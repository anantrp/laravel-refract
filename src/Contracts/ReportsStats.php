<?php

namespace Anantrp\Refract\Contracts;

/**
 * A collector that can say what it holds, for refract:status and the stall warning.
 */
interface ReportsStats
{
    /**
     * Get the trees stored, their bytes, the cap, the seconds the oldest due tree has waited (null when none is due), and the trees dropped at the cap where the store counts them.
     *
     * @return array{trees: int, bytes: int, max_bytes: int, oldest_age: ?int, dropped: ?int}
     */
    public function stats(): array;
}
