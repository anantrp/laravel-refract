<?php

namespace Anantrp\Refract\Console;

use Anantrp\Refract\Contracts\Collector;
use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Contracts\ReportsStats;
use Anantrp\Refract\RefractServiceProvider;
use Anantrp\Refract\Support\Settings;
use Anantrp\Refract\Support\Stats;
use Anantrp\Refract\Transport\Drain;
use Anantrp\Refract\Transport\DrainTraces;
use Anantrp\Refract\Transport\ExportBreaker;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Says whether traces are being lost and why, and exits 1 when they are.
 *
 * Unhealthy means: the store of the queue drain cannot be read, the
 * oldest due tree in it has waited past Drain::STALL_AFTER (no worker
 * drains it), traces were dropped since the
 * last run of this command, or exports have failed for 10 minutes with
 * no success. Every section that fails is shown as unavailable;
 * the command itself never throws.
 */
class StatusCommand extends Command
{
    protected $signature = 'refract:status {--json : Print the status as one JSON object}';

    protected $description = 'Show whether Refract is delivering traces, and exit 1 when traces are being lost';

    /**
     * The seconds exports may keep failing, with no success, before the status is unhealthy.
     */
    public const FAILING_AFTER = 600;

    /**
     * The cache key of the drop total seen by the last run of this command.
     */
    protected const SEEN = 'refract:status:seen';

