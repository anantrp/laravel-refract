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
 * @phpstan-type OpenSpan array{trace_id: string, span_id: string, parent_span_id: ?string, kind: string, start: int, call: array<string, mixed>}
 * @phpstan-type FinishedSpan array{trace_id: string, span_id: string, parent_span_id: ?string, kind: string, start: int, end: int, status: string, status_message: ?string, call: array<string, mixed>}
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
     * Create a new recorder instance.
     */
    public function __construct(protected Transport $transport) {}

    /**
     * Start a span under the given parent, or under the app's active trace.
     *
     * @param  array<string, mixed>  $call
     */
    public function start(string $key, string $kind, ?string $parentKey, array $call): void
    {
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
        ];
    }

    /**
     * End the given span, merging in the call data known only at its end.
     *
     * @param  array<string, mixed>  $call
     */
    public function end(string $key, string $status = 'ok', ?string $message = null, array $call = []): void
    {
        $span = $this->open[$key] ?? null;

        if ($span === null) {
            return;
        }

        unset($this->open[$key]);

        $this->finished[] = [
            ...$span,
            'call' => [...$span['call'], ...$call],
            'end' => $this->monotonic(),
            'status' => $status,
            'status_message' => $message,
        ];
    }

    /**
     * Hand the finished spans to the transport as one batch.
     */
    public function flush(): void
    {
        if ($this->finished === []) {
            return;
        }

        $finished = $this->finished;
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
            'context' => [],
            'events' => [],
        ], $finished));
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
