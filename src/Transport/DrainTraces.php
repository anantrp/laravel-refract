<?php

namespace Anantrp\Refract\Transport;

use Anantrp\Refract\Contracts\Collector;
use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Contracts\ExportResult;
use Anantrp\Refract\Support\Diagnostics;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\InteractsWithTime;
use Throwable;

/**
 * Exports the collected run trees from the app's own queue workers.
 *
 * The job claims trees, exports them and acks them, until the store is
 * empty or its time budget is spent; then it dispatches the next drain.
 * When the destination cannot take a batch now, the batch is released
 * with a backoff and the drain stops until then. It never throws, so it
 * never reaches the failed jobs table.
 *
 * It is unique until it starts processing: while one drain is waiting,
 * dispatching another is a no-op, and a running drain can dispatch the
 * next one. The lock lives in the app's default cache store, so the
 * store must be shared by every process for the lock to be shared too.
 */
class DrainTraces implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, InteractsWithTime, Queueable;

    /**
     * The queue the drain runs on, of the app's default queue connection.
     */
    public const QUEUE = 'refract';

    /**
     * The seconds a drain waits after the push that dispatches it, so a burst of runs leaves together.
     */
    public const DELAY = 5;

    /**
     * The seconds an export from the drain waits for the destination.
     */
    public const TIMEOUT = 15;

    /**
     * The most bytes of stored trees one claim takes, about one request.
     */
    public const CLAIM_BYTES = 4_000_000;

    /**
     * The seconds a claim holds its trees. Longer than the job may run, so a live drain never loses its trees to another.
     */
    public const LEASE = 120;

    /**
     * The seconds one drain spends claiming, before it hands over to the next drain.
     *
     * Its last export may add up to a few TIMEOUTs, which keeps the job
     * well under its timeout and under the queue's default retry_after.
     */
    protected const int BUDGET = 20;

    /**
     * The seconds to wait after each failed export in a row. A Retry-After is waited for when it is longer.
     *
     * @var list<int>
     */
    protected const BACKOFF = [10, 60, 300];

    /**
     * The most seconds the drain waits after a failed export, a Retry-After included.
     */
    protected const MAX_BACKOFF = 300;

    /**
     * The seconds to wait before looking again when trees are stored but none can be claimed yet.
     */
    protected const IDLE = 30;

    /**
     * The seconds the unique lock outlives the drain's delay, so a lost drain job never blocks the next one for good.
     */
    protected const LOCK_MARGIN = 60;

    /**
     * The cache key of the backoff shared by every drain: when the next export may be tried, and the failures in a row.
     */
    protected const BACKOFF_KEY = 'refract:drain:backoff';

    /**
     * The drain is tried once. A retry is a new drain, dispatched by this one.
     */
    public int $tries = 1;

    /**
     * The seconds the worker lets the drain run.
     */
    public int $timeout = 60;

    /**
     * Create a new drain job instance that runs after the given seconds.
     *
     * It never waits for the app's database transactions: a queue
     * connection with after_commit would hold the drain until they
     * commit, and drop it on a rollback.
     */
    public function __construct(public int $wait = self::DELAY)
    {
        $this->onQueue(self::QUEUE)->delay($wait)->beforeCommit();
    }

    /**
     * Get the seconds the unique lock is held: until the drain is due, and a margin.
     */
    public function uniqueFor(): int
    {
        return $this->wait + self::LOCK_MARGIN;
    }

    /**
     * Export the stored trees, and never throw.
     */
    public function handle(Container $container): void
    {
        try {
            $this->drain($container->make(Collector::class), $container->make(Exporter::class), $container->make(Cache::class));
        } catch (Throwable $e) {
            Diagnostics::warn('drain.error', 'The queue drain failed ('.$e::class.'). The trees it claimed are sent again when their lease runs out.');

            // So the trees it may have claimed are not left waiting for the next run.
            try {
                $this->next(self::LEASE);
            } catch (Throwable) {
                //
            }
        }
    }

    /**
     * Claim, export and ack until the store is empty, the destination cannot take a batch, or the budget is spent.
     */
    protected function drain(Collector $collector, Exporter $exporter, Cache $cache): void
    {
        $deadline = hrtime(true) + static::BUDGET * 1_000_000_000;
        [$until, $failures] = $this->backoffState($cache);

        // Another drain was told to wait: this one never sends before then.
        if ($until > $now = $this->currentTime()) {
            $this->next($until - $now);

            return;
        }

        while (hrtime(true) < $deadline) {
            $trees = $collector->claim(self::CLAIM_BYTES, self::LEASE);

            if ($trees === []) {
                // The stored trees are released for later, or claimed by another drain whose lease may run out.
                if ($collector->size() > 0) {
                    $this->next(self::IDLE);
                }

                return;
            }

            $result = $this->export($exporter, array_values($trees));

            if ($result->isRetryable()) {
                $this->retryLater($collector, $cache, array_keys($trees), $result, $failures + 1);

                return;
            }

            $collector->ack(array_keys($trees));

            if ($failures > 0) {
                $cache->forget(self::BACKOFF_KEY);
                $failures = 0;
            }
        }

        if ($collector->size() > 0) {
            $this->next(0);
        }
    }

    /**
     * Export the given trees. An exporter that throws drops them, so a tree that breaks it is never tried again.
     *
     * @param  list<list<array<string, mixed>>>  $trees
     */
    protected function export(Exporter $exporter, array $trees): ExportResult
    {
        try {
            return $exporter->export($trees);
        } catch (Throwable $e) {
            Diagnostics::warn('export.error', 'Spans could not be exported ('.$e::class.'). The batch was dropped.');

            return ExportResult::rejected();
        }
    }

    /**
     * Release the given trees, record the backoff for every drain, and dispatch the drain that tries them again.
     *
     * The wait is the backoff of the failures in a row, or the Retry-After
     * when it is longer: Retry-After means "not before".
     *
     * @param  list<array-key>  $ids
     */
    protected function retryLater(Collector $collector, Cache $cache, array $ids, ExportResult $result, int $failures): void
    {
        $delay = min(self::MAX_BACKOFF, max($result->retryAfter ?? 0, self::BACKOFF[min($failures, count(self::BACKOFF)) - 1]));

        $collector->release($ids, $delay);

        $cache->put(self::BACKOFF_KEY, ['until' => $this->currentTime() + $delay, 'failures' => $failures], $delay + self::LOCK_MARGIN);

        Diagnostics::warn('drain.retry', "Spans could not be exported: the destination could not be reached or answered 408, 429 or 5xx. They are tried again in {$delay} seconds.");

        $this->next($delay);
    }

    /**
     * Get when the next export may be tried, as a Unix time, and the failed exports in a row.
     *
     * @return array{int, int}
     */
    protected function backoffState(Cache $cache): array
    {
        $backoff = $cache->get(self::BACKOFF_KEY);

        if (! is_array($backoff) || ! is_int($backoff['until'] ?? null) || ! is_int($backoff['failures'] ?? null)) {
            return [0, 0];
        }

        return [$backoff['until'], $backoff['failures']];
    }

    /**
     * Dispatch the next drain, to run after the given seconds.
     *
     * On a sync-like queue connection the next drain would run at once,
     * inside this one: the stored trees wait for the next push instead.
     */
    protected function next(int $seconds): void
    {
        if ($this->job instanceof SyncJob) {
            Diagnostics::warn('drain.sync', 'The queue drain runs on a sync queue connection, so it cannot wait before its next try. Stored trees are sent by the drain of the next run. Use an async queue connection.');

            return;
        }

        static::dispatch($seconds);
    }
}