    public function handle(): int
    {
        $status = $this->status();

        if ($this->option('json')) {
            $this->line((string) json_encode($status, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $this->show($status);
        }

        return $status['healthy'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Gather the status.
     *
     * @return array{destination: string, drain: string, collector: ?string, trees: ?int, bytes: ?int, max_bytes: ?int, oldest_age: ?int, max_attempts: ?int, dropped: array<string, int>, paused_until: ?int, backoff_until: ?int, last_success: ?int, last_failure: ?int, durable: bool, cache: string, unavailable: array<string, string>, healthy: bool, problems: list<string>}
     */
    protected function status(): array
    {
        $drain = Settings::choice('drain', ['sync', 'queue'], 'sync');
        $stats = Stats::read();
        $now = Carbon::now()->getTimestamp();
        $problems = [];

        $status = [
            'destination' => $this->destination(),
            'drain' => $drain,
            'collector' => null,
            'trees' => null,
            'bytes' => null,
            'max_bytes' => null,
            'oldest_age' => null,
            'max_attempts' => null,
            'dropped' => $stats['dropped'],
            'paused_until' => null,
            'backoff_until' => null,
            'last_success' => $stats['last_success'],
            'last_failure' => $stats['last_failure'],
            'durable' => $stats['durable'],
            'cache' => Stats::driver(),
            'unavailable' => [],
        ];

        if ($drain === 'queue') {
            $status['collector'] = Settings::choice('collector', ['redis', 'database'], 'redis');

            try {
                /** @var Collector $collector */
                $collector = $this->laravel->make(Collector::class);

                if ($collector instanceof ReportsStats) {
                    $store = $collector->stats();

                    $status = [...$status, 'trees' => $store['trees'], 'bytes' => $store['bytes'], 'max_bytes' => $store['max_bytes'], 'oldest_age' => $store['oldest_age'], 'max_attempts' => $store['max_attempts']];
                }
            } catch (Throwable $e) {
                $status['unavailable']['collector'] = $e::class;
            }

            try {
                $status['backoff_until'] = DrainTraces::backoffUntil($this->laravel->make(Cache::class));
            } catch (Throwable $e) {
                $status['unavailable']['backoff'] = $e::class;
            }
        } else {
            try {
                $status['paused_until'] = (new ExportBreaker($this->laravel))->pausedUntil();
            } catch (Throwable $e) {
                $status['unavailable']['pause'] = $e::class;
            }
        }

        if (is_int($status['oldest_age']) && $status['oldest_age'] > Drain::STALL_AFTER) {
            $minutes = intdiv($status['oldest_age'], 60);
            $problems[] = "The oldest stored tree has waited {$minutes} minutes: no worker is draining the refract queue.";
        }

        if (isset($status['unavailable']['collector'])) {
            $problems[] = "The trace store could not be read ({$status['unavailable']['collector']}): pushed traces may be lost.";
        }

        $newDrops = $this->newDrops(array_sum($stats['dropped']));

        if ($newDrops > 0) {
            $problems[] = "{$newDrops} traces were dropped since the last check.";
        }

        // Measured from the first failure of the streak: each retry is a new, recent failure.
        $since = $stats['failing_since'];

        if ($since !== null && ($stats['last_success'] ?? 0) < $since && $now - $since > self::FAILING_AFTER) {
            $problems[] = 'No export has succeeded since '.$this->time($since).', when exports started failing.';
        }

        return [...$status, 'healthy' => $problems === [], 'problems' => $problems];
    }

    /**
     * Get the name of the destination, and whether it can send.
     */
    protected function destination(): string
    {
        $name = Settings::choice('destination', RefractServiceProvider::DESTINATIONS, 'otlp');

        try {
            $this->laravel->make(Exporter::class);

            return $name;
        } catch (Throwable) {
            return "{$name} (cannot send: check the warning logged at boot)";
        }
    }

    /**
     * Get the drops since the last run of this command, and remember the total for the next one.
     */
    protected function newDrops(int $total): int
    {
        try {
            $cache = $this->laravel->make(Cache::class);
            $seen = $cache->get(self::SEEN);
            $cache->forever(self::SEEN, $total);

            return max(0, $total - (is_int($seen) ? $seen : 0));
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Print the status as rows.
     *
     * @param  array{destination: string, drain: string, collector: ?string, trees: ?int, bytes: ?int, max_bytes: ?int, oldest_age: ?int, max_attempts: ?int, dropped: array<string, int>, paused_until: ?int, backoff_until: ?int, last_success: ?int, last_failure: ?int, durable: bool, cache: string, unavailable: array<string, string>, healthy: bool, problems: list<string>}  $status
     */
    protected function show(array $status): void
    {
        $unavailable = $status['unavailable'];
        $row = fn (string $label, string $value) => $this->components->twoColumnDetail($label, $value);

        $this->newLine();
        $row('Destination', $status['destination']);
        $row('Drain', $status['drain']);

        if ($status['drain'] === 'queue') {
            $row('Collector', (string) $status['collector']);

            if (isset($unavailable['collector'])) {
                $row('Stored', "unavailable ({$unavailable['collector']})");
            } elseif ($status['trees'] !== null) {
                $row('Stored', "{$status['trees']} trees, {$status['bytes']} of {$status['max_bytes']} bytes");
                $row('Oldest due tree', $status['oldest_age'] === null ? 'none' : "waited {$status['oldest_age']} seconds");
                $row('Most failed attempts', (string) $status['max_attempts']);
            }

            $row('Backing off until', isset($unavailable['backoff']) ? "unavailable ({$unavailable['backoff']})" : $this->time($status['backoff_until']));
        } else {
            $row('Paused until', isset($unavailable['pause']) ? "unavailable ({$unavailable['pause']})" : $this->time($status['paused_until']));
        }

        $dropped = $status['dropped'];
        $row('Dropped', $dropped === [] ? 'none' : implode(', ', array_map(fn (string $reason, int $count) => "{$reason} {$count}", array_keys($dropped), $dropped)));
        $row('Last success', $this->time($status['last_success'], 'never'));
        $row('Last failure', $this->time($status['last_failure'], 'never'));
        $this->newLine();

        if (! $status['durable']) {
            $this->components->warn("Counts are kept per process (cache store \"{$status['cache']}\"), so they are not complete.");
        }

        $problems = $status['problems'];

        if ($problems === []) {
            $this->components->info('Healthy.');
        }

        foreach ($problems as $problem) {
            $this->components->error($problem);
        }
    }

    /**
     * Format the given Unix time in the app's timezone.
     */
    protected function time(mixed $time, string $none = 'no'): string
    {
        $timezone = config('app.timezone');

        return is_int($time) ? Carbon::createFromTimestamp($time, is_string($timezone) ? $timezone : 'UTC')->toDateTimeString() : $none;
    }
}
