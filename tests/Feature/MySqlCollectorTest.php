<?php

use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Tests\Support\WarningLog;
use Anantrp\Refract\Transport\Collectors\DatabaseCollector;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

pest()->group('mysql');

/*
 * The database collector against a real MySQL 8+, where a claim locks
 * rows with SELECT ... FOR UPDATE SKIP LOCKED. Set REFRACT_TEST_MYSQL_HOST,
 * _PORT, _DATABASE, _USERNAME and _PASSWORD to run it; it is skipped when
 * the host is not set. The tests create and drop the refract_traces table
 * in that database.
 */

/**
 * Configure the MySQL connections the tests use, or skip the test when no MySQL is given.
 *
 * Each name is a connection of its own, with its own PDO, as two workers would have.
 */
function mysqlConnections(string ...$names): void
{
    $host = getenv('REFRACT_TEST_MYSQL_HOST');

    if (! is_string($host) || $host === '') {
        test()->markTestSkipped('REFRACT_TEST_MYSQL_HOST is not set.');
    }

    foreach ($names as $name) {
        config(["database.connections.{$name}" => [
            'driver' => 'mysql',
            'host' => $host,
            'port' => getenv('REFRACT_TEST_MYSQL_PORT') ?: '3306',
            'database' => getenv('REFRACT_TEST_MYSQL_DATABASE') ?: 'refract_test',
            'username' => getenv('REFRACT_TEST_MYSQL_USERNAME') ?: 'root',
            'password' => getenv('REFRACT_TEST_MYSQL_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ]]);
    }
}

/**
 * Make a database collector on the given MySQL connection.
 */
function mysqlCollector(string $name = 'refract_mysql', int $maxBytes = DatabaseCollector::MAX_BYTES): DatabaseCollector
{
    $connection = DB::connection($name);

    assert($connection instanceof Connection);

    return new DatabaseCollector($connection, $maxBytes);
}

beforeEach(function () {
    mysqlConnections('refract_mysql', 'refract_mysql_other');

    config(['refract.collectors.database.connection' => 'refract_mysql']);

    Schema::connection('refract_mysql')->dropIfExists(DatabaseCollector::TABLE);

    // The migration runs on the collector connection; its record stays in the default SQLite database.
    $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');

    Diagnostics::reset();
    Log::swap($this->log = new WarningLog);
});

itBehavesLikeAStoredCollector(fn (int $maxBytes) => mysqlCollector(maxBytes: $maxBytes));

it('claims on MySQL lock with FOR UPDATE SKIP LOCKED', function () {
    $lock = (fn () => $this->lockForClaim())->call(mysqlCollector());

    expect($lock)->toBe('FOR UPDATE SKIP LOCKED');
});

it('two claims at once never get the same trees: the second skips the rows the first holds', function () {
    $first = mysqlCollector();
    $second = mysqlCollector('refract_mysql_other');

    foreach (['one', 'two', 'three'] as $name) {
        $first->push(storedTree($name));
    }

    // Without SKIP LOCKED the second claim would wait for the first: fail fast instead of hanging.
    DB::connection('refract_mysql_other')->statement('SET SESSION innodb_lock_wait_timeout = 2');

    // The first claim takes one tree and keeps its row locked, as a worker still inside the claim would.
    DB::connection('refract_mysql')->beginTransaction();

    $held = $first->claim(1, 60);

    $taken = $second->claim(4_000_000, 60);

    DB::connection('refract_mysql')->commit();

    expect(storedNames($held))->toBe(['one'])
        ->and(storedNames($taken))->toBe(['two', 'three'])
        ->and(array_intersect(array_keys($held), array_keys($taken)))->toBe([])
        ->and($first->claim(4_000_000, 60))->toBe([])
        ->and($first->size())->toBe(3);
});

it('a claim that finds every due row locked by another claim gets nothing, without waiting', function () {
    $first = mysqlCollector();
    $second = mysqlCollector('refract_mysql_other');

    $first->push(storedTree('one'));

    DB::connection('refract_mysql_other')->statement('SET SESSION innodb_lock_wait_timeout = 2');
    DB::connection('refract_mysql')->beginTransaction();

    $held = $first->claim(4_000_000, 60);
    $started = microtime(true);
    $taken = $second->claim(4_000_000, 60);
    $waited = microtime(true) - $started;

    DB::connection('refract_mysql')->commit();

    expect(storedNames($held))->toBe(['one'])
        ->and($taken)->toBe([])
        ->and($waited)->toBeLessThan(1.0);
});
