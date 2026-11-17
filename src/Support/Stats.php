<?php

namespace Anantrp\Refract\Support;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Refract's durable counters: the trees dropped by reason, and when an export last succeeded and last failed.
 *
 * They live in the app's default cache, without an expiry, so every
 * process adds to the same counts and refract:status reads them later.
 * What the cache cannot take is kept in the process instead, and read
 * then says the counts are not durable. It never throws.
 */
class Stats
{
    /**
     * Every reason a tree is dropped for.
     *
     * @var list<string>
     */
    public const REASONS = ['full', 'encode', 'decode', 'transaction', 'paused', 'unavailable', 'unfinished', 'error', 'run_cap', 'poison', 'rejected'];

    /**
     * The prefix of the cache key that counts the drops of one reason.
     */
    protected const DROPPED = 'refract:stats:dropped:';

    /**
     * The cache key of the Unix time an export last succeeded.
     */
    protected const LAST_SUCCESS = 'refract:stats:last_success';

    /**
     * The cache key of the Unix time an export last failed.
     */
    protected const LAST_FAILURE = 'refract:stats:last_failure';

    /**
     * The drops by reason the cache could not take.
     *
     * @var array<string, int>
     */
    protected static array $dropped = [];

    /**
     * The times the cache could not take, by key.
     *
     * @var array<string, int>
     */
    protected static array $times = [];

    /**
     * Count the given drops for their reason.
     */
    public static function dropped(string $reason, int $count): void
    {
        $saved = self::cache(function (Cache $cache) use ($reason, $count) {
            $cache->add(self::DROPPED.$reason, 0);

            return $cache->increment(self::DROPPED.$reason, $count) !== false;
        });

        if ($saved !== true) {
            self::$dropped[$reason] = (self::$dropped[$reason] ?? 0) + $count;
        }
    }

    /**
     * Record that an export succeeded now.
     */
    public static function exported(): void
    {
        self::time(self::LAST_SUCCESS);
    }

    /**
     * Record that an export failed now.
     */
    public static function failed(): void
    {
        self::time(self::LAST_FAILURE);
    }

    /**
     * Get the drops by reason, the last success and failure times, and whether the counts are shared by every process.
     *
     * @return array{dropped: array<string, int>, last_success: ?int, last_failure: ?int, durable: bool}
     */
    public static function read(): array
    {
        $shared = self::cache(function (Cache $cache) {
            $values = $cache->many([...array_map(fn ($reason) => self::DROPPED.$reason, self::REASONS), self::LAST_SUCCESS, self::LAST_FAILURE]);

            return array_map(fn ($value) => is_numeric($value) ? (int) $value : null, $values);
        });

        $dropped = [];

        foreach (self::REASONS as $reason) {
            $count = ($shared[self::DROPPED.$reason] ?? 0) + (self::$dropped[$reason] ?? 0);

            if ($count > 0) {
                $dropped[$reason] = $count;
            }
        }

        foreach (array_diff_key(self::$dropped, $dropped) as $reason => $count) {
            $dropped[$reason] = $count;
        }

        return [
            'dropped' => $dropped,
            'last_success' => self::latest($shared[self::LAST_SUCCESS] ?? null, self::$times[self::LAST_SUCCESS] ?? null),
            'last_failure' => self::latest($shared[self::LAST_FAILURE] ?? null, self::$times[self::LAST_FAILURE] ?? null),
            'durable' => $shared !== null && self::$dropped === [] && self::$times === [] && self::durableStore(),
        ];
    }

    /**
     * Forget what this process kept.
     */
    public static function reset(): void
    {
        self::$dropped = [];
        self::$times = [];
    }

    /**
     * Get the name of the app's default cache store's driver.
     */
    public static function driver(): string
    {
        try {
            $default = config('cache.default');
            $driver = is_string($default) ? config("cache.stores.{$default}.driver") : null;

            return is_string($driver) ? $driver : 'unknown';
        } catch (Throwable) {
            return 'unknown';
        }
    }

    /**
     * Determine whether the app's default cache store outlives a process.
     */
    protected static function durableStore(): bool
    {
        return ! in_array(self::driver(), ['array', 'null', 'unknown'], true);
    }

    /**
     * Put the time now under the given key.
     */
    protected static function time(string $key): void
    {
        $now = self::now();

        if (self::cache(fn (Cache $cache) => $cache->forever($key, $now)) !== true) {
            self::$times[$key] = $now;
        }
    }

    /**
     * Get the later of two times.
     */
    protected static function latest(?int $one, ?int $other): ?int
    {
        return $one === null || $other === null ? ($one ?? $other) : max($one, $other);
    }

    /**
     * Get the current Unix time.
     */
    protected static function now(): int
    {
        return Carbon::now()->getTimestamp();
    }

    /**
     * Run the given callback with the default cache, or get null when the cache fails.
     *
     * @template T
     *
     * @param  callable(Cache): T  $callback
     * @return T|null
     */
    protected static function cache(callable $callback): mixed
    {
        try {
            return $callback(app(Cache::class));
        } catch (Throwable) {
            return null;
        }
    }
}
