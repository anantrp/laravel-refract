<?php

namespace Anantrp\Refract\Transport;

use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Support\Diagnostics;
use Throwable;

/**
 * Takes the finished run trees from capture and sends them on their way.
 *
 * The trees are exported in this process, once. It is best effort: a
 * batch the destination cannot take is dropped with one warning, and
 * nothing ever reaches the app.
 */
class Drain
{
    /**
     * The seconds an export in this process waits for the destination.
     */
    public const TIMEOUT = 5;

    /**
     * Create a new drain instance.
     */
    public function __construct(protected Exporter $exporter) {}

    /**
     * Send the given finished run trees, each a list of neutral spans.
     *
     * @param  list<list<array<string, mixed>>>  $trees
     */
    public function send(array $trees): void
    {
        if ($trees === []) {
            return;
        }

        try {
            $result = $this->exporter->export($trees);
        } catch (Throwable $e) {
            Diagnostics::warn('export.error', 'Spans could not be exported ('.$e::class.'). The batch was dropped.');

            return;
        }

        if ($result->isRetryable()) {
            Diagnostics::warn('export.unavailable', 'Spans could not be exported: the destination could not be reached or answered 408, 429 or 5xx. The batch was dropped (an export in this process is not retried).');
        }
    }
}
