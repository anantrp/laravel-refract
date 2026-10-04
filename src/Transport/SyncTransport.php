<?php

namespace Anantrp\Refract\Transport;

use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Contracts\ExportResult;
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
 */
class SyncTransport implements Transport
{
    /**
     * Create a new sync transport instance.
     */
    public function __construct(protected Exporter $exporter) {}

    public function send(array $spans): void
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
