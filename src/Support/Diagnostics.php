<?php

namespace Anantrp\Refract\Support;

use Anantrp\Refract\Events\TracesDropped;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one warning path, and the one path for lost traces. Never throws.
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
     * Whether a TracesDropped event is being dispatched, so a listener that drops traces does not dispatch another.
     */
    protected static bool $dispatching = false;

    /**
     * Record that traces were lost: count them by reason, fire TracesDropped, and warn under the given key.
     *
     * Without a key nothing is logged, for a loss whose warning was already logged where it happened.
     */
    public static function dropped(string $reason, int $count, ?string $key = null, ?string $message = null): void
    {
        if ($count <= 0) {
            return;
        }

        Stats::dropped($reason, $count);

        if (! self::$dispatching) {
            self::$dispatching = true;

            try {
                event(new TracesDropped($count, $reason));
            } catch (Throwable) {
                //
            } finally {
                self::$dispatching = false;
            }
        }

        if ($key !== null && $message !== null) {
            self::warn($key, $message);
        }
    }

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
