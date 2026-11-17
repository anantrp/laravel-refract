<?php

namespace Anantrp\Refract\Capture;

use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Support\Guard;
use Anantrp\Refract\Transport\Drain;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use OpenTelemetry\API\Trace\Span;

/**
 * Builds neutral spans and hands each finished run tree on.
 *
 * A run tree is a top-level span and every span under it, sub-agent runs
 * included. Each span records the span id of its tree's top-level span
 * when it starts, so the tree is finished when that span ends. A console
 * process hands the tree on then, unless a database transaction is open:
 * then the tree waits until none is. A web request holds it until the
 * flush point, after the response is sent.
 *
 * Times are read from the monotonic clock while recording and turned into
 * wall clock times when a tree is handed on.
 *
 * @phpstan-type SpanEvent array{kind: string, time: int, call: array<string, mixed>}
 * @phpstan-type OpenSpan array{trace_id: string, span_id: string, parent_span_id: ?string, root: string, kind: string, start: int, call: array<string, mixed>, content: array<string, mixed>, context: array<string, mixed>, context_from: ?string, events: list<SpanEvent>}
 * @phpstan-type FinishedSpan array{trace_id: string, span_id: string, parent_span_id: ?string, root: string, kind: string, start: int, end: int, status: string, status_message: ?string, call: array<string, mixed>, content: array<string, mixed>, context: array<string, mixed>, context_from: ?string, events: list<SpanEvent>}
 */
class Recorder
{
    /**
     * The version of the neutral span format.
     */
    public const VERSION = 1;

    /**
     * The most spans one run tree records: its top-level span and every span under it.
     */
    public const MAX_RUN_SPANS = 1_000;

    /**
     * The spans started but not ended yet, keyed by their capture key.
     *
     * @var array<string, OpenSpan>
     */
    protected array $open = [];

    /**
     * The ended spans of the trees not handed on yet.
     *
     * @var list<FinishedSpan>
     */
    protected array $finished = [];

    /**
     * The keys of the spans dropped at the per-run cap and not ended yet, so their children are dropped too, with their run's top-level span id.
     *
     * @var array<string, string>
     */
    protected array $dropped = [];

    /**
     * The spans recorded in each run tree still open, keyed by the span id of its top-level span.
     *
     * @var array<string, int>
     */
    protected array $sizes = [];

    /**
     * The span ids of the top-level spans whose trees reached the per-run cap, marked truncated when they end, with the spans they dropped.
     *
     * @var array<string, int>
     */
    protected array $truncated = [];

    /**
     * The span ids of the top-level spans whose trees ended in a console process and wait to be handed on.
     *
     * @var array<string, true>
     */
    protected array $waiting = [];

    /**
     * The callbacks that reset other capture state at each flush.
     *
     * @var list<Closure(): mixed>
     */
    protected array $resets = [];

    /**
     * Whether a flush is closing the open spans, so the trees they finish leave together with the rest.
     */
    protected bool $flushing = false;

    /**
     * Create a new recorder instance. The drain is made from the container on the first send, not before.
     */
    public function __construct(protected Container $container) {}

