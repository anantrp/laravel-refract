<?php

namespace Anantrp\Refract\Tests\Support;

use Anantrp\Refract\Capture\Recorder;

/**
 * A recorder whose monotonic clock and wall clock the test sets, in nanoseconds.
 */
class ClockRecorder extends Recorder
{
    /**
     * The monotonic clock reading.
     */
    public int $monotonicNow = 0;

    /**
     * The wall clock reading.
     */
    public int $wallNow = 0;

    protected function monotonic(): int
    {
        return $this->monotonicNow;
    }

    protected function wall(): int
    {
        return $this->wallNow;
    }
}
