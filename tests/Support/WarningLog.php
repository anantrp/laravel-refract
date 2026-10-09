<?php

namespace Anantrp\Refract\Tests\Support;

/**
 * Stands in for the Log facade and keeps every warning message.
 */
class WarningLog
{
    /**
     * The warning messages logged so far.
     *
     * @var list<string>
     */
    public array $warnings = [];

    public function warning(string $message): void
    {
        $this->warnings[] = $message;
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): void
    {
        //
    }
}
