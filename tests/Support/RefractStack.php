<?php

namespace Anantrp\Refract\Tests\Support;

/**
 * Tells whether Refract's own code (not a test) is on the call stack.
 */
class RefractStack
{
    /**
     * Determine if a Refract class called the code that asks.
     */
    public static function calledByRefract(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $class = $frame['class'] ?? '';

            if (str_starts_with($class, 'Anantrp\\Refract\\') && ! str_starts_with($class, 'Anantrp\\Refract\\Tests\\')) {
                return true;
            }
        }

        return false;
    }
}
