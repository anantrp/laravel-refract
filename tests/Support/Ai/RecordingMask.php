<?php

namespace Anantrp\Refract\Tests\Support\Ai;

/**
 * Replaces "secret" with "[masked]" and keeps every value it is given.
 */
class RecordingMask
{
    /**
     * The values the mask was called with.
     *
     * @var list<string>
     */
    public static array $seen = [];

    public function __invoke(string $value): string
    {
        self::$seen[] = $value;

        return str_replace('secret', '[masked]', $value);
    }
}
