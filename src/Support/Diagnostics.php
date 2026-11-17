<?php

namespace Anantrp\Refract\Support;

use Anantrp\Refract\Events\TracesDropped;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one warning path, and the one path for lost traces. Never throws.
 */
class Diagnostics
{
    /**
     * The seconds a key is not logged again for after it was.
     */
    public const WINDOW = 600;

    /**
     * The seconds the count of warnings held back is kept for, in case no warning comes after them for a while.
     */
    protected const HELD_FOR = 86_400;

    /**
     * When this process last logged each key, for when the cache fails.
     *
     * @var array<string, int>
     */
    protected static array $logged = [];

    /**
     * The warnings this process held back since it last logged each key, for when the cache fails.
     *
     * @var array<string, int>
     */
    protected static array $held = [];

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
     * Log the given warning, at most once per window for its key, in every process that shares the default cache.
     *
     * The warnings held back meanwhile are counted, and the next one logged
     * says how many there were. When the cache fails, or cannot save, the
     * process keeps the window on its own.
     */
    public static function warn(string $key, string $message): void
    {
        $held = self::shared($key);

        if ($held === false) {
            return;
        }

        if ($held === null) {
            $now = Carbon::now()->getTimestamp();

            if (isset(self::$logged[$key]) && $now - self::$logged[$key] < self::WINDOW) {
                self::$held[$key] = (self::$held[$key] ?? 0) + 1;

                return;
            }

            $held = self::$held[$key] ?? 0;
            self::$logged[$key] = $now;
            unset(self::$held[$key]);
        }

        try {
            Log::warning("[refract] {$message}".($held > 0 ? " ({$held} more since the last warning)" : ''));
        } catch (Throwable) {
            //
        }
    }

    /**
     * Claim the window of the given key in the default cache: the warnings held back since it was last logged, false while it is held, or null when the cache cannot tell.
     */
    protected static function shared(string $key): int|false|null
    {
        try {
            $cache = app(Cache::class);

            if ($cache->add("refract:warned:{$key}", true, self::WINDOW)) {
                $held = $cache->pull("refract:warned:{$key}:count", 0);

                return is_numeric($held) ? (int) $held : 0;
            }

            // A cache that cannot save would hold every warning back for good.
            if ($cache->get("refract:warned:{$key}") === null) {
                return null;
            }

            $cache->add("refract:warned:{$key}:count", 0, self::HELD_FOR);
            $cache->increment("refract:warned:{$key}:count");

            return false;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Forget what this process kept about its warnings.
     */
    public static function reset(): void
    {
        self::$logged = [];
        self::$held = [];
    }
}
