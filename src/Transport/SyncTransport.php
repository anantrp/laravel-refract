<?php

namespace Anantrp\Refract\Transport;

use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Contracts\ExportResult;
use Anantrp\Refract\Contracts\Parts;
use Anantrp\Refract\Contracts\Reachability;
use Anantrp\Refract\Contracts\RetryAfter;
use Anantrp\Refract\Contracts\SendNow;
use Anantrp\Refract\Contracts\Transport;
use Anantrp\Refract\Support\Diagnostics;
use Throwable;

/**
 * Exports each batch in the current process, at the flush point.
 *
 * At the flush point a failed batch is dropped with one warning. A send
 * while the process keeps going gives a failed part back to be tried again
 * later, and once a part cannot connect, the later parts are given back
 * without a try.
 */
class SyncTransport implements SendNow, Transport
{
    /**
     * The longest wait in seconds the destination asked for across the parts given back in this send, or null.
     */
    protected ?int $retryAfter = null;

    /**
     * Whether a part of this send could not reach the destination.
     */
    protected bool $unreachable = false;

    /**
     * Create a new sync transport instance.
     */
    public function __construct(protected Exporter $exporter) {}

    public function send(array $spans): void
    {
        foreach ($this->parts($spans) as $part) {
            $this->export($part);
        }
    }

    public function sendNow(array $spans): array
    {
        $this->startSend();

        $kept = [];

        foreach ($this->parts($spans) as $part) {
            array_push($kept, ...$this->exportNow($part));
        }

        return $kept;
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /**
     * Forget the wait the destination asked for and whether it was reached, at the start of a send that may span many parts.
     */
    public function startSend(): void
    {
        $this->retryAfter = null;
        $this->unreachable = false;
    }

    /**
     * Get the parts the exporter sends the given batch in, or the batch as one part.
     *
     * @param  list<array<string, mixed>>  $spans
     * @return list<list<array<string, mixed>>>
     */
    public function parts(array $spans): array
    {
        try {
            return $this->exporter instanceof Parts ? $this->exporter->parts($spans) : [$spans];
        } catch (Throwable) {
            return [$spans];
        }
    }

    /**
     * Export one part in this process, without splitting it again.
     *
     * @param  list<array<string, mixed>>  $spans
     */
    public function export(array $spans): void
    {
        if ($this->result($spans) === ExportResult::Retryable) {
            Diagnostics::warn('export.unavailable', 'Spans could not be exported: the destination could not be reached or answered 408, 429 or 5xx. The batch was dropped (spans exported at the end of a request, job or command are not retried).');
        }
    }

    /**
     * Export one part in this process while it keeps going, and give the part back when the destination could not take it now.
     *
     * After a part of this send could not reach the destination, the part is given back without a try.
     *
     * @param  list<array<string, mixed>>  $spans
     * @return list<array<string, mixed>>
     */
    public function exportNow(array $spans): array
    {
        if ($this->unreachable) {
            return $spans;
        }

        if ($this->result($spans) !== ExportResult::Retryable) {
            return [];
        }

        if ($this->exporter instanceof Reachability && $this->exporter->unreachable()) {
            $this->unreachable = true;
        }

        $seconds = $this->exporter instanceof RetryAfter ? $this->exporter->retryAfter() : null;

        if ($seconds !== null && ($this->retryAfter === null || $seconds > $this->retryAfter)) {
            $this->retryAfter = $seconds;
        }

        Diagnostics::warn('export.kept', 'Spans could not be exported while the process runs: the destination could not be reached or answered 408, 429 or 5xx. They are kept and tried again later.');

        return $spans;
    }

    /**
     * Export one part, or get null when the exporter throws, which drops the part with one warning.
     *
     * @param  list<array<string, mixed>>  $spans
     */
    protected function result(array $spans): ?ExportResult
    {
        try {
            return $this->exporter->export($spans);
        } catch (Throwable $e) {
            Diagnostics::warn('export.error', 'Spans could not be exported ('.$e::class.'). The batch was dropped.');

            return null;
        }
    }
}
