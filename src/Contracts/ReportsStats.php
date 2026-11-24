<?php

namespace Anantrp\Refract\Contracts;

/**
 * A collector that can say what it holds, for refract:status and the stall warning.
 */
interface ReportsStats
{
    /**
     * Get the trees stored, their bytes, the cap, the seconds the oldest due tree has waited (null when none is due), the trees dropped at the cap where the store counts them, and the most failed attempts of a stored tree.
     *
     * @return array{trees: int, bytes: int, max_bytes: int, oldest_age: ?int, dropped: ?int, max_attempts: int}
     */
    public function stats(): array;
}
