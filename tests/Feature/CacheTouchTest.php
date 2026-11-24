<?php

use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Support\Stats;
use Anantrp\Refract\Tests\Support\WarningLog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
 * Laravel's default cache store is the database. Refract's counts and
 * warnings never write to it inside the app's open transaction, where a
 * row lock would be held until the app commits, and a warning repeated in
 * one process does not go to the cache each time.
 */

beforeEach(function () {
    Schema::dropIfExists('cache');
    Schema::create('cache', function (Blueprint $table) {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });

    config(['cache.default' => 'database', 'cache.stores.database' => ['driver' => 'database', 'table' => 'cache', 'connection' => null]]);
    Log::swap($this->log = new WarningLog);

    $this->cacheQueries = [];
    DB::listen(function ($query) {
        if (str_contains($query->sql, '"cache"')) {
            $this->cacheQueries[] = [$query->sql, DB::transactionLevel()];
        }
    });
});

afterEach(function () {
    Schema::dropIfExists('cache');
});

it('inside an open transaction, a drop and a warning do not touch a database cache, and the count is written after', function () {
    DB::transaction(function () {
        Diagnostics::dropped('run_cap', 3, 'run.truncated', 'A run reached 1000 spans.');
    });

    expect(array_filter($this->cacheQueries, fn (array $query) => $query[1] > 0))->toBe([])
        ->and($this->log->warnings)->toBe(['[refract] A run reached 1000 spans.']);

    Stats::flush();

    expect(Stats::read()['dropped'])->toBe(['run_cap' => 3])
        ->and(Stats::read()['durable'])->toBeTrue();
});

it('a warning repeated in one process goes to the cache once per window', function () {
    foreach (range(1, 100) as $i) {
        Diagnostics::warn('otlp.endpoint', 'No OTLP endpoint is set.');
    }

    expect($this->log->warnings)->toHaveCount(1)
        // One claim of the window, a few queries on the database store; it was 500.
        ->and(count($this->cacheQueries))->toBeLessThanOrEqual(5);
});

it('the warnings another process held back are still counted', function () {
    Diagnostics::warn('collect.full', 'The store is full.');

    // Another process, in the same window.
    Diagnostics::reset();
    foreach (range(1, 4) as $i) {
        Diagnostics::warn('collect.full', 'The store is full.');
    }

    $this->travel(601)->seconds();
    Diagnostics::reset();
    Diagnostics::warn('collect.full', 'The store is full.');

    expect($this->log->warnings)->toBe(['[refract] The store is full.', '[refract] The store is full. (1 more since the last warning)']);
});
