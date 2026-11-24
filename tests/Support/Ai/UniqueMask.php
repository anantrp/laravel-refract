<?php

namespace Anantrp\Refract\Tests\Support\Ai;

/**
 * A mask that is not pure: every call gives a different value.
 */
class UniqueMask
{
    public function __invoke(string $value): string
    {
        return $value.' '.bin2hex(random_bytes(8));
    }
}