    /**
     * Start a span under the given parent, or under the app's active trace.
     *
     * A span still open under the same key is closed as abandoned first.
     * A span started with a context source takes that span's context when
     * its tree is handed on, in place of its own. A run tree that holds
     * MAX_RUN_SPANS spans drops the span and is marked truncated, so one huge
     * run never crowds out the others. A span whose parent was dropped is
     * dropped too, so it never becomes a new trace without its run. A parent
     * that is not open for any other reason falls back to the active trace.
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

        if ($parentKey !== null && isset($this->dropped[$parentKey])) {
            $root = $this->dropped[$parentKey];
            $this->dropped[$key] = $root;

            if (isset($this->truncated[$root])) {
                $this->truncated[$root]++;
            }

            return;
        }

        $parent = $parentKey === null ? null : ($this->open[$parentKey] ?? null);

        if ($parent !== null && ($this->sizes[$parent['root']] ?? 0) >= self::MAX_RUN_SPANS) {
            $this->truncated[$parent['root']] = ($this->truncated[$parent['root']] ?? 0) + 1;
            $this->dropped[$key] = $parent['root'];

            return;
        }

        unset($this->dropped[$key]);

        $spanId = bin2hex(random_bytes(8));

        if ($parent !== null) {
            $traceId = $parent['trace_id'];
            $parentSpanId = $parent['span_id'];
            $root = $parent['root'];
        } else {
            [$traceId, $parentSpanId] = $this->activeTrace();
            $root = $spanId;
        }

        $this->sizes[$root] = ($this->sizes[$root] ?? 0) + 1;

        $this->open[$key] = [
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'parent_span_id' => $parentSpanId,
            'root' => $root,
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
     * Check whether the span under the given key was dropped at the cap and has not ended.
     */
    public function isDropped(string $key): bool
    {
        return isset($this->dropped[$key]);
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
     * A top-level span whose tree reached the per-run cap is marked truncated.
     * A top-level span ending finishes its tree.
     *
     * @param  array<string, mixed>  $call
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $content
     */
    public function end(string $key, string $status = 'ok', ?string $message = null, array $call = [], array $context = [], array $content = []): void
    {
        $span = $this->open[$key] ?? null;

        if ($span === null) {
            unset($this->dropped[$key]);

            return;
        }

        unset($this->open[$key]);

        if ($span['root'] === $span['span_id']) {
            $dropped = $this->truncated[$span['root']] ?? 0;
            $call = [...$call, ...($dropped > 0 ? ['truncated' => true] : [])];

            unset($this->sizes[$span['root']], $this->truncated[$span['root']]);

            Diagnostics::dropped('run_cap', $dropped, 'run.truncated', 'A run reached '.self::MAX_RUN_SPANS." spans, the most one run records. Its {$dropped} later spans were dropped and the run is marked laravel.ai.truncated.");
        }

        $this->finished[] = [
            ...$span,
            'call' => [...$span['call'], ...$call],
            'content' => [...$span['content'], ...$content],
            'context' => [...$span['context'], ...$context],
            'end' => $this->monotonic(),
            'status' => $status,
            'status_message' => $message,
        ];

        if ($span['root'] === $span['span_id']) {
            $this->treeEnded($span['root']);
        }
    }

    /**
     * Add an event to the given open span. A span that is not open takes no events.
     *
     * @param  array<string, mixed>  $call
     */
    public function event(string $key, string $kind, array $call = []): void
    {
        if (isset($this->open[$key])) {
            $this->open[$key]['events'][] = ['kind' => $kind, 'time' => $this->monotonic(), 'call' => $call];
        }
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

        // A span starts after its parent (a re-started key goes in again at
        // the end), so one pass in start order finds every span under it.
        foreach ($this->open as $open) {
            if (isset($ids[$open['parent_span_id'] ?? ''])) {
                $ids[$open['span_id']] = true;
            }
        }

        unset($ids[$span['span_id']]);

        // Latest start first, as in flush(): children close first.
        foreach (array_reverse(array_keys($this->open)) as $openKey) {
            if (isset($ids[$this->open[$openKey]['span_id']])) {
                $this->end($openKey, 'abandoned');
            }
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
     * Hand the tree with the given top-level span id on, in a console process.
     *
     * A console process (command, queue worker, tinker) may run for long, so
     * each run tree leaves when it ends. A web request keeps its trees until
     * it terminates, so nothing touches the network before the response.
     */
    protected function treeEnded(string $root): void
    {
        if ($this->flushing || ! $this->runningInConsole()) {
            return;
        }

        $this->waiting[$root] = true;

        $this->sendWaiting();
    }

    /**
     * Hand on the trees waiting in a console process, unless a database transaction is open.
     *
     * A tree handed on inside the app's transaction would hold it open for
     * an export, or be written into it and rolled back with it. So a tree
     * that ends inside one waits, and leaves when the transaction ends, with
     * the next run that ends outside one, or at the flush point.
     */
    public function sendWaiting(): void
    {
        if ($this->waiting === [] || $this->inTransaction()) {
            return;
        }

        $trees = [];
        $rest = [];

        foreach ($this->finished as $span) {
            if (isset($this->waiting[$span['root']])) {
                $trees[$span['root']][] = $span;
            } else {
                $rest[] = $span;
            }
        }

        $this->finished = $rest;
        $this->waiting = [];

        $this->send(array_values($trees));
    }

    /**
     * Determine if a database transaction is open on a connection this process made. No connection is made to find out.
     */
    protected function inTransaction(): bool
    {
        if (! $this->container->resolved('db')) {
            return false;
        }

        /** @var DatabaseManager $database */
        $database = $this->container->make('db');

        foreach ($database->getConnections() as $connection) {
            if ($connection->transactionLevel() > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Close every open span as abandoned, children before their parent, then hand every finished tree on.
     *
     * The trees that wait for a transaction to end leave too, even if it is
     * still open: the flush point is the last chance to hand them on.
     *
     * The buffer and the state kept between flushes are cleared even when
     * building the trees fails or a reset throws.
     */
    public function flush(): void
    {
        $this->flushing = true;
        $trees = [];

        try {
            // Latest start first: a span starts after its parent, so children close first.
            foreach (array_reverse(array_keys($this->open)) as $key) {
                $this->end($key, 'abandoned');
            }

            foreach ($this->finished as $span) {
                $trees[$span['root']][] = $span;
            }
        } finally {
            $this->open = [];
            $this->finished = [];
            $this->waiting = [];
            $this->dropped = [];
            $this->sizes = [];
            $this->truncated = [];
            $this->flushing = false;

            foreach ($this->resets as $reset) {
                Guard::run('capture.reset', 'to reset its state at a flush', $reset);
            }
        }

        $this->send(array_values($trees));
    }

    /**
     * Hand the given finished trees on, as neutral spans with wall clock times.
     *
     * @param  list<list<FinishedSpan>>  $trees
     */
    protected function send(array $trees): void
    {
        if ($trees === []) {
            return;
        }

        $this->container->make(Drain::class)->send(array_map($this->batch(...), $trees));
    }

    /**
     * Determine if the process runs in the console, where each run tree leaves when it ends.
     */
    protected function runningInConsole(): bool
    {
        return $this->container instanceof Application && $this->container->runningInConsole();
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
     * Get the wall clock in nanoseconds since the Unix epoch, read each time trees are handed on.
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
