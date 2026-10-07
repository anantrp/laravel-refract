<?php

namespace Anantrp\Refract\Capture;

use Anantrp\Refract\Contracts\SendNow;
use Anantrp\Refract\Contracts\Transport;
use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Support\Guard;
use Closure;
use Illuminate\Contracts\Container\Container;
use OpenTelemetry\API\Trace\Span;

/**
 * Builds neutral spans and holds them until a flush point.
 *
 * Times are read from the monotonic clock while recording and turned into
 * wall clock times at each flush.
 *
 * Spans a send gave back while the process runs (the destination could
 * not take them now) are kept as neutral spans, with the wall clock times
 * of that send, and go first in the next send. They count toward the cap.
 *
 * @phpstan-type SpanEvent array{kind: string, time: int, call: array<string, mixed>}
 * @phpstan-type OpenSpan array{trace_id: string, span_id: string, parent_span_id: ?string, kind: string, start: int, call: array<string, mixed>, content: array<string, mixed>, context: array<string, mixed>, context_from: ?string, events: list<SpanEvent>}
 * @phpstan-type FinishedSpan array{trace_id: string, span_id: string, parent_span_id: ?string, kind: string, start: int, end: int, status: string, status_message: ?string, call: array<string, mixed>, content: array<string, mixed>, context: array<string, mixed>, context_from: ?string, events: list<SpanEvent>}
 */
class Recorder
{
    /**
     * The version of the neutral span format.
     */
    public const VERSION = 1;

    /**
     * The most spans the buffer holds between two flushes.
     */
    public const MAX_SPANS = 1_000;

    /**
     * The finished spans that start a partial flush.
     */
    public const PARTIAL_SPANS = 500;

    /**
     * The time since the last send, in nanoseconds, that starts a partial flush.
     */
    public const PARTIAL_INTERVAL = 5_000_000_000;

    /**
     * The least seconds to wait after a send gave spans back, before the next send while the process runs.
     */
    public const KEPT_WAIT = 5;

    /**
     * The most seconds to wait after a send gave spans back, whatever the destination asked.
     */
    public const KEPT_MAX_WAIT = 300;

    /**
     * The spans started but not ended yet, keyed by their capture key.
     *
     * @var array<string, OpenSpan>
     */
    protected array $open = [];

    /**
     * The spans ended since the last flush.
     *
     * @var list<FinishedSpan>
     */
    protected array $finished = [];

    /**
     * The index in the finished spans of each span ended since the last flush, keyed by its capture key.
     *
     * @var array<string, int>
     */
    protected array $ended = [];

    /**
     * The neutral spans a send gave back, to go first in the next send.
     *
     * @var list<array<string, mixed>>
     */
    protected array $kept = [];

    /**
     * The monotonic time before which no send is made while the process runs, after a send gave spans back.
     */
    protected ?int $waitUntil = null;

    /**
     * The callbacks that reset other capture state at each flush.
     *
     * @var list<Closure(): mixed>
     */
    protected array $resets = [];

    /**
     * The callbacks run when a top-level run ends, given the run's capture key.
     *
     * @var list<Closure(string): mixed>
     */
    protected array $runEnds = [];

    /**
     * The callbacks run when the buffer is full, before a new span is dropped.
     *
     * @var list<Closure(): mixed>
     */
    protected array $fulls = [];

    /**
     * Whether a span tree may have finished since the full callbacks last ran.
     *
     * Set when a span ends whose parent is not open, and at each flush. A
     * tree can finish only then, so a full buffer does not run the callbacks
     * again on every dropped span.
     */
    protected bool $treeEnded = true;

    /**
     * The monotonic time of the last send, or of the recorder's start before the first one.
     */
    protected int $lastSend;

    /**
     * Create a new recorder instance. The transport is made from the container on the first send, not before.
     */
    public function __construct(protected Container $container)
    {
        $this->lastSend = $this->monotonic();
    }

