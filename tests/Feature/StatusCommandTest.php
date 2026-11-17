<?php

use Anantrp\Refract\Contracts\Collector;
use Anantrp\Refract\Contracts\ReportsStats;
use Anantrp\Refract\Support\Stats;
use Anantrp\Refract\Transport\Collectors\DatabaseCollector;
use Anantrp\Refract\Transport\ExportBreaker;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * php artisan refract:status says whether traces are being lost and why,
 * and exits 1 when they are, so a cron or a deploy check can alert.
 */

beforeEach(function () {
    Schema::dropIfExists(DatabaseCollector::TABLE);
    $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
});

/**
 * Use the queue drain with the database collector, on a cache store that every process shares.
 */
function queueDrain(): void
{
    test()->refreshApplicationWithConfig([
        'refract.drain' => 'queue',
        'refract.collector' => 'database',
        'cache.default' => 'file',
        'cache.stores.file.path' => sys_get_temp_dir().'/refract-status-'.getmypid(),
    ]);
    app(Cache::class)->getStore()->flush();
}

/**
 * Run refract:status with --json and get what it printed, decoded.
 *
 * @return array<string, mixed>
 */
function statusJson(): array
{
    Artisan::call('refract:status', ['--json' => true]);

    return json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
}

it('healthy queue drain exits 0', function () {
    queueDrain();
    app(Collector::class)->push(storedTree('one'));
    Stats::exported();

    $this->artisan('refract:status')
        ->expectsOutputToContain('queue')
        ->expectsOutputToContain('database')
        ->expectsOutputToContain('1 trees')
        ->expectsOutputToContain('Most failed attempts')
        ->expectsOutputToContain('Healthy')
        ->assertExitCode(0);
});

it('with no worker, status exits 1 once the oldest tree passes 10 minutes', function () {
    queueDrain();
    app(Collector::class)->push(storedTree('one'));

    $this->travel(599)->seconds();
    $this->artisan('refract:status')->assertExitCode(0);

    $this->travel(2)->seconds();
    $this->artisan('refract:status')
        ->expectsOutputToContain('no worker is draining the refract queue')
        ->assertExitCode(1);
});

it('new drops since the last check exit 1, then 0 on the next run', function () {
    queueDrain();
    Stats::dropped('full', 2);

    $this->artisan('refract:status')
        ->expectsOutputToContain('full 2')
        ->expectsOutputToContain('2 traces were dropped since the last check')
        ->assertExitCode(1);

    $this->artisan('refract:status')->assertExitCode(0);

    Stats::dropped('paused', 1);

    $this->artisan('refract:status')->assertExitCode(1);
});

it('exports failing for over 10 minutes exit 1, and one success clears it', function () {
    queueDrain();
    Stats::exported();
    $this->travel(5)->seconds();
    Stats::failed();

    $this->travel(5)->minutes();
    $this->artisan('refract:status')->assertExitCode(0);

    $this->travel(6)->minutes();
    $this->artisan('refract:status')
        ->expectsOutputToContain('No export has succeeded since')
        ->assertExitCode(1);

    Stats::exported();
    $this->artisan('refract:status')->assertExitCode(0);
});

it('status on the sync drain does not make a collector', function () {
    $this->refreshApplicationWithConfig(['refract.drain' => 'sync']);
    app()->bind(Collector::class, fn () => throw new RuntimeException('Redis is not there.'));

    (new ExportBreaker(app()))->failed();

    $this->artisan('refract:status')
        ->expectsOutputToContain('sync')
        ->expectsOutputToContain('Paused until')
        ->expectsOutputToContain('Last success')
        ->doesntExpectOutputToContain('Redis is not there')
        ->assertExitCode(0);
});

it('a collector that fails is shown as unavailable, and status still answers', function () {
    queueDrain();
    app()->instance(Collector::class, $collector = Mockery::mock(Collector::class, ReportsStats::class));
    $collector->shouldReceive('stats')->andThrow(new RuntimeException('down'));

    $this->artisan('refract:status')
        ->expectsOutputToContain('unavailable (RuntimeException)')
        ->assertExitCode(0);
});

it('status warns that counters are per process when the cache is array', function () {
    $this->refreshApplicationWithConfig(['cache.default' => 'array']);

    $this->artisan('refract:status')
        ->expectsOutputToContain('Counts are kept per process (cache store "array"), so they are not complete.')
        ->assertExitCode(0);
});

it('--json is one parseable object', function () {
    queueDrain();
    app(Collector::class)->push(storedTree('one'));
    Stats::dropped('full', 1);

    $status = statusJson();

    expect($status)->toHaveKeys(['destination', 'drain', 'collector', 'trees', 'bytes', 'max_bytes', 'oldest_age', 'max_attempts', 'dropped', 'paused_until', 'backoff_until', 'last_success', 'last_failure', 'durable', 'healthy', 'problems'])
        ->and($status['drain'])->toBe('queue')
        ->and($status['trees'])->toBe(1)
        ->and($status['max_attempts'])->toBe(0)
        ->and($status['dropped'])->toBe(['full' => 1])
        ->and($status['healthy'])->toBeFalse()
        ->and($status['durable'])->toBeTrue()
        ->and(DB::table(DatabaseCollector::TABLE)->count())->toBe(1);
});
