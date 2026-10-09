<?php

namespace Anantrp\Refract\Tests\Support\Ai;

use RuntimeException;

/**
 * Throws with the value in its message, as a buggy mask might.
 */
class ThrowingMask
{
    public function __invoke(string $value): string
    {
        throw new RuntimeException("cannot mask {$value}");
    }
}