    /**
     * Start a span under the given parent, or under the app's active trace.
     *
     * A span still open under the same key is closed as abandoned first.
     * A span started with a context source takes that span's context at
     * flush, in place of its own. A full buffer drops the span.
     *
     * @param  array<string, mixed>  $call
     * @param  array<string, mixed>  $context
     * @param  string|null  $contextFrom  The key of the open span whose context this span takes.
     * @param  array<string, mixed>  $content
     */
    public function start(string $key, string $kind, ?string $parentKey, array $call, array $context = [], ?string $contextFrom = null, array $content = []): void
    {
        if (isset($this->open[$key])) {
            $this->abandon($key);
        }

        if ($this->treeEnded && $this->full()) {
            $this->treeEnded = false;

            foreach ($this->fulls as $callback) {
                $callback();
            }
        }

        if ($this->full()) {
            Diagnostics::warn('buffer.full', 'The buffer holds '.self::MAX_SPANS.' spans, the most it can between two flushes. New spans are dropped until the next flush.');

            return;
        }

        $parent = $parentKey === null ? null : ($this->open[$parentKey] ?? null);

        if ($parent !== null) {
            $traceId = $parent['trace_id'];
            $parentSpanId = $parent['span_id'];
        } else {
            [$traceId, $parentSpanId] = $this->activeTrace();
        }

        $this->open[$key] = [
            'trace_id' => $traceId,
            'span_id' => bin2hex(random_bytes(8)),
            'parent_span_id' => $parentSpanId,
            'kind' => $kind,
            'start' => $this->monotonic(),
            'call' => $call,
            'content' => $content,
            'context' => $context,
            'context_from' => $contextFrom === null ? null : ($this->open[$contextFrom]['span_id'] ?? null),
            'events' => [],
        ];
    }

    /**
     * Get the key of the open span that the given open span is under.
     */
    public function parentKey(string $key): ?string
    {
        $parent = $this->open[$key]['parent_span_id'] ?? null;

        if ($parent === null) {
            return null;
        }

        foreach ($this->open as $openKey => $open) {
            if ($open['span_id'] === $parent) {
                return $openKey;
            }
        }

        return null;
    }

    /**
     * Get the call bucket of the span open under the given key, or none when no span is open there.
     *
     * @return array<string, mixed>
     */
    public function call(string $key): array
    {
        return $this->open[$key]['call'] ?? [];
    }

    /**
     * Check whether a span is open under the given key.
     */
    public function isOpen(string $key): bool
    {
        return isset($this->open[$key]);
    }

    /**
     * Merge the given call data into an open span.
     *
     * @param  array<string, mixed>  $call
     */
    public function update(string $key, array $call): void
    {
        if (isset($this->open[$key])) {
            $this->open[$key]['call'] = [...$this->open[$key]['call'], ...$call];
        }
    }

    /**
     * End the given span, merging in the call, context and content data known only at its end.
     *
     * @param  array<string, mixed>  $call
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $content
     */
    public function end(string $key, string $status = 'ok', ?string $message = null, array $call = [], array $context = [], array $content = []): void
    {
        $span = $this->open[$key] ?? null;

        if ($span === null) {
            return;
        }

        unset($this->open[$key]);

        $this->finished[] = [
            ...$span,
            'call' => [...$span['call'], ...$call],
            'content' => [...$span['content'], ...$content],
            'context' => [...$span['context'], ...$context],
            'end' => $this->monotonic(),
            'status' => $status,
            'status_message' => $message,
        ];

        $this->ended[$key] = array_key_last($this->finished);

        if (! $this->treeEnded && ! $this->parentOpen($span)) {
            $this->treeEnded = true;
        }

        if ($span['kind'] === 'invoke_agent' && $status !== 'abandoned' && ! $this->hasParent($span)) {
            foreach ($this->runEnds as $callback) {
                $callback($key);
            }
        }
    }

