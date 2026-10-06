<?php

namespace Anantrp\Refract\Transport;

use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Contracts\ExportResult;
use Anantrp\Refract\Contracts\Parts;
use Anantrp\Refract\Contracts\Transport;
use Anantrp\Refract\Support\Diagnostics;
use Throwable;

/**
 * Exports each batch in the current process, at the flush point.
 *
 * It never retries: a batch the destination could not take now (a network
 * error, 408, 429 or 5xx) is dropped with one warning. A rejected batch
 * has already been warned about by the exporter. An exporter that throws
 * drops the batch with one warning.
 *
 * A batch the exporter splits into parts is sent one part after another.
 * Each failed part gives its own warning, and the later parts are still sent.
 */
class SyncTransport implements Transport
{
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
        try {
            $result = $this->exporter->export($spans);
        } catch (Throwable $e) {
            Diagnostics::warn('export.error', 'Spans could not be exported ('.$e::class.'). The batch was dropped.');

            return;
        }

        if ($result === ExportResult::Retryable) {
            Diagnostics::warn('export.unavailable', 'Spans could not be exported: the destination could not be reached or answered 408, 429 or 5xx. The batch was dropped (spans exported in-process are not retried).');
        }
    }
}
