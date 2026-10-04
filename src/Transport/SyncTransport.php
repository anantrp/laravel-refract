<?php

namespace Anantrp\Refract\Transport;

use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Contracts\Transport;

/**
 * Exports each batch in the current process, at the flush point.
 */
class SyncTransport implements Transport
{
    /**
     * Create a new sync transport instance.
     */
    public function __construct(protected Exporter $exporter) {}

    public function send(array $spans): void
    {
        $this->exporter->export($spans);
    }
}
