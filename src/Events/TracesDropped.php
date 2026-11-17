<?php

namespace Anantrp\Refract\Events;

/**
 * Refract lost traces: listen for it to alert, or to count drops in your own metrics.
 *
 * The count is of run trees, except for the run_cap reason, where it is
 * of the spans one run dropped at its cap. A listener must not throw; if
 * it does, Refract ignores it.
 */
final class TracesDropped
{
    /**
     * Create a new event instance.
     *
     * @param  string  $reason  One of full, encode, decode, transaction, paused, unavailable, rejected, unfinished, error, run_cap, poison.
     */
    public function __construct(
        public readonly int $count,
        public readonly string $reason,
    ) {}
}
