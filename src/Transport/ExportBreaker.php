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
 * in a row up to MAX_PAUSE. Once the pause is over, one export probes
 * the destination: an answer closes the breaker, another failure pauses
 * again. Exports in other processes keep dropping while it probes.
 *
 * The pause lives in the app's default cache, so every process (each
 * PHP-FPM request, each queue worker) sees the same one, and a probe in
 * one process closes it for all. Each process keeps a copy of the last
 * pause it saw, used while the cache fails, and kept over the cache's
 * answer when the cache could not save it (a full disk, a full Redis).
 * A failing cache never fails the export.
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
     * The cache key a process claims to probe the destination once the pause is over.
     */
    protected const PROBE_KEY = 'refract:sync:probe';

    /**
     * The seconds a claim to probe lasts: longer than an export, so a process that dies while probing does not block exports for long.
     */
    protected const PROBE_SECONDS = 10;

    /**
     * The last pause this process saw, or null when exports are not paused.
     *
     * @var array{until: int, failures: int}|null
     */
    protected static ?array $state = null;

    /**
     * Whether the pause in $state failed to reach the cache, so the cache does not know it.
     */
    protected static bool $unsaved = false;

    /**
     * Create a new breaker instance.
     */
    public function __construct(protected Container $container) {}

    /**
     * Determine if exports are paused now.
     */
    public function isOpen(): bool
    {
        $until = $this->state()['until'];

        if ($until === 0) {
            return false;
        }

        if ($until > $this->currentTime()) {
            return true;
        }

        // The pause is over: one export probes. A pause the cache does not know,
        // or a cache that fails, cannot share the claim: the export goes
        // through, as exports in one process run one at a time.
        if (self::$unsaved) {
            return false;
        }

        // A claim that fails while no other claim is there is a cache that cannot
        // write: this process probes.
        return $this->cache(fn (Cache $cache) => ! $cache->add(self::PROBE_KEY, true, self::PROBE_SECONDS) && $cache->get(self::PROBE_KEY) !== null) === true;
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
        self::$unsaved = $this->cache(fn (Cache $cache) => $cache->put(self::KEY, $state, $pause + self::MAX_PAUSE)) !== true;

        $this->cache(fn (Cache $cache) => $cache->forget(self::PROBE_KEY));

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
        self::$unsaved = false;

        $this->cache(function (Cache $cache) {
            $cache->forget(self::KEY);
            $cache->forget(self::PROBE_KEY);
        });
    }

    /**
     * Forget the pause known to this process. The cache keeps its own.
     */
    public static function reset(): void
    {
        self::$state = null;
        self::$unsaved = false;
    }

    /**
     * Get the pause in the cache, and keep a copy of it; or this process's own pause, while the cache fails, or when the cache could not save it and holds nothing later.
     *
     * @return array{until: int, failures: int}
     */
    protected function state(): array
    {
        $read = $this->cache(fn (Cache $cache) => ['shared' => $cache->get(self::KEY)]);

        if ($read !== null) {
            $shared = $read['shared'];

            $shared = is_array($shared) && is_int($shared['until'] ?? null) && is_int($shared['failures'] ?? null)
                ? ['until' => $shared['until'], 'failures' => $shared['failures']]
                : null;

            // A pause the cache never took gives way only to a later one. Otherwise
            // the cache is right: a pause missing from it was closed by a probe.
            if (! self::$unsaved || ($shared !== null && $shared['until'] > (self::$state['until'] ?? 0))) {
                self::$state = $shared;
                self::$unsaved = false;
            }
        }

        return self::$state ?? ['until' => 0, 'failures' => 0];
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
