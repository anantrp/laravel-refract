<?php

use Anantrp\Refract\Contracts\Transport;
use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Tests\Support\WarningLog;
use Anantrp\Refract\Transport\ExportSpans;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/*
 * Rule 8: retry on a network error, 408, 429 and 5xx; drop every other
 * status with one warning. The queue job tries 3 times, 10 s apart.
 */

/**
 * Boot Refract with a working Langfuse destination and the given transport, and record warnings.
 */
function bootExport(string $transport): WarningLog
{
    test()->refreshApplicationWithConfig([
        'refract.transport' => $transport,
        'refract.destination' => 'langfuse',
        'refract.destinations.langfuse.public_key' => 'pk-test',
        'refract.destinations.langfuse.secret_key' => 'sk-test',
    ]);

    Diagnostics::reset();
    Log::swap($log = new WarningLog);

    // No test here may reach a real destination.
    Http::preventStrayRequests();

    return $log;
}

/**
 * Send one neutral run span through the configured transport.
 *
 * @param  array<string, mixed>  $call
 */
function sendSpan(array $call = []): void
{
    app(Transport::class)->send([[
        'v' => 1, 'trace_id' => str_repeat('a', 32), 'span_id' => bin2hex(random_bytes(8)), 'parent_span_id' => null,
        'kind' => 'invoke_agent', 'start' => 1, 'end' => 2, 'status' => 'ok', 'status_message' => null,
        'call' => ['agent' => 'TimeAgent', ...$call], 'content' => [], 'context' => [], 'events' => [],
    ]]);
}

/**
 * Use the database queue on the test database, with an empty jobs table and no failed job store.
 */
