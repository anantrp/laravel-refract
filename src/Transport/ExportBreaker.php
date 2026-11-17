<?php

namespace Anantrp\Refract\Transport;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\InteractsWithTime;
use Throwable;

/**
 * Remembers that the destination failed, so exports in this process skip it for a while.
 *
 * After a failed export (a network error, a timeout, 408, 429 or 5xx),
 * exports are paused for FIRST_PAUSE seconds, doubling on each failure
 * in a row up to MAX_PAUSE. Once the pause is over, the next export is
 * tried: an answer closes the breaker, another failure pauses again.
 *
 * The pause is kept in the process, for queue workers and long commands,
 * and in the app's default cache, for PHP-FPM, where each request starts
 * with nothing. A cache that fails leaves the process state alone in
 * charge; it never fails the export.
 */
class ExportBreaker
{
    use InteractsWithTime;

    /**
     * The seconds exports pause after the first failed export.
     */
    public const FIRST_PAUSE = 30;

    /**
     * The most seconds exports pause.
     */
    public const MAX_PAUSE = 300;

    /**
     * The cache key of the pause shared by every process: when exports may be tried again, and the failures in a row.
     */
    protected const KEY = 'refract:sync:breaker';

    /**
     * The pause known to this process, or null when exports are not paused.
     *
     * @var array{until: int, failures: int}|null
     */
    protected static ?array $state = null;

    /**
     * Create a new breaker instance.
     */
    public function __construct(protected Container $container) {}

    /**
     * Determine if exports are paused now.
     */
    public function isOpen(): bool
    {
        return $this->state()['until'] > $this->currentTime();
    }

    /**
     * Record one more failed export in a row, and get the seconds exports now pause for.
     */
    public function failed(): int
    {
        $failures = $this->state()['failures'] + 1;
        $pause = min(self::MAX_PAUSE, self::FIRST_PAUSE * 2 ** min($failures - 1, 8));

        $state = ['until' => $this->currentTime() + $pause, 'failures' => $failures];

        self::$state = $state;

        // Kept past the pause, so a probe that fails again doubles it.
        $this->cache(fn (Cache $cache) => $cache->put(self::KEY, $state, $pause + self::MAX_PAUSE));

        return $pause;
    }

    /**
     * Record that the destination answered, and close the breaker.
     */
    public function answered(): void
    {
        if ($this->state()['failures'] === 0) {
            return;
        }

        self::$state = null;

        $this->cache(fn (Cache $cache) => $cache->forget(self::KEY));
    }

    /**
     * Forget the pause known to this process. The cache keeps its own.
     */
    public static function reset(): void
    {
        self::$state = null;
    }

    /**
     * Get the latest pause known to this process or the cache.
     *
     * @return array{until: int, failures: int}
     */
    protected function state(): array
    {
        $local = self::$state ?? ['until' => 0, 'failures' => 0];
        $shared = $this->cache(fn (Cache $cache) => $cache->get(self::KEY));

        if (! is_array($shared) || ! is_int($shared['until'] ?? null) || ! is_int($shared['failures'] ?? null)) {
            return $local;
        }

        return $shared['until'] > $local['until'] ? ['until' => $shared['until'], 'failures' => $shared['failures']] : $local;
    }

    /**
     * Run the given callback on the app's default cache, or get null when the cache fails.
     *
     * @template TValue
     *
     * @param  callable(Cache): TValue  $callback
     * @return TValue|null
     */
    protected function cache(callable $callback): mixed
    {
        try {
            return $callback($this->container->make(Cache::class));
        } catch (Throwable) {
            return null;
        }
    }
}
