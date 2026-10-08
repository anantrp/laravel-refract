<?php

namespace Anantrp\Refract\Contracts;

/**
 * An exporter that can say whether its last export reached the destination.
 *
 * It is read next to the ExportResult, which is an enum and cannot carry it.
 */
interface Reachability
{
    /**
     * Determine if the last export could not connect to the destination.
     */
    public function unreachable(): bool;
}