function useExportQueue(): void
{
    config([
        'queue.default' => 'database',
        'queue.connections.database' => [
            'driver' => 'database', 'connection' => 'testing', 'table' => 'jobs',
            'queue' => 'default', 'retry_after' => 90, 'after_commit' => false,
        ],
        'queue.failed.driver' => 'null',
    ]);

    Schema::dropIfExists('jobs');
    Schema::create('jobs', function (Blueprint $table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
}

/**
 * Run the next available job on the database queue the way a worker does.
 */
function workExportJob(): void
{
    app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(sleep: 0));
}

/**
 * Fake every request to the destination with the given status, or a failed connection for "network".
 */
function fakeDestination(int|string $status, string $body = ''): void
{
    Http::fake(fn () => $status === 'network' ? Http::failedConnection() : Http::response($body, (int) $status));
}

dataset('retryable', ['408' => 408, '429' => 429, '500' => 500, '503' => 503, 'network error' => 'network']);
dataset('rejected', ['400' => 400, '401' => 401, '403' => 403, '404' => 404]);

it('E1: a 2xx is done with no log', function (string $transport, int $status) {
    $log = bootExport($transport);
    fakeDestination($status);
    useExportQueue();

    sendSpan();

    if ($transport === 'queue') {
        workExportJob();

        expect(DB::table('jobs')->count())->toBe(0);
    }

    Http::assertSentCount(1);
    expect($log->warnings)->toBe([]);
})->with(['sync', 'queue'])->with([200, 202, 204]);

it('E2: a 400, 401, 403 or 404 is not retried and logs one warning with the status and the first 200 chars of the body', function (string $transport, int $status) {
    $log = bootExport($transport);
    fakeDestination($status, str_repeat('é', 200).'TAIL');
    useExportQueue();

    sendSpan();

    if ($transport === 'queue') {
        workExportJob();

        expect(DB::table('jobs')->count())->toBe(0);

        $this->travel(60)->seconds();
        workExportJob();
    }

    sendSpan();

    Http::assertSentCount($transport === 'queue' ? 1 : 2);
    expect($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain((string) $status)
        ->and($log->warnings[0])->toContain(str_repeat('é', 200))
        ->and($log->warnings[0])->not->toContain('TAIL')
        ->and($log->warnings[0])->not->toContain('sk-test')
        ->and($log->warnings[0])->not->toContain('pk-test');
})->with(['sync', 'queue'])->with('rejected');

it('E2: an HTTP client error that is not a network error is dropped with one warning, nothing reaches the app', function (string $transport, string $error) {
    $log = bootExport($transport);

    if ($error === 'exception from the client') {
        Http::fake(fn () => throw new RuntimeException('boom'));
    }

    useExportQueue();

    sendSpan();

    if ($transport === 'queue') {
        workExportJob();

        expect(DB::table('jobs')->count())->toBe(0);
    }

    expect($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('dropped')
        ->and($log->warnings[0])->toContain($error === 'stray request' ? 'StrayRequestException' : 'RuntimeException');
})->with(['sync', 'queue'])->with(['exception from the client', 'stray request']);

it('E2: a request exception that carries a response is judged by its status', function () {
    $log = bootExport('sync');
    Http::fake(fn () => throw new RequestException(new Response(new GuzzleHttp\Psr7\Response(401, [], 'no key'))));

    sendSpan();

    expect($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('401')
        ->and($log->warnings[0])->toContain('no key');
});

it('E3: a 408, 429, 5xx or network error on queue is retried, 3 tries, 10 s apart', function (int|string $status) {
    $log = bootExport('queue');
    fakeDestination($status);
    useExportQueue();

    sendSpan();

    workExportJob();

    Http::assertSentCount(1);
    expect(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->value('available_at') - now()->getTimestamp())->toBe(10)
        ->and($log->warnings)->toBe([]);

    $this->travel(9)->seconds();
    workExportJob();

    Http::assertSentCount(1);

    $this->travel(1)->seconds();
    workExportJob();

    Http::assertSentCount(2);
    expect($log->warnings)->toBe([]);

    $this->travel(10)->seconds();
    workExportJob();

    Http::assertSentCount(3);
    expect(DB::table('jobs')->count())->toBe(0)
        ->and($log->warnings)->toHaveCount(1);

    $this->travel(60)->seconds();
    workExportJob();

    Http::assertSentCount(3);
})->with('retryable');

it('E3: a queued batch that succeeds on a later try logs nothing', function () {
    $log = bootExport('queue');
    Http::fakeSequence()->push('', 503)->push('', 200);
    useExportQueue();

    sendSpan();

    workExportJob();
    $this->travel(10)->seconds();
    workExportJob();

    Http::assertSentCount(2);
    expect(DB::table('jobs')->count())->toBe(0)
        ->and($log->warnings)->toBe([]);
});

it('E4: a 408, 429, 5xx or network error on sync is not retried and logs one warning', function (int|string $status) {
    $log = bootExport('sync');
    fakeDestination($status);

    sendSpan();

    Http::assertSentCount(1);
    expect($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('dropped');

    sendSpan();

    Http::assertSentCount(2);
    expect($log->warnings)->toHaveCount(1);
})->with('retryable');

it('E4: the queue transport exporting in this process does not retry and logs one warning, like sync', function (Closure $setup, Closure $call, int $queueWarnings) {
    $log = bootExport('queue');
    fakeDestination(503);
    useExportQueue();
    $setup();

    sendSpan($call());

    Http::assertSentCount(1);
    expect(DB::table('jobs')->count())->toBe(0)
        ->and($log->warnings)->toHaveCount($queueWarnings + 1)
        ->and(end($log->warnings))->toContain('dropped');
})->with([
    'sync driver' => [fn () => config(['queue.connections.database.driver' => 'sync']), fn () => [], 0],
    'dispatch fails' => [fn () => config(['queue.connections.database.table' => 'missing_jobs']), fn () => [], 1],
    // Random text does not compress much: well over 256 KB gzipped.
    'too big for the queue' => [fn () => null, fn () => ['padding' => bin2hex(random_bytes(200_000))], 1],
]);

it('E5: a queue job that gives up logs one warning from failed() and is not sent to the error tracker', function () {
    $log = bootExport('queue');
    Exceptions::fake();
    fakeDestination(503);
    useExportQueue();

    $failed = 0;
    Event::listen(JobFailed::class, function () use (&$failed) {
        $failed++;
    });

    sendSpan();

    foreach (range(1, 3) as $try) {
        workExportJob();
        $this->travel(10)->seconds();
    }

    Http::assertSentCount(3);
    Exceptions::assertNothingReported();
    expect($failed)->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('3 tries');
});

it('E5: failed() called by the queue logs one warning and does not throw', function () {
    $log = bootExport('queue');

    $job = new ExportSpans('');
    $job->failed(new RuntimeException('Max attempts exceeded.'));
    $job->failed(null);

    expect($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('3 tries')
        ->and($log->warnings[0])->toContain('dropped')
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe(10);
});

it('E6: a 401 and then an outage in one process give two different warnings', function (string $transport) {
    $log = bootExport($transport);
    Http::fakeSequence()->push('bad key', 401)->whenEmpty(Http::response('down', 503));
    useExportQueue();

    sendSpan();
    sendSpan();

    if ($transport === 'queue') {
        foreach (range(1, 4) as $try) {
            workExportJob();
            $this->travel(10)->seconds();
        }

        Http::assertSentCount(4);
    }

    expect($log->warnings)->toHaveCount(2)
        ->and($log->warnings[0])->toContain('401')
        ->and($log->warnings[1])->not->toBe($log->warnings[0]);
})->with(['sync', 'queue']);

it('E2: the body excerpt in the warning leaves out header lines and credentials the destination echoed', function () {
    $log = bootExport('sync');
    fakeDestination(401, implode("\n", [
        '{"error":"bad key","authorization":"Basic cGstdGVzdDpzay10ZXN0"}',
        'Authorization: Basic SEVBREVSLVNFQ1JFVA==',
        'X-Api-Key: KEY-SECRET',
        'token Bearer TOKEN-SECRET.abc',
        "auth 'Basic QkFTSUMtU0VDUkVU'",
    ]));

    sendSpan();

    expect($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('401')
        ->and($log->warnings[0])->toContain('bad key');

    foreach (['cGstdGVzdDpzay10ZXN0', 'SEVBREVSLVNFQ1JFVA', 'KEY-SECRET', 'TOKEN-SECRET', 'QkFTSUMtU0VDUkVU', 'X-Api-Key'] as $secret) {
        expect($log->warnings[0])->not->toContain($secret);
    }
});

it('E5: failed() called by the queue for another cause names that cause, not a network error or status', function () {
    $log = bootExport('queue');

    (new ExportSpans(''))->failed(new RuntimeException('The job timed out.'));

    expect($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain(RuntimeException::class)
        ->and($log->warnings[0])->not->toContain('408')
        ->and($log->warnings[0])->not->toContain('could not be reached')
        ->and($log->warnings[0])->not->toContain('timed out');
});

it('E3: a batch that cannot be retried because the export job is not on a queue is dropped with one warning', function () {
    $log = bootExport('queue');
    fakeDestination(503);

    $job = ExportSpans::of([[
        'v' => 1, 'trace_id' => str_repeat('a', 32), 'span_id' => str_repeat('b', 16), 'parent_span_id' => null,
        'kind' => 'invoke_agent', 'start' => 1, 'end' => 2, 'status' => 'ok', 'status_message' => null,
        'call' => [], 'content' => [], 'context' => [], 'events' => [],
    ]]);

    expect($job)->not->toBeNull();

    $job?->handle(app());

    Http::assertSentCount(1);
    expect($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('dropped');
});