    /**
     * Check whether the given span's parent is an open span.
     *
     * @param  OpenSpan  $span
     */
    protected function parentOpen(array $span): bool
    {
        foreach ($this->open as $open) {
            if ($open['span_id'] === $span['parent_span_id']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check whether the given span's parent is a span in the buffer, open or finished.
     *
     * @param  OpenSpan|FinishedSpan  $span
     */
    protected function hasParent(array $span): bool
    {
        $parent = $span['parent_span_id'];

        if ($parent === null) {
            return false;
        }

        foreach ([...array_values($this->open), ...$this->finished] as $other) {
            if ($other['span_id'] === $parent) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add an event to the given span.
     *
     * A span that already ended keeps the event at its end, so the event
     * stays inside the span's time.
     *
     * @param  array<string, mixed>  $call
     */
    public function event(string $key, string $kind, array $call = []): void
    {
        $time = $this->monotonic();

        if (isset($this->open[$key])) {
            $this->open[$key]['events'][] = ['kind' => $kind, 'time' => $time, 'call' => $call];

            return;
        }

        $index = $this->ended[$key] ?? null;

        if ($index === null) {
            return;
        }

        $span = $this->finished[$index];
        $span['events'][] = ['kind' => $kind, 'time' => min($time, $span['end']), 'call' => $call];
        $this->finished[$index] = $span;
    }

    /**
     * Close the given open span and every open span under it as abandoned.
     */
    public function abandon(string $key): void
    {
        if (! isset($this->open[$key])) {
            return;
        }

        $this->abandonChildren($key);
        $this->end($key, 'abandoned');
    }

    /**
     * Close every open span under the given open span as abandoned, the deepest first.
     */
    public function abandonChildren(string $key): void
    {
        $span = $this->open[$key] ?? null;

        if ($span === null) {
            return;
        }

        $ids = [$span['span_id'] => true];
        $keys = [];

        do {
            $found = false;

            foreach ($this->open as $openKey => $open) {
                if ($openKey !== $key && ! in_array($openKey, $keys, true) && isset($ids[$open['parent_span_id'] ?? ''])) {
                    $ids[$open['span_id']] = true;
                    $keys[] = $openKey;
                    $found = true;
                }
            }
        } while ($found);

        foreach (array_reverse($keys) as $openKey) {
            $this->end($openKey, 'abandoned');
        }
    }

    /**
     * Run the given callback at each flush, to reset capture state kept between flushes.
     *
     * @param  Closure(): mixed  $callback
     */
    public function flushing(Closure $callback): void
    {
        $this->resets[] = $callback;
    }

    /**
     * Run the given callback when a top-level run ends, with the run's capture key.
     *
     * A run closed as abandoned does not count.
     *
     * @param  Closure(string): mixed  $callback
     */
    public function whenRunEnds(Closure $callback): void
    {
        $this->runEnds[] = $callback;
    }

    /**
     * Run the given callback when the buffer is full, before a new span is dropped.
     *
     * @param  Closure(): mixed  $callback
     */
    public function whenFull(Closure $callback): void
    {
        $this->fulls[] = $callback;
    }

    /**
     * Determine if the buffer holds the most spans it can.
     */
    protected function full(): bool
    {
        return count($this->open) + count($this->finished) + count($this->kept) >= self::MAX_SPANS;
    }

    /**
     * Hand every finished span tree to the transport now, the held run's too, leaving every open span and the state kept between flushes alone.
     *
     * Used when the buffer is full: by then the SDK has added its events to
     * the run that ended last, since a new span is starting. Nothing is
     * sent while waiting after a send that gave spans back.
     */
    public function flushAllFinished(): void
    {
        if ($this->waiting()) {
            return;
        }

        $this->send($this->sendablePositions(null));
    }

    /**
     * Hand the finished span trees to the transport, leaving every open span and the state kept between flushes alone.
     *
     * A span tree is a top-level span and every span under it, sub-agent
     * runs included. It is finished when none of its spans is open. The
     * flush runs only when the finished trees hold PARTIAL_SPANS spans, or
     * PARTIAL_INTERVAL passed since the last send. The tree of the given
     * span is held for the next send, as the SDK adds events to a run right
     * after it ends. Nothing is sent while waiting after a send that gave
     * spans back.
     */
    public function flushFinished(string $heldKey): void
    {
        if ($this->waiting()) {
            return;
        }

        $due = $this->monotonic() - $this->lastSend >= self::PARTIAL_INTERVAL;

        if (! $due && count($this->finished) < self::PARTIAL_SPANS) {
            return;
        }

        $positions = $this->sendablePositions($heldKey);

        if (! $due && count($positions) < self::PARTIAL_SPANS) {
            return;
        }

        $this->send($positions);
    }

    /**
     * Determine if no send is made yet while the process runs, after a send gave spans back.
     */
    protected function waiting(): bool
    {
        return $this->waitUntil !== null && $this->monotonic() < $this->waitUntil;
    }

    /**
     * Hand the kept spans and the finished spans at the given positions to the transport and forget them.
     *
     * A transport that sends now may give spans back: they are kept for
     * the next send, which waits KEPT_WAIT seconds, or the seconds the
     * destination asked for, at most KEPT_MAX_WAIT.
     *
     * @param  list<int>  $positions
     */
    protected function send(array $positions): void
    {
        if ($positions === [] && $this->kept === []) {
            return;
        }

        try {
            $batch = [...$this->kept, ...$this->batch(array_values(array_intersect_key($this->finished, array_flip($positions))))];
        } finally {
            $this->kept = [];
            $this->forget($positions);
            $this->lastSend = $this->monotonic();
        }

        if ($batch === []) {
            return;
        }

        /** @var Transport $transport Any transport the app binds, not only the ones Refract ships. */
        $transport = $this->container->make(Transport::class);

        if (! $transport instanceof SendNow) {
            $transport->send($batch);

            return;
        }

        $this->kept = $transport->sendNow($batch);

        if ($this->kept !== []) {
            $wait = min(self::KEPT_MAX_WAIT, max(self::KEPT_WAIT, $transport->retryAfter() ?? 0));
            $this->waitUntil = $this->monotonic() + $wait * 1_000_000_000;
        }
    }

    /**
     * Get the positions in the finished spans of every finished span tree, except the tree of the given span.
     *
     * @return list<int>
     */
    protected function sendablePositions(?string $heldKey): array
    {
        $parents = [];

        foreach ([...array_values($this->open), ...$this->finished] as $span) {
            $parents[$span['span_id']] = $span['parent_span_id'];
        }

        $roots = [];

        $root = function (string $id) use ($parents, &$roots): string {
            $chain = [];

            // Bounded by the span count, so a cycle cannot loop forever.
            while (! isset($roots[$id]) && isset($parents[$id]) && array_key_exists($parents[$id], $parents) && count($chain) <= count($parents)) {
                $chain[] = $id;
                $id = $parents[$id];
            }

            $top = $roots[$id] ?? $id;

            foreach ([$id, ...$chain] as $link) {
                $roots[$link] = $top;
            }

            return $top;
        };

        $held = [];

        foreach ($this->open as $span) {
            $held[$root($span['span_id'])] = true;
        }

        $index = $heldKey === null ? null : ($this->ended[$heldKey] ?? null);

        if ($index !== null) {
            $held[$root($this->finished[$index]['span_id'])] = true;
        }

        $positions = [];

        foreach ($this->finished as $position => $span) {
            if (! isset($held[$root($span['span_id'])])) {
                $positions[] = $position;
            }
        }

        return $positions;
    }

    /**
     * Remove the finished spans at the given positions, keeping the index of the others.
     *
     * @param  list<int>  $positions
     */
    protected function forget(array $positions): void
    {
        $kept = array_diff_key($this->finished, array_flip($positions));
        $moved = array_flip(array_keys($kept));

        $this->finished = array_values($kept);

        $ended = [];

        foreach ($this->ended as $key => $position) {
            if (isset($moved[$position])) {
                $ended[$key] = $moved[$position];
            }
        }

        $this->ended = $ended;
    }

    /**
     * Close every open span as abandoned, then hand the kept spans and the finished spans to the transport as one batch.
     *
     * The buffer and the state kept between flushes are cleared even when
     * building the batch fails or a reset throws.
     */
    public function flush(): void
    {
        try {
            foreach (array_keys($this->open) as $key) {
                $this->end($key, 'abandoned');
            }

            $batch = [...$this->kept, ...$this->batch($this->finished)];
        } finally {
            $this->kept = [];
            $this->open = [];
            $this->finished = [];
            $this->ended = [];
            $this->treeEnded = true;

            foreach ($this->resets as $reset) {
                Guard::run('capture.reset', 'to reset its state at a flush', $reset);
            }
        }

        if ($batch !== []) {
            $this->lastSend = $this->monotonic();
            $this->container->make(Transport::class)->send($batch);
        }
    }

    /**
     * Get the given finished spans as neutral spans, with wall clock times.
     *
     * @param  list<FinishedSpan>  $spans
     * @return list<array<string, mixed>>
     */
    protected function batch(array $spans): array
    {
        if ($spans === []) {
            return [];
        }

        $offset = $this->wall() - $this->monotonic();

        return array_map(fn (array $span) => [
            'v' => self::VERSION,
            'trace_id' => $span['trace_id'],
            'span_id' => $span['span_id'],
            'parent_span_id' => $span['parent_span_id'],
            'kind' => $span['kind'],
            'start' => $span['start'] + $offset,
            'end' => $span['end'] + $offset,
            'status' => $span['status'],
            'status_message' => $span['status_message'],
            'call' => $span['call'],
            'content' => $span['content'],
            'context' => $span['context'],
            'events' => array_map(fn (array $event) => [
                'kind' => $event['kind'],
                'time' => $event['time'] + $offset,
                'call' => $event['call'],
            ], $span['events']),
        ], $this->inheritContext($spans));
    }

    /**
     * Give each span with a context source the context of the first span up its chain that has none.
     *
     * A span whose chain leaves the batch keeps its own context.
     *
     * @param  list<FinishedSpan>  $spans
     * @return list<FinishedSpan>
     */
    protected function inheritContext(array $spans): array
    {
        $index = [];

        foreach ($spans as $position => $span) {
            $index[$span['span_id']] = $position;
        }

        foreach ($spans as $position => $span) {
            $from = $span['context_from'];
            $source = null;
            $hops = 0;

            // Bounded by the span count, so a cycle cannot loop forever.
            while ($from !== null && isset($index[$from]) && $hops++ < count($spans)) {
                $source = $spans[$index[$from]];
                $from = $source['context_from'];
            }

            if ($source !== null && $from === null) {
                $spans[$position]['context'] = $source['context'];
            }
        }

        return $spans;
    }

    /**
     * Get the wall clock in nanoseconds since the Unix epoch, read at each flush.
     */
    protected function wall(): int
    {
        return (int) round(microtime(true) * 1_000_000) * 1_000;
    }

    /**
     * Get the monotonic clock in nanoseconds.
     */
    protected function monotonic(): int
    {
        $time = hrtime(true);

        // hrtime() gives a float only on 32-bit builds.
        return is_int($time) ? $time : (int) $time;
    }

    /**
     * Get the trace id and parent span id from the app's active OTel span, or a new trace id.
     *
     * @return array{string, ?string}
     */
    protected function activeTrace(): array
    {
        $context = Span::getCurrent()->getContext();

        if ($context->isValid()) {
            return [$context->getTraceId(), $context->getSpanId()];
        }

        return [bin2hex(random_bytes(16)), null];
    }
}
