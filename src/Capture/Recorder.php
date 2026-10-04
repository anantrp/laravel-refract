<?php

namespace Anantrp\Refract\Capture;

use Anantrp\Refract\Contracts\Transport;
use OpenTelemetry\API\Trace\Span;

/**
 * Builds neutral spans and holds them until a flush point.
 *
 * Times are read from the monotonic clock while recording and turned into
 * wall clock times at each flush.
 *
 * @phpstan-type SpanEvent array{kind: string, time: int, call: array<string, mixed>}
 * @phpstan-type OpenSpan array{trace_id: string, span_id: string, parent_span_id: ?string, kind: string, start: int, call: array<string, mixed>, context: array<string, mixed>, context_from: ?string, events: list<SpanEvent>}
 * @phpstan-type FinishedSpan array{trace_id: string, span_id: string, parent_span_id: ?string, kind: string, start: int, end: int, status: string, status_message: ?string, call: array<string, mixed>, context: array<string, mixed>, context_from: ?string, events: list<SpanEvent>}
 */
class Recorder
{
    /**
     * The version of the neutral span format.
     */
    public const VERSION = 1;

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
     * Create a new recorder instance.
     */
    public function __construct(protected Transport $transport) {}

    /**
     * Start a span under the given parent, or under the app's active trace.
     *
     * A span still open under the same key is closed as abandoned first.
     * A span started with a context source takes that span's context at
     * flush, in place of its own.
     *
     * @param  array<string, mixed>  $call
     * @param  array<string, mixed>  $context
     * @param  string|null  $contextFrom  The key of the open span whose context this span takes.
     */
    public function start(string $key, string $kind, ?string $parentKey, array $call, array $context = [], ?string $contextFrom = null): void
    {
        if (isset($this->open[$key])) {
            $this->abandon($key);
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
     * Check whether a span is open under the given key.
     */
    public function isOpen(string $key): bool
    {
        return isset($this->open[$key]);
    }

    /**
     * End the given span, merging in the call and context data known only at its end.
     *
     * @param  array<string, mixed>  $call
     * @param  array<string, mixed>  $context
     */
    public function end(string $key, string $status = 'ok', ?string $message = null, array $call = [], array $context = []): void
    {
        $span = $this->open[$key] ?? null;

        if ($span === null) {
            return;
        }

        unset($this->open[$key]);

        $this->finished[] = [
            ...$span,
            'call' => [...$span['call'], ...$call],
            'context' => [...$span['context'], ...$context],
            'end' => $this->monotonic(),
            'status' => $status,
            'status_message' => $message,
        ];

        $this->ended[$key] = array_key_last($this->finished);
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
        $span = $this->open[$key] ?? null;

        if ($span === null) {
            return;
        }

        $ids = [$span['span_id'] => true];
        $keys = [$key];

        do {
            $found = false;

            foreach ($this->open as $openKey => $open) {
                if (! in_array($openKey, $keys, true) && isset($ids[$open['parent_span_id'] ?? ''])) {
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
     * Close every open span as abandoned, then hand the finished spans to the transport as one batch.
     */
    public function flush(): void
    {
        foreach (array_keys($this->open) as $key) {
            $this->end($key, 'abandoned');
        }

        $this->ended = [];

        if ($this->finished === []) {
            return;
        }

        $finished = $this->inheritContext($this->finished);
        $this->finished = [];

        $offset = (int) round(microtime(true) * 1_000_000) * 1_000 - $this->monotonic();

        $this->transport->send(array_map(fn (array $span) => [
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
            'content' => [],
            'context' => $span['context'],
            'events' => array_map(fn (array $event) => [
                'kind' => $event['kind'],
                'time' => $event['time'] + $offset,
                'call' => $event['call'],
            ], $span['events']),
        ], $finished));
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
