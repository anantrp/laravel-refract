<?php

namespace Anantrp\Refract\Transport;

use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Contracts\ExportResult;
use Anantrp\Refract\Contracts\RetryAfter;
use Anantrp\Refract\Support\Diagnostics;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Exports one batch of neutral spans from a queue worker.
 *
 * The batch travels as gzipped JSON, base64 encoded: the queue payload is
 * JSON, so invalid UTF-8 becomes U+FFFD and floats keep their fraction.
 *
 * A retryable result (network error, 408, 429, 5xx) is tried again, 4
 * tries in all: after the backoff of the attempt (10 s, 60 s, then 300 s),
 * or after the Retry-After seconds the exporter read from a 429 or 503 when
 * they are longer (the exporter caps them at 300 s). The job
 * never throws for it: after the last try it deletes itself and warns once
 * from failed(), so it is not reported to the exception handler, not
 * stored as a failed job and fires no JobFailed event. Error trackers never see it. Nothing else in the job
 * throws either: a failure to decode or export drops the batch with one
 * warning.
 */
class ExportSpans implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Never wait for the app's database transactions: a queue connection
     * with after_commit would hold the job until they commit, and drop it
     * on a rollback. Laravel reads this before the connection's setting.
     */
    public bool $afterCommit = false;

    /**
     * The number of times the batch is tried.
     */
    public int $tries = 4;

    /**
     * The seconds to wait before each next try, by attempt.
     *
     * @var list<int>
     */
    public array $backoff = [10, 60, 300];

    /**
     * The flags the batch is encoded with.
     */
    protected const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION | JSON_PARTIAL_OUTPUT_ON_ERROR;

    /**
     * Create a new job instance for the given encoded batch.
     */
    public function __construct(public string $batch) {}

    /**
     * Create a job for the given neutral spans, or get null when they cannot be encoded.
     *
     * @param  list<array<string, mixed>>  $spans
     */
    public static function of(array $spans): ?self
    {
        $json = json_encode($spans, self::JSON_FLAGS);
        $gzipped = $json === false ? false : gzencode($json);

        return $gzipped === false ? null : new self(base64_encode($gzipped));
    }

    /**
     * Get the neutral spans of the batch, or null when it cannot be decoded.
     *
     * @return list<array<string, mixed>>|null
     */
    public function spans(): ?array
    {
        $gzipped = base64_decode($this->batch, true);
        $json = $gzipped === false ? false : gzdecode($gzipped);
        $spans = $json === false ? null : json_decode($json, true);

        if (! is_array($spans) || ! array_is_list($spans)) {
            return null;
        }

        $batch = [];

        foreach ($spans as $span) {
            if (is_array($span)) {
                $batch[] = $this->fields($span);
            }
        }

        return $batch;
    }

    /**
     * Get the named fields of a decoded neutral span.
     *
     * @param  array<mixed>  $span
     * @return array<string, mixed>
     */
    protected function fields(array $span): array
    {
        $fields = [];

        foreach ($span as $name => $value) {
            if (is_string($name)) {
                $fields[$name] = $value;
            }
        }

        return $fields;
    }

    /**
     * Export the batch, and try again later when the destination could not take it now.
     */
    public function handle(Container $container): void
    {
        try {
            $this->export($container);
        } catch (Throwable $e) {
            Diagnostics::warn('export.error', 'Spans could not be exported ('.$e::class.'). The batch was dropped.');
        }
    }

    /**
     * Export the batch with the exporter made from the container, so that a failure to make it is guarded too.
     */
    protected function export(Container $container): void
    {
        $spans = $this->spans();

        if ($spans === null) {
            Diagnostics::warn('queue.decode', 'A queued batch of spans could not be decoded and was dropped.');

            return;
        }

        $exporter = $container->make(Exporter::class);

        if ($exporter->export($spans) !== ExportResult::Retryable) {
            return;
        }

        if ($this->job === null) {
            Diagnostics::warn('queue.no_job', 'A batch of spans could not be exported and cannot be tried again: its export job did not run on a queue. The batch was dropped.');

            return;
        }

        if ($this->attempts() < $this->tries) {
            $asked = $exporter instanceof RetryAfter ? $exporter->retryAfter() : null;

            // Retry-After means "not before": a shorter one never cuts the backoff, so a batch survives an outage of a few minutes.
            $this->release(max($asked ?? 0, $this->backoff[min($this->attempts(), count($this->backoff)) - 1]));

            return;
        }

        $this->delete();
        $this->failed();
    }

    /**
     * Warn that the batch was given up on. Also called by the queue when the job fails another way
     * (a timeout, or more attempts than its tries), with that exception.
     */
    public function failed(?Throwable $e = null): void
    {
        Diagnostics::warn('queue.failed', $e === null
            ? "A queued batch of spans could not be exported in {$this->tries} tries: the destination could not be reached or answered 408, 429 or 5xx. The batch was dropped."
            : 'The export job of a queued batch of spans failed ('.$e::class."), within its {$this->tries} tries. The batch was dropped.");
    }
}
