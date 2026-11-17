<?php

namespace Anantrp\Refract\Transport;

use Anantrp\Refract\Contracts\Collector;
use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Contracts\ExportResult;
use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Support\Stats;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Carbon;
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
 * It keeps to a time limit, so it ends well before its worker timeout and
 * before any queue connection's retry_after, and a claim's lease outlives
 * it: a drain is never killed or run twice in the middle of an export. A
 * drain that is killed anyway (a crash, a deploy) is never failed: the
 * queue gives it to a worker again after its retry_after, and the trees it
 * claimed are claimed again once their lease runs out.
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
     * The seconds an export from the drain waits to connect to the destination.
     */
    public const CONNECT_TIMEOUT = 5;

    /**
     * The most bytes of stored trees one claim takes. Trees are stored gzipped: for text, about one 4 MB request of JSON.
     */
    public const CLAIM_BYTES = 1_000_000;

    /**
     * The seconds a claim holds its trees: twice the job's timeout, so a drain still running never loses its trees to another.
     */
    public const LEASE = 120;

    /**
     * The seconds one drain starts new claims for, before it hands over to the next drain.
     */
    protected const int BUDGET = 20;

    /**
     * The seconds after the drain starts by which its exports end.
     *
     * A claim is at most CLAIM_BYTES and one tree, and a tree over a
     * request's limit is sent in more than one request: the export is
     * given what is left of this limit, never starts a request it could
     * not finish in time, and leaves the rest of the claim for the next
     * drain. With the claims, acks and encoding around it, the drain ends
     * well within its 60 second timeout, itself within the 90 second
     * retry_after of Laravel's default queue connections.
     */
    protected const int LIMIT = 45;

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
     * The failed attempts after which a tree is sent alone, so it cannot hold back the trees claimed with it.
     */
    public const SEND_ALONE = 3;

    /**
     * The failed attempts after which a tree sent alone is dropped, when the destination took other trees in the same drain.
     */
    public const DROP_AFTER = 10;

    /**
     * The seconds a tree that failed alone waits, while the destination takes others.
     */
    protected const ALONE_DELAY = 60;

    /**
     * The cache key of the backoff shared by every drain: when the next export may be tried, and the failures in a row.
     */
    protected const BACKOFF_KEY = 'refract:drain:backoff';

    /**
     * The drain is never failed for its attempts: a drain killed by a crash or its timeout is given to a worker again.
     *
     * The drain never throws, so it is attempted again only when its
     * worker died. A retry after a failed export is a new drain,
     * dispatched by this one.
     */
    public int $tries = 0;

    /**
     * The seconds the worker lets the drain run: longer than LIMIT, shorter than LEASE.
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
            $collector = $container->make(Collector::class);
            $cache = $container->make(Cache::class);
        } catch (Throwable $e) {
            $this->broke($e);

            return;
        }

        try {
            $exporter = $container->make(Exporter::class);
        } catch (Throwable $e) {
            $this->withoutExporter($collector, $cache, $e);

            return;
        }

        try {
            $this->drain($collector, $exporter, $cache);
        } catch (Throwable $e) {
            $this->broke($e);
        }
    }

    /**
     * Warn that the drain failed, and dispatch the next one for when the trees it may have claimed are due again.
     */
    protected function broke(Throwable $e): void
    {
        Diagnostics::warn('drain.error', 'The queue drain failed ('.$e::class.'). The trees it claimed are sent again when their lease runs out.');

        try {
            $this->next(self::LEASE);
        } catch (Throwable) {
            //
        }
    }

    /**
     * Warn that no exporter could be made, and try again with the backoff of a failed export while trees are stored.
     */
    protected function withoutExporter(Collector $collector, Cache $cache, Throwable $e): void
    {
        Diagnostics::warn('drain.exporter', 'The queue drain could not make the exporter ('.$e::class.'). Check the destination settings; the stored trees are kept.');

        try {
            if ($collector->size() > 0) {
                $this->next($this->failureDelay($cache, ExportResult::retryable()));
            }
        } catch (Throwable) {
            //
        }
    }

    /**
     * Claim, export and ack until the store is empty, the destination cannot take a batch, or the time is spent.
     */
    protected function drain(Collector $collector, Exporter $exporter, Cache $cache): void
    {
        $started = hrtime(true);
        [$until, $failures] = $this->backoffState($cache);

        // Another drain was told to wait: this one never sends before then.
        if ($until > $now = $this->currentTime()) {
            $this->next($until - $now);

            return;
        }

        while ($this->elapsed($started) < static::BUDGET) {
            $trees = $collector->claim(self::CLAIM_BYTES, self::LEASE);

            if ($trees === []) {
                // The stored trees are released for later, or claimed by another drain whose lease may run out.
                if ($collector->size() > 0) {
                    $this->next(self::IDLE);
                }

                return;
            }

            [$batch, $suspects, $attempts] = $this->split($collector, $trees);
            $answered = false;

            if ($batch !== []) {
                $result = $this->export($exporter, $this->only($trees, $batch), static::LIMIT - $this->elapsed($started));

                if ($result->isRetryable()) {
                    $this->retryLater($collector, $cache, $batch, $result, $suspects);

                    return;
                }

                if ($result->isUnfinished()) {
                    $this->keepProgress($collector, $batch, $result, 0);
                    $this->release($collector, $suspects, 0);
                    $this->next(0);

                    return;
                }

                $collector->ack($batch);
                $answered = true;

                if ($result->isOk()) {
                    Stats::exported();
                }
            }

            // A tree that failed SEND_ALONE times is sent on its own, so it cannot hold the others back.
            foreach ($suspects as $i => $id) {
                $later = array_slice($suspects, $i + 1);
                $result = $this->export($exporter, [$trees[$id]], static::LIMIT - $this->elapsed($started));

                if ($result->isRetryable() && $answered) {
                    $this->failedAlone($collector, $id, $attempts[$id] ?? 0);

                    continue;
                }

                if ($result->isRetryable()) {
                    $this->retryLater($collector, $cache, [$id], $result, $later);

                    return;
                }

                if ($result->isUnfinished()) {
                    $this->keepProgress($collector, [$id], $result, 0);
                    $this->release($collector, $later, 0);
                    $this->next(0);

                    return;
                }

                $collector->ack([$id]);
                $answered = true;

                if ($result->isOk()) {
                    Stats::exported();
                }
            }

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
     * Ack the trees an export got through before it stopped, and give the rest back, due after the given seconds.
     *
     * A tree cut part way through its requests is acked too, and only its
     * unsent spans are stored again, as a tree of their own: sending it
     * whole again would restart from its first request every time, so a
     * tree too slow to send within one drain would never finish. The tree
     * is acked only once its unsent spans are stored: when the store
     * drops them (it is full) or fails, the whole tree goes back instead
     * and is sent again, so no span is lost.
     *
     * @param  list<array-key>  $ids
     */
    protected function keepProgress(Collector $collector, array $ids, ExportResult $result, int $delay, bool $failed = false): void
    {
        if ($result->done > 0 || $result->rest !== []) {
            Stats::exported();
        }

        $done = array_slice($ids, 0, $result->done);
        $rest = array_slice($ids, $result->done);

        if ($result->rest !== [] && $rest !== []) {
            try {
                if ($collector->push($result->rest)) {
                    $done[] = array_shift($rest);
                }
            } catch (Throwable $e) {
                Diagnostics::warn('collect.error', 'The unsent spans of a tree could not be stored ('.$e::class.'). The whole tree will be sent again.');
            }
        }

        $collector->ack($done);
        $this->release($collector, $rest, $delay, $failed);
    }

    /**
     * Give the given trees back, unless there are none.
     *
     * @param  list<array-key>  $ids
     */
    protected function release(Collector $collector, array $ids, int $delay, bool $failed = false): void
    {
        if ($ids !== []) {
            $collector->release($ids, $delay, $failed);
        }
    }

    /**
     * Split the claimed trees into the batch sent together and the trees that failed often enough to be sent alone, and get each one's failed attempts.
     *
     * @param  array<array-key, list<array<string, mixed>>>  $trees
     * @return array{list<array-key>, list<array-key>, array<array-key, int>}
     */
    protected function split(Collector $collector, array $trees): array
    {
        try {
            $attempts = $collector->attempts(array_keys($trees));
        } catch (Throwable) {
            $attempts = [];
        }

        $batch = [];
        $suspects = [];

        foreach (array_keys($trees) as $id) {
            if (($attempts[$id] ?? 0) >= self::SEND_ALONE) {
                $suspects[] = $id;
            } else {
                $batch[] = $id;
            }
        }

        return [$batch, $suspects, $attempts];
    }

    /**
     * Get the given trees, in the given order.
     *
     * @param  array<array-key, list<array<string, mixed>>>  $trees
     * @param  list<array-key>  $ids
     * @return list<list<array<string, mixed>>>
     */
    protected function only(array $trees, array $ids): array
    {
        return array_map(fn (int|string $id) => $trees[$id], $ids);
    }

    /**
     * Count a failed export of a tree sent alone while the destination answers others: give it back for later, or drop it once it failed DROP_AFTER times.
     */
    protected function failedAlone(Collector $collector, int|string $id, int $attempts): void
    {
        if ($attempts + 1 >= self::DROP_AFTER) {
            $collector->ack([$id]);

            Diagnostics::dropped('poison', 1, 'drain.poison', 'A run tree failed '.self::DROP_AFTER.' exports in a row while the destination took others, and was dropped.');

            return;
        }

        $collector->release([$id], self::ALONE_DELAY, failed: true);
    }

    /**
     * Get the seconds since the given monotonic time.
     */
    protected function elapsed(int|float $since): float
    {
        return (hrtime(true) - $since) / 1_000_000_000;
    }

    /**
     * Export the given trees. An exporter that throws drops them, so a tree that breaks it is never tried again.
     *
     * @param  list<list<array<string, mixed>>>  $trees
     */
    protected function export(Exporter $exporter, array $trees, float $within): ExportResult
    {
        try {
            $result = $exporter->export($trees, max(0.0, $within));
        } catch (Throwable $e) {
            Stats::failed();
            Diagnostics::dropped('error', count($trees), 'export.error', 'Spans could not be exported ('.$e::class.'). The batch was dropped.');

            return ExportResult::rejected();
        }

        if ($result->isRejected()) {
            Stats::failed();
            // The exporter warned with the destination's answer.
            Diagnostics::dropped('rejected', count($trees));
        }

        return $result;
    }

    /**
     * Release the given trees, each with one more failed attempt, and the untried ones, record the backoff for every drain, and dispatch the drain that tries them again.
     *
     * @param  list<array-key>  $ids
     * @param  list<array-key>  $untried
     */
    protected function retryLater(Collector $collector, Cache $cache, array $ids, ExportResult $result, array $untried = []): void
    {
        $delay = $this->failureDelay($cache, $result);

        // The trees the requests before the failing one delivered are not sent again.
        $this->keepProgress($collector, $ids, $result, $delay, failed: true);
        $this->release($collector, $untried, $delay);
        Stats::failed();

        Diagnostics::warn('drain.retry', "Spans could not be exported: the destination could not be reached or answered 408, 429 or 5xx. They are tried again in {$delay} seconds.");

        $this->next($delay);
    }

    /**
     * Record one more failed export in a row for every drain, and get the seconds to wait before the next try.
     *
     * The wait is the backoff of the failures in a row, or the Retry-After
     * when it is longer: Retry-After means "not before".
     */
    protected function failureDelay(Cache $cache, ExportResult $result): int
    {
        $failures = $this->backoffState($cache)[1] + 1;

        $delay = min(self::MAX_BACKOFF, max($result->retryAfter ?? 0, self::BACKOFF[min($failures, count(self::BACKOFF)) - 1]));

        $cache->put(self::BACKOFF_KEY, ['until' => $this->currentTime() + $delay, 'failures' => $failures], $delay + self::LOCK_MARGIN);

        return $delay;
    }

    /**
     * Get when the drain may export again after failed exports, as a Unix time, or null when it is not backing off.
     */
    public static function backoffUntil(Cache $cache): ?int
    {
        $backoff = $cache->get(self::BACKOFF_KEY);
        $until = is_array($backoff) ? ($backoff['until'] ?? null) : null;

        return is_int($until) && $until > Carbon::now()->getTimestamp() ? $until : null;
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
