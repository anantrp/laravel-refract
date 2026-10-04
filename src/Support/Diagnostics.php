<?php

namespace Anantrp\Refract\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one warning path: once per key per process, at most 10 keys, never throws.
 */
class Diagnostics
{
    /**
     * The most keys warned about in one process.
     */
    public const MAX_KEYS = 10;

    /**
     * The keys warned about so far.
     *
     * @var array<string, true>
     */
    protected static array $warned = [];

    /**
     * Log the given warning once for its key.
     */
    public static function warn(string $key, string $message): void
    {
        if (isset(self::$warned[$key]) || count(self::$warned) >= self::MAX_KEYS) {
            return;
        }

        self::$warned[$key] = true;

        try {
            Log::warning("[refract] {$message}");
        } catch (Throwable) {
            //
        }
    }

    /**
     * Forget the keys warned about so far.
     */
    public static function reset(): void
    {
        self::$warned = [];
    }
}
