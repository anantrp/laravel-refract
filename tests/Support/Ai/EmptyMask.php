<?php

namespace Anantrp\Refract\Tests\Support\Ai;

/**
 * Masks every value to an empty string.
 */
class EmptyMask
{
    public function __invoke(string $value): string
    {
        return '';
    }
}
