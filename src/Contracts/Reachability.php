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
     * Determine if the last export got no answer at all: the destination could not be reached.
     */
    public function unreachable(): bool;
}
