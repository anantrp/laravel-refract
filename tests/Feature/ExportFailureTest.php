<?php

use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Contracts\Transport;
use Anantrp\Refract\Export\GenAiTranslator;
use Anantrp\Refract\Export\HttpExporter;
use Anantrp\Refract\Export\OtlpJson;
use Anantrp\Refract\Export\PlatformFactory;
use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Tests\Support\Otlp;
use Anantrp\Refract\Tests\Support\WarningLog;
use Anantrp\Refract\Transport\ExportSpans;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
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
 * Retry on a network error, 408, 429 and 5xx; drop every other
 * status with one warning. The queue job tries 4 times, 10 s, 60 s then
 * 300 s apart, or after the Retry-After seconds of a 429 or 503 (at most 300).
 */

/**
 * Boot Refract with a working Langfuse destination and the given transport, and record warnings.
 *
 * @param  array<string, mixed>  $config
 */
function bootExport(string $transport, array $config = []): WarningLog
{
    test()->refreshApplicationWithConfig([
        'refract.transport' => $transport,
        'refract.destination' => 'langfuse',
        'refract.destinations.langfuse.public_key' => 'pk-test',
        'refract.destinations.langfuse.secret_key' => 'sk-test',
        'refract.destinations.otlp.endpoint' => 'https://otlp.test/v1/traces',
        ...$config,
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

/**
 * Fake every request to the destination with the given status and Retry-After header.
 */
function fakeRetryAfter(int $status, string $retryAfter): void
{
    Http::fake(fn () => Http::response('', $status, ['Retry-After' => $retryAfter]));
}

/**
 * Get the seconds until the queued export job is available again.
 */
function secondsUntilRetry(): int
{
    return DB::table('jobs')->value('available_at') - now()->getTimestamp();
}

dataset('retryable', ['408' => 408, '429' => 429, '500' => 500, '503' => 503, 'network error' => 'network']);
dataset('rejected', ['400' => 400, '401' => 401, '403' => 403, '404' => 404]);

it('a 2xx is done with no log', function (string $transport, int $status) {
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

it('a 2xx that refuses some spans is done, not retried, and logs one warning with the count and the message', function (string $transport, string $body, string $count, string $message) {
    $log = bootExport($transport);
    fakeDestination(200, $body);
    useExportQueue();

    sendSpan();

    if ($transport === 'queue') {
        workExportJob();

        expect(DB::table('jobs')->count())->toBe(0);
    }

    Http::assertSentCount(1);
    expect($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain($count)
        ->and($log->warnings[0])->toContain($message);
})->with(['sync', 'queue'])->with([
    'count and message' => ['{"partialSuccess":{"rejectedSpans":2,"errorMessage":"test reject"}}', '2 span', 'test reject'],
    'count as an int64 string' => ['{"partialSuccess":{"rejectedSpans":"3","errorMessage":"too old"}}', '3 span', 'too old'],
    'count only' => ['{"partialSuccess":{"rejectedSpans":5}}', '5 span', 'no message'],
    'count too big for an int' => ['{"partialSuccess":{"rejectedSpans":99999999999999999999}}', (string) PHP_INT_MAX.' span', 'no message'],
    'message only' => ['{"partialSuccess":{"errorMessage":"slow down"}}', '0 span', 'slow down'],
]);

it('the partial success message leaves out credentials the destination echoed', function () {
    $log = bootExport('sync');
    fakeDestination(200, json_encode(['partialSuccess' => [
        'rejectedSpans' => 1,
        'errorMessage' => 'bad span, Authorization: Bearer TOKEN-SECRET.abc, api_key=K1-SECRET',
    ]]));

    sendSpan();

    expect($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('bad span')
        ->and($log->warnings[0])->not->toContain('TOKEN-SECRET')
        ->and($log->warnings[0])->not->toContain('K1-SECRET');
});

it('a 2xx with no refused spans logs nothing', function (string $body) {
    $log = bootExport('sync');
    fakeDestination(200, $body);

    sendSpan();

    Http::assertSentCount(1);
    expect($log->warnings)->toBe([]);
})->with([
    'empty object' => '{}',
    'empty body' => '',
    'not JSON' => 'ok',
    'a JSON list' => '[1]',
    'zero and no message' => '{"partialSuccess":{"rejectedSpans":0}}',
    'zero and an empty message' => '{"partialSuccess":{"rejectedSpans":"0","errorMessage":""}}',
    'empty partial success' => '{"partialSuccess":{}}',
    'partial success not an object' => '{"partialSuccess":"yes"}',
    'count not a number' => '{"partialSuccess":{"rejectedSpans":"many"}}',
    'negative count' => '{"partialSuccess":{"rejectedSpans":-1}}',
]);

it('a 400, 401, 403 or 404 is not retried and logs one warning with the status and the first 200 chars of the body', function (string $transport, int $status) {
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

it('an HTTP client error that is not a network error is dropped with one warning, nothing reaches the app', function (string $transport, string $error) {
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

it('a request exception that carries a response is judged by its status', function () {
    $log = bootExport('sync');
    Http::fake(fn () => throw new RequestException(new Response(new GuzzleHttp\Psr7\Response(401, [], 'no key'))));

    sendSpan();

    expect($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('401')
        ->and($log->warnings[0])->toContain('no key');
});

it('a 408, 429, 5xx or network error on queue is retried, 4 tries, 10 s, 60 s then 300 s apart', function (int|string $status) {
    $log = bootExport('queue');
    // The backoff is checked to the second: a clock tick during the test must not change it.
    $this->freezeTime();
    fakeDestination($status);
    useExportQueue();

    sendSpan();

    workExportJob();

    Http::assertSentCount(1);
    expect(DB::table('jobs')->count())->toBe(1)
        ->and(secondsUntilRetry())->toBe(10)
        ->and($log->warnings)->toBe([]);

    $this->travel(9)->seconds();
    workExportJob();

    Http::assertSentCount(1);

    $this->travel(1)->seconds();
    workExportJob();

    Http::assertSentCount(2);
    expect(secondsUntilRetry())->toBe(60)
        ->and($log->warnings)->toBe([]);

    $this->travel(59)->seconds();
    workExportJob();

    Http::assertSentCount(2);

    $this->travel(1)->seconds();
    workExportJob();

    Http::assertSentCount(3);
    expect(secondsUntilRetry())->toBe(300)
        ->and($log->warnings)->toBe([]);

    $this->travel(299)->seconds();
    workExportJob();

    Http::assertSentCount(3);

    $this->travel(1)->seconds();
    workExportJob();

    Http::assertSentCount(4);
    expect(DB::table('jobs')->count())->toBe(0)
        ->and($log->warnings)->toHaveCount(1);

    $this->travel(300)->seconds();
    workExportJob();

    Http::assertSentCount(4);
})->with('retryable');

it('a queued batch that succeeds on a later try logs nothing', function () {
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

it('a 429 or 503 with Retry-After in seconds on queue waits that long when it is longer than the backoff', function (int $status) {
    $log = bootExport('queue');
    $this->freezeTime();
    fakeRetryAfter($status, '30');
    useExportQueue();

    sendSpan();

    workExportJob();

    expect(secondsUntilRetry())->toBe(30);

    $this->travel(29)->seconds();
    workExportJob();

    Http::assertSentCount(1);

    $this->travel(1)->seconds();
    workExportJob();

    // On the second try the backoff of 60 s is longer than the 30 s asked: the longer wait wins.
    Http::assertSentCount(2);
    expect(secondsUntilRetry())->toBe(60)
        ->and($log->warnings)->toBe([]);
})->with(['429' => 429, '503' => 503]);

it('a Retry-After shorter than the backoff waits the backoff of the attempt', function (string $retryAfter) {
    bootExport('queue');
    $this->freezeTime();
    fakeRetryAfter(503, $retryAfter);
    useExportQueue();

    sendSpan();

    workExportJob();

    expect(secondsUntilRetry())->toBe(10);
})->with(['0' => '0', '1' => '1', '3' => '3', '9' => '9']);

it('a Retry-After of 30 s on the third try waits the 300 s backoff', function () {
    bootExport('queue');
    $this->freezeTime();
    fakeRetryAfter(429, '30');
    useExportQueue();

    sendSpan();

    workExportJob();
    $this->travel(30)->seconds();
    workExportJob();
    $this->travel(60)->seconds();
    workExportJob();

    Http::assertSentCount(3);
    expect(secondsUntilRetry())->toBe(300);
});

it('a Retry-After over 300 s waits 300 s', function (string $retryAfter) {
    bootExport('queue');
    $this->freezeTime();
    fakeRetryAfter(429, $retryAfter);
    useExportQueue();

    sendSpan();

    workExportJob();

    expect(secondsUntilRetry())->toBe(300);
})->with(['301', '3600', '99999999999999999999999']);

it('a Retry-After that is not whole seconds, or on another status, is ignored and the backoff is used', function (int $status, string $retryAfter) {
    bootExport('queue');
    $this->freezeTime();
    fakeRetryAfter($status, $retryAfter);
    useExportQueue();

    sendSpan();

    workExportJob();

    expect(secondsUntilRetry())->toBe(10);
})->with([
    'HTTP date' => [503, 'Wed, 21 Oct 2026 07:28:00 GMT'],
    'negative' => [429, '-5'],
    'text' => [429, 'soon'],
    'fraction' => [503, '1.5'],
    'empty' => [503, ''],
    'on 500' => [500, '3'],
    'on 408' => [408, '3'],
]);

it('a Retry-After of 0 s on every try still waits the backoff, so a batch survives an outage of a few minutes', function () {
    $log = bootExport('queue');
    $this->freezeTime();
    fakeRetryAfter(503, '0');
    useExportQueue();

    sendSpan();

    foreach ([10, 60, 300] as $wait) {
        workExportJob();

        expect(secondsUntilRetry())->toBe($wait);

        workExportJob();
        $this->travel($wait)->seconds();
    }

    Http::assertSentCount(3);
    expect(DB::table('jobs')->count())->toBe(1)
        ->and($log->warnings)->toBe([]);
});

it('a Retry-After on sync is not waited for: no retry and one warning, like any failed sync send', function (int $status) {
    $log = bootExport('sync');
    fakeRetryAfter($status, '3');

    sendSpan();

    Http::assertSentCount(1);
    expect($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('dropped');
})->with(['429' => 429, '503' => 503]);

it('a 408, 429, 5xx or network error on sync is not retried and logs one warning', function (int|string $status) {
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

it('the queue transport exporting in this process does not retry and logs one warning, like sync', function (Closure $setup, Closure $call, int $queueWarnings) {
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

it('a queue job that gives up logs one warning from failed() and is not sent to the error tracker', function () {
    $log = bootExport('queue');
    Exceptions::fake();
    fakeDestination(503);
    useExportQueue();

    $failed = 0;
    Event::listen(JobFailed::class, function () use (&$failed) {
        $failed++;
    });

    sendSpan();

    foreach ([10, 60, 300, 300] as $wait) {
        workExportJob();
        $this->travel($wait)->seconds();
    }

    Http::assertSentCount(4);
    Exceptions::assertNothingReported();
    expect($failed)->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('4 tries');
});

it('failed() called by the queue logs one warning and does not throw', function () {
    $log = bootExport('queue');

    $job = new ExportSpans('');
    $job->failed(new RuntimeException('Max attempts exceeded.'));
    $job->failed(null);

    expect($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('4 tries')
        ->and($log->warnings[0])->toContain('dropped')
        ->and($job->tries)->toBe(4)
        ->and($job->backoff)->toBe([10, 60, 300]);
});

it('a 401 and then an outage in one process give two different warnings', function (string $transport) {
    $log = bootExport($transport);
    Http::fakeSequence()->push('bad key', 401)->whenEmpty(Http::response('down', 503));
    useExportQueue();

    sendSpan();
    sendSpan();

    if ($transport === 'queue') {
        foreach ([0, 10, 60, 300, 300] as $wait) {
            workExportJob();
            $this->travel($wait)->seconds();
        }

        Http::assertSentCount(5);
    }

    expect($log->warnings)->toHaveCount(2)
        ->and($log->warnings[0])->toContain('401')
        ->and($log->warnings[1])->not->toBe($log->warnings[0]);
})->with(['sync', 'queue']);

it('the body excerpt in the warning leaves out header lines and credentials the destination echoed', function () {
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

    foreach (['cGstdGVzdDpzay10ZXN0', 'SEVBREVSLVNFQ1JFVA', 'KEY-SECRET', 'TOKEN-SECRET', 'QkFTSUMtU0VDUkVU'] as $secret) {
        expect($log->warnings[0])->not->toContain($secret);
    }
});

it('the body excerpt masks values by key name and keeps plain error lines', function () {
    $log = bootExport('sync');
    fakeDestination(400, implode("\n", [
        'Error: invalid project',
        '{"x-api-key":"K1-SECRET","secret_token": "K2-SECRET","Authorization":"Bearer a,K3-SECRET"}',
        'api_key=K4-SECRET&password=K5-SECRET',
        '{"access_token": 6543210987}',
    ]));

    sendSpan();

    expect($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('Error: invalid project');

    foreach (['K1-SECRET', 'K2-SECRET', 'K3-SECRET', 'K4-SECRET', 'K5-SECRET', '6543210987'] as $secret) {
        expect($log->warnings[0])->not->toContain($secret);
    }
});

it('failed() called by the queue for another cause names that cause, not a network error or status', function () {
    $log = bootExport('queue');

    (new ExportSpans(''))->failed(new RuntimeException('The job timed out.'));

    expect($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain(RuntimeException::class)
        ->and($log->warnings[0])->not->toContain('408')
        ->and($log->warnings[0])->not->toContain('could not be reached')
        ->and($log->warnings[0])->not->toContain('timed out');
});

it('a batch that cannot be retried because the export job is not on a queue is dropped with one warning', function () {
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

it('a redirect is not followed and counts as rejected, so the headers never go to another host', function (string $transport, int $status) {
    $log = bootExport($transport);
    useExportQueue();

    Http::fake([
        'cloud.langfuse.com/*' => Http::response('Moved.', $status, ['Location' => 'https://elsewhere.test/collect']),
        'elsewhere.test/*' => Http::response('', 200),
    ]);

    sendSpan();

    if ($transport === 'queue') {
        workExportJob();

        expect(DB::table('jobs')->count())->toBe(0);
    }

    $requests = Http::recorded();

    expect($requests)->toHaveCount(1)
        ->and($requests[0][0]->url())->toStartWith('https://cloud.langfuse.com/')
        ->and($requests[0][0]->method())->toBe('POST')
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain("HTTP {$status}");
})->with(['sync', 'queue'])->with([301, 302, 303, 307, 308]);

/**
 * Send one span through the given transport and destination with the given compression, and get the request it made.
 */
function sendCompressed(string $transport, string $destination, ?string $compression): Request
{
    bootExport($transport, ['refract.destination' => $destination, 'refract.compression' => $compression]);
    fakeDestination(200);
    useExportQueue();

    sendSpan();

    if ($transport === 'queue') {
        workExportJob();
    }

    Http::assertSentCount(1);

    return Http::recorded()[0][0];
}

it('by default the body is gzipped and sent with Content-Encoding: gzip, to both destinations', function (string $transport, string $destination, ?string $compression) {
    $request = sendCompressed($transport, $destination, $compression);

    $json = gzdecode($request->body());

    expect($request->header('Content-Encoding'))->toBe(['gzip'])
        ->and($json)->toBeString()
        ->and(strlen($request->body()))->toBeLessThan(strlen((string) $json))
        ->and(Otlp::spans())->toHaveCount(1);
})->with(['sync', 'queue'])->with(['otlp', 'langfuse'])->with([
    'not set' => null,
    'empty' => '',
    'gzip' => 'gzip',
    'GZIP' => 'GZIP',
]);

it('when gzip fails the body is sent plain, with no Content-Encoding', function () {
    $log = bootExport('sync');
    fakeDestination(200);

    app()->instance(Exporter::class, new class(PlatformFactory::fromConfig(), new GenAiTranslator([]), new OtlpJson, 'testing', 'Refract') extends HttpExporter
    {
        protected function gzip(string $body): string|false
        {
            return false;
        }
    });

    sendSpan();

    $request = Http::recorded()[0][0];

    expect($request->header('Content-Encoding'))->toBe([])
        ->and(json_decode($request->body(), true))->toHaveKey('resourceSpans')
        ->and($log->warnings)->toBe([]);
});

it('with none the body is not gzipped and has no Content-Encoding, for both destinations', function (string $transport, string $destination, string $compression) {
    $request = sendCompressed($transport, $destination, $compression);

    expect($request->header('Content-Encoding'))->toBe([])
        ->and(json_decode($request->body(), true))->toHaveKey('resourceSpans')
        ->and(Otlp::spans())->toHaveCount(1);
})->with(['sync', 'queue'])->with(['otlp', 'langfuse'])->with(['none', 'NONE']);

/*
 * A batch over 4 MB of OTLP JSON (before gzip) is sent in parts of
 * 4 MB or less, cut between run trees. A run tree is cut only when it alone
 * is too big.
 */

/**
 * Get the neutral spans of one run tree: a run span and the given tool spans under it, each tool result the given text.
 *
 * @param  list<string>  $results
 * @return list<array<string, mixed>>
 */
function runTree(array $results): array
{
    $traceId = bin2hex(random_bytes(16));
    $runId = bin2hex(random_bytes(8));
    $span = fn (string $id, ?string $parent, string $kind, array $content) => [
        'v' => 1, 'trace_id' => $traceId, 'span_id' => $id, 'parent_span_id' => $parent,
        'kind' => $kind, 'start' => 1_700_000_000_000_000_000, 'end' => 1_700_000_001_000_000_000, 'status' => 'ok', 'status_message' => null,
        'call' => ['agent' => 'TimeAgent', 'tool' => 'Clock'], 'content' => $content, 'context' => [], 'events' => [],
    ];

    $spans = [$span($runId, null, 'invoke_agent', [])];

    foreach ($results as $result) {
        $spans[] = $span(bin2hex(random_bytes(8)), $runId, 'execute_tool', ['result' => $result]);
    }

    return $spans;
}

/**
 * Get random text of the given length, which neither compresses nor grows when JSON encoded.
 */
function randomText(int $length): string
{
    return substr(bin2hex(random_bytes(intdiv($length, 2) + 1)), 0, $length);
}

/**
 * Get the OTLP JSON body (before gzip) of each request sent so far.
 *
 * @return list<string>
 */
function partBodies(): array
{
    return Http::recorded()->map(fn (array $pair) => Otlp::body($pair[0]))->values()->all();
}

/**
 * Get the trace ids of each request sent so far, one sorted list per request.
 *
 * @return list<list<string>>
 */
function partTraces(): array
{
    return array_map(function (string $body) {
        $traces = array_values(array_unique(array_column(json_decode($body, true)['resourceSpans'][0]['scopeSpans'][0]['spans'], 'traceId')));
        sort($traces);

        return $traces;
    }, partBodies());
}

it('a batch over 4 MB on sync is sent in parts of 4 MB or less, each run tree whole in one part', function () {
    $log = bootExport('sync');
    fakeDestination(200);

    // Five trees of about 1.5 MB: two fit in one part, so three parts.
    $trees = array_map(fn () => runTree([randomText(500_000), randomText(500_000), randomText(500_000)]), range(1, 5));

    app(Transport::class)->send(array_merge(...$trees));

    $bodies = partBodies();

    expect($bodies)->toHaveCount(3)
        ->and(array_map(strlen(...), $bodies))->each->toBeLessThanOrEqual(4_000_000)
        ->and(Otlp::spans())->toHaveCount(20)
        ->and(array_merge(...partTraces()))->toHaveCount(5)
        ->and($log->warnings)->toBe([]);
});

it('a batch of 4 MB or less is sent in one request', function (string $transport) {
    bootExport($transport);
    fakeDestination(200);
    useExportQueue();

    // Text that compresses well, so the batch fits one queue job.
    app(Transport::class)->send([...runTree([str_repeat('a', 1_900_000)]), ...runTree([str_repeat('b', 1_900_000)])]);

    if ($transport === 'queue') {
        expect(DB::table('jobs')->count())->toBe(1);

        workExportJob();
    }

    Http::assertSentCount(1);
    expect(Otlp::spans())->toHaveCount(4);
})->with(['sync', 'queue']);

it('the size is the encoded OTLP JSON, so text that grows when escaped is split by its escaped size', function () {
    bootExport('sync');
    fakeDestination(200);

    // 1.2 MB of quotes and new lines each: 2.4 MB as text, about 4.8 MB as JSON.
    $text = str_repeat("\"\n", 600_000);

    app(Transport::class)->send([...runTree([$text]), ...runTree([$text])]);

    expect(partBodies())->toHaveCount(2)
        ->and(array_map(strlen(...), partBodies()))->each->toBeLessThanOrEqual(4_000_000)
        ->and(Otlp::spans())->toHaveCount(4);
});

it('a run tree bigger than 4 MB is split, and the other trees stay whole', function () {
    $log = bootExport('sync');
    fakeDestination(200);

    $big = runTree(array_map(fn () => randomText(600_000), range(1, 10)));
    $small = runTree([randomText(1_000)]);

    app(Transport::class)->send([...$small, ...$big]);

    $traces = partTraces();
    $smallTrace = $small[0]['trace_id'];

    expect(count($traces))->toBeGreaterThanOrEqual(2)
        ->and(array_map(strlen(...), partBodies()))->each->toBeLessThanOrEqual(4_000_000)
        ->and(Otlp::spans())->toHaveCount(13)
        ->and(array_values(array_filter($traces, fn (array $request) => in_array($smallTrace, $request, true))))->toHaveCount(1)
        ->and($log->warnings)->toBe([]);
});

it('a run tree split into parts keeps tied siblings in order, in distinct milliseconds, inside their parent on Langfuse', function (string $transport) {
    bootExport($transport);
    fakeDestination(200);
    useExportQueue();

    // Ten tool spans that start in the same nanosecond, too big for one part. The text compresses, so each part fits one queue job.
    $tree = runTree(array_map(fn (int $tool) => str_repeat((string) $tool, 600_000), range(0, 9)));

    app(Transport::class)->send($tree);

    if ($transport === 'queue') {
        expect(DB::table('jobs')->count())->toBeGreaterThanOrEqual(2);

        while (DB::table('jobs')->count() > 0) {
            workExportJob();
        }
    }

    $spans = collect(Otlp::spans())->keyBy('spanId');
    $run = $spans[$tree[0]['span_id']];
    $tools = array_map(fn (array $span) => $spans[$span['span_id']], array_slice($tree, 1));
    $milliseconds = array_map(fn (array $span) => intdiv((int) $span['startTimeUnixNano'], 1_000_000), $tools);
    $sorted = $milliseconds;
    sort($sorted);

    expect(count(partBodies()))->toBeGreaterThanOrEqual(2)
        ->and(array_unique($milliseconds))->toHaveCount(10)
        ->and($milliseconds)->toBe($sorted);

    foreach ($tools as $tool) {
        expect((int) $tool['startTimeUnixNano'])->toBeGreaterThanOrEqual((int) $run['startTimeUnixNano'])
            ->and((int) $tool['endTimeUnixNano'])->toBeLessThanOrEqual((int) $run['endTimeUnixNano']);
    }
})->with(['sync', 'queue']);

it('a single span bigger than 4 MB is sent alone, with one warning', function () {
    $log = bootExport('sync');
    fakeDestination(200);

    $huge = runTree([randomText(4_500_000)]);
    $other = runTree([randomText(1_000)]);

    app(Transport::class)->send([...$huge, ...$other, ...runTree([randomText(4_200_000)])]);

    $alone = array_values(array_filter(
        Http::recorded()->map(fn (array $pair) => Otlp::data($pair[0])['resourceSpans'][0]['scopeSpans'][0]['spans'])->all(),
        fn (array $spans) => count($spans) === 1 && strlen(json_encode($spans)) > 4_000_000,
    ));

    expect($alone)->toHaveCount(2)
        ->and(Otlp::spans())->toHaveCount(6)
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('over the 4000000 byte limit');
});

it('on sync failed parts warn once per kind and the later parts are still sent', function () {
    $log = bootExport('sync');
    // Two parts fail the same way (503): one warning for both.
    Http::fake(['*' => Http::sequence()->push('bad', 400)->push('', 503)->push('', 503)->push('', 200)]);

    $trees = array_map(fn () => runTree([randomText(1_500_000), randomText(1_500_000)]), range(1, 4));

    app(Transport::class)->send(array_merge(...$trees));

    Http::assertSentCount(4);
    expect($log->warnings)->toHaveCount(2)
        ->and($log->warnings[0])->toContain('HTTP 400')
        ->and($log->warnings[1])->toContain('could not be exported');
});

it('on queue each part is its own job, so a retry sends only the part that failed', function () {
    $log = bootExport('queue');
    useExportQueue();

    // Text that compresses well, so each part fits one queue job.
    $trees = array_map(fn () => runTree([str_repeat('a', 1_500_000), str_repeat('b', 1_500_000)]), range(1, 3));

    app(Transport::class)->send(array_merge(...$trees));

    expect(DB::table('jobs')->count())->toBe(3);

    Http::fake(['*' => Http::sequence()->push('', 200)->push('', 503)->push('', 200)->push('', 200)]);

    workExportJob();
    workExportJob();
    workExportJob();

    expect(DB::table('jobs')->count())->toBe(1);

    DB::table('jobs')->update(['available_at' => now()->getTimestamp()]);
    workExportJob();

    $spansPerRequest = Http::recorded()->map(fn (array $pair) => count(Otlp::data($pair[0])['resourceSpans'][0]['scopeSpans'][0]['spans']))->all();

    expect($spansPerRequest)->toBe([3, 3, 3, 3])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(partTraces()[1])->toBe(partTraces()[3])
        ->and($log->warnings)->toBe([]);
});

it('on queue a part too big for one job is exported in this process, and the other parts are still queued', function () {
    $log = bootExport('queue');
    fakeDestination(200);
    useExportQueue();

    app(Transport::class)->send([
        ...runTree([str_repeat('a', 2_000_000), str_repeat('b', 1_500_000)]),
        // Random text does not compress much: well over 256 KB gzipped.
        ...runTree([randomText(1_000_000)]),
    ]);

    Http::assertSentCount(1);
    expect(DB::table('jobs')->count())->toBe(1)
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('too big for the queue');

    workExportJob();

    Http::assertSentCount(2);
    expect(Otlp::spans())->toHaveCount(5);
});

it('the limit is exact: a run tree of exactly 4 MB is one request, one byte more is split', function () {
    bootExport('sync');
    fakeDestination(200);

    app(Transport::class)->send(runTree(['x']));
    $length = 4_000_000 - strlen(partBodies()[0]) + 1;

    fakeDestination(200);
    app(Transport::class)->send(runTree([str_repeat('x', $length)]));

    expect(array_map(strlen(...), partBodies()))->toBe([4_000_000]);

    fakeDestination(200);
    app(Transport::class)->send(runTree([str_repeat('x', $length + 1)]));

    expect(partBodies())->toHaveCount(2);
});

it('every export request waits at most 15 s for the destination', function (string $transport) {
    bootExport($transport);
    useExportQueue();
    $timeouts = [];
    Http::fake(function ($request, array $options) use (&$timeouts) {
        $timeouts[] = $options['timeout'] ?? null;

        return Http::response('', 200);
    });

    sendSpan();

    if ($transport === 'queue') {
        workExportJob();
    }

    expect($timeouts)->toBe([15]);
})->with(['sync', 'queue']);
