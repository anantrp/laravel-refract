<?php

namespace Anantrp\Refract\Contracts;

/**
 * An exporter, or a transport that sends now, that can say how long the destination asked it to wait.
 *
 * It is read next to the ExportResult, which is an enum and cannot carry the time.
 */
interface RetryAfter
{
    /**
     * Get the seconds the destination asked to wait after the last export
     * that was Retryable, or null when it did not ask.
     */
    public function retryAfter(): ?int;
}
