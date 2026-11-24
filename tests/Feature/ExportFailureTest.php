<?php

use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Contracts\ExportResult;
use Anantrp\Refract\Support\Stats;
use Anantrp\Refract\Tests\Support\MemoryExporter;
use Anantrp\Refract\Tests\Support\Otlp;
use Anantrp\Refract\Transport\Drain;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/*
 * An export in this process is best effort: a network error, 408, 429 or
 * 5xx stops it and drops the trees with one warning; every other status is
 * dropped with one warning too, and the later requests are still sent.
 */

dataset('retryable', ['408' => 408, '429' => 429, '500' => 500, '503' => 503, 'network error' => 'network']);
dataset('rejected', ['400' => 400, '401' => 401, '403' => 403, '404' => 404]);

it('a 2xx is done with no log', function (int $status) {
    $log = bootExport();
    fakeDestination($status);

    sendSpan();

    Http::assertSentCount(1);
    expect($log->warnings)->toBe([]);
})->with([200, 202, 204]);

it('a 2xx that refuses some spans is done, not retried, and logs one warning with the count and the message', function (string $body, string $count, string $message) {
    $log = bootExport();
    fakeDestination(200, $body);

    sendSpan();

    Http::assertSentCount(1);
    expect($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain($count)
        ->and($log->warnings[0])->toContain($message);
})->with([
    'count and message' => ['{"partialSuccess":{"rejectedSpans":2,"errorMessage":"test reject"}}', '2 span', 'test reject'],
    'count as an int64 string' => ['{"partialSuccess":{"rejectedSpans":"3","errorMessage":"too old"}}', '3 span', 'too old'],
    'count only' => ['{"partialSuccess":{"rejectedSpans":5}}', '5 span', 'no message'],
    'count too big for an int' => ['{"partialSuccess":{"rejectedSpans":99999999999999999999}}', (string) PHP_INT_MAX.' span', 'no message'],
    'message only' => ['{"partialSuccess":{"errorMessage":"slow down"}}', '0 span', 'slow down'],
]);

it('the partial success message leaves out credentials the destination echoed', function () {
    $log = bootExport();
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
    $log = bootExport();
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

it('a 400, 401, 403 or 404 is not retried and logs one warning with the status and the first 200 chars of the body', function (int $status) {
    $log = bootExport();
    fakeDestination($status, str_repeat('é', 200).'TAIL');

    sendSpan();
    sendSpan();

    Http::assertSentCount(2);
    expect($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain((string) $status)
        ->and($log->warnings[0])->toContain(str_repeat('é', 200))
        ->and($log->warnings[0])->not->toContain('TAIL')
        ->and($log->warnings[0])->not->toContain('sk-test')
        ->and($log->warnings[0])->not->toContain('pk-test');
})->with('rejected');

it('an HTTP client error that is not a network error is dropped with one warning, nothing reaches the app', function (string $error) {
    $log = bootExport();

    if ($error === 'exception from the client') {
        Http::fake(fn () => throw new RuntimeException('boom'));
    }

    sendSpan();

    expect($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('dropped')
        ->and($log->warnings[0])->toContain($error === 'stray request' ? 'StrayRequestException' : 'RuntimeException');
})->with(['exception from the client', 'stray request']);

it('a request exception that carries a response is judged by its status', function () {
    $log = bootExport();
    Http::fake(fn () => throw new RequestException(new Response(new GuzzleHttp\Psr7\Response(401, [], 'no key'))));

    sendSpan();

    expect($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('401')
        ->and($log->warnings[0])->toContain('no key');
});

it('a Retry-After is not waited for: no retry and one warning, like any failed export', function (int $status) {
    $log = bootExport();
    Http::fake(fn () => Http::response('', $status, ['Retry-After' => '3']));

    sendSpan();

    Http::assertSentCount(1);
    expect($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('dropped');
})->with(['429' => 429, '503' => 503]);

it('a 429 or 503 carries its Retry-After seconds in the export result, capped at 300', function (int $status, string $header, ?int $seconds) {
    bootExport();
    Http::fake(fn () => Http::response('', $status, $header === 'missing' ? [] : ['Retry-After' => $header]));

    $result = app(Exporter::class)->export([runTree(['x'])]);

    expect($result->isRetryable())->toBeTrue()
        ->and($result->retryAfter)->toBe($seconds);
})->with(['429' => 429, '503' => 503])->with([
    'seconds' => ['30', 30],
    'zero' => ['0', 0],
    'over the cap' => ['301', 300],
    'too long for an int' => ['99999999999999999999999', 300],
    'an HTTP date' => ['Wed, 21 Oct 2026 07:28:00 GMT', null],
    'negative' => ['-5', null],
    'missing' => ['missing', null],
]);

it('a 408, 500 or network error carries no Retry-After, and a 2xx or 4xx is not retryable', function (int|string $status, bool $retryable) {
    bootExport();
    Http::fake(fn () => $status === 'network' ? Http::failedConnection() : Http::response('', (int) $status, ['Retry-After' => '30']));

    $result = app(Exporter::class)->export([runTree(['x'])]);

    expect($result->isRetryable())->toBe($retryable)
        ->and($result->retryAfter)->toBeNull();
})->with([
    '408' => [408, true],
    '500' => [500, true],
    'network error' => ['network', true],
    '200' => [200, false],
    '400' => [400, false],
]);

it('a 408, 429, 5xx or network error is not retried, logs one warning and pauses later exports', function (int|string $status) {
    $log = bootExport();
    fakeDestination($status);

    sendSpan();

    Http::assertSentCount(1);
    expect($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('dropped');

    sendSpan();
    sendSpan();

    Http::assertSentCount(1);
    expect($log->warnings)->toHaveCount(2)
        ->and($log->warnings[1])->toContain('dropped without trying');
})->with('retryable');

it('a 401 and then an outage in one process give two different warnings', function () {
    $log = bootExport();
    Http::fakeSequence()->push('bad key', 401)->whenEmpty(Http::response('down', 503));

    sendSpan();
    sendSpan();

    expect($log->warnings)->toHaveCount(2)
        ->and($log->warnings[0])->toContain('401')
        ->and($log->warnings[1])->not->toBe($log->warnings[0]);
});

it('the body excerpt in the warning leaves out header lines and credentials the destination echoed', function () {
    $log = bootExport();
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
    $log = bootExport();
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

it('a redirect is not followed and counts as rejected, so the headers never go to another host', function (int $status) {
    $log = bootExport();

    Http::fake([
        'cloud.langfuse.com/*' => Http::response('Moved.', $status, ['Location' => 'https://elsewhere.test/collect']),
        'elsewhere.test/*' => Http::response('', 200),
    ]);

    sendSpan();

    $requests = Http::recorded();

    expect($requests)->toHaveCount(1)
        ->and($requests[0][0]->url())->toStartWith('https://cloud.langfuse.com/')
        ->and($requests[0][0]->method())->toBe('POST')
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain("HTTP {$status}");
})->with([301, 302, 303, 307, 308]);

it('the body is always gzipped and sent with Content-Encoding: gzip, to both destinations', function (string $destination) {
    bootExport(['refract.destination' => $destination]);
    fakeDestination(200);

    sendSpan();

    Http::assertSentCount(1);

    $request = Http::recorded()[0][0];
    $json = gzdecode($request->body());

    expect($request->header('Content-Encoding'))->toBe(['gzip'])
        ->and($json)->toBeString()
        ->and(strlen($request->body()))->toBeLessThan(strlen((string) $json))
        ->and(Otlp::spans())->toHaveCount(1);
})->with(['otlp', 'langfuse']);

/*
 * Whole run trees are packed into requests of 4 MB of OTLP JSON or less
 * (before gzip). A run tree is cut only when it alone is too big.
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

it('trees over 4 MB in all are sent in requests of 4 MB or less, each run tree whole in one request', function () {
    $log = bootExport();
    fakeDestination(200);

    // Five trees of about 1.5 MB: two fit in one part, so three parts.
    $trees = array_map(fn () => runTree([randomText(500_000), randomText(500_000), randomText(500_000)]), range(1, 5));

    app(Drain::class)->send($trees);

    $bodies = partBodies();

    expect($bodies)->toHaveCount(3)
        ->and(array_map(strlen(...), $bodies))->each->toBeLessThanOrEqual(4_000_000)
        ->and(Otlp::spans())->toHaveCount(20)
        ->and(array_merge(...partTraces()))->toHaveCount(5)
        ->and($log->warnings)->toBe([]);
});

it('trees of 4 MB or less in all are sent in one request', function () {
    bootExport();
    fakeDestination(200);

    app(Drain::class)->send([runTree([str_repeat('a', 1_900_000)]), runTree([str_repeat('b', 1_900_000)])]);

    Http::assertSentCount(1);
    expect(Otlp::spans())->toHaveCount(4);
});

it('the size is the encoded OTLP JSON, so text that grows when escaped is split by its escaped size', function () {
    bootExport();
    fakeDestination(200);

    // 1.2 MB of quotes and new lines each: 2.4 MB as text, about 4.8 MB as JSON.
    $text = str_repeat("\"\n", 600_000);

    app(Drain::class)->send([runTree([$text]), runTree([$text])]);

    expect(partBodies())->toHaveCount(2)
        ->and(array_map(strlen(...), partBodies()))->each->toBeLessThanOrEqual(4_000_000)
        ->and(Otlp::spans())->toHaveCount(4);
});

it('a run tree bigger than 4 MB is split with one warning, and the other trees stay whole', function () {
    $log = bootExport();
    fakeDestination(200);

    $big = runTree(array_map(fn () => randomText(600_000), range(1, 10)));
    $small = runTree([randomText(1_000)]);

    app(Drain::class)->send([$small, $big]);

    $traces = partTraces();
    $smallTrace = $small[0]['trace_id'];

    expect(count($traces))->toBeGreaterThanOrEqual(2)
        ->and(array_map(strlen(...), partBodies()))->each->toBeLessThanOrEqual(4_000_000)
        ->and(Otlp::spans())->toHaveCount(13)
        ->and(array_values(array_filter($traces, fn (array $request) => in_array($smallTrace, $request, true))))->toHaveCount(1)
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('A run tree is over the 4000000 byte limit');
});

it('a single span bigger than 4 MB is sent alone, with one warning for it and one for its split tree', function () {
    $log = bootExport();
    fakeDestination(200);

    $huge = runTree([randomText(4_500_000)]);
    $other = runTree([randomText(1_000)]);

    app(Drain::class)->send([$huge, $other, runTree([randomText(4_200_000)])]);

    $alone = array_values(array_filter(
        Http::recorded()->map(fn (array $pair) => Otlp::data($pair[0])['resourceSpans'][0]['scopeSpans'][0]['spans'])->all(),
        fn (array $spans) => count($spans) === 1 && strlen(json_encode($spans)) > 4_000_000,
    ));

    expect($alone)->toHaveCount(2)
        ->and(Otlp::spans())->toHaveCount(6)
        ->and($log->warnings)->toHaveCount(2)
        ->and($log->warnings[0])->toContain('A run tree is over the 4000000 byte limit')
        ->and($log->warnings[1])->toContain('over the 4000000 byte limit. It was sent alone');
});

it('a rejected request does not stop the later requests, a retryable one stops the export, each with one warning', function () {
    $log = bootExport();
    Http::fake(['*' => Http::sequence()->push('bad', 400)->push('', 503)->push('', 200)]);

    $trees = array_map(fn () => runTree([randomText(1_500_000), randomText(1_500_000)]), range(1, 4));

    app(Drain::class)->send($trees);

    Http::assertSentCount(2);
    expect($log->warnings)->toHaveCount(2)
        ->and($log->warnings[0])->toContain('HTTP 400')
        ->and($log->warnings[1])->toContain('could not be exported');
});

it('the limit is exact: a run tree of exactly 4 MB is one request, one byte more is split', function () {
    bootExport();
    fakeDestination(200);

    app(Drain::class)->send([runTree(['x'])]);
    $length = 4_000_000 - strlen(partBodies()[0]) + 1;

    fakeDestination(200);
    app(Drain::class)->send([runTree([str_repeat('x', $length)])]);

    expect(array_map(strlen(...), partBodies()))->toBe([4_000_000]);

    fakeDestination(200);
    app(Drain::class)->send([runTree([str_repeat('x', $length + 1)])]);

    expect(partBodies())->toHaveCount(2);
});

it('an export in this process waits at most 5 s for the destination', function () {
    bootExport();
    $timeouts = [];
    Http::fake(function ($request, array $options) use (&$timeouts) {
        $timeouts[] = $options['timeout'] ?? null;

        return Http::response('', 200);
    });

    sendSpan();

    expect($timeouts)->toHaveCount(1)
        ->and($timeouts[0])->toBeGreaterThan(4)->toBeLessThanOrEqual(5);
});

it('an export in this process is given one 5 s limit in all, however many requests it makes', function () {
    bootExport();
    app()->instance(Exporter::class, $exporter = new MemoryExporter);

    sendSpan();

    expect($exporter->limits)->toBe([5.0]);
});

it('an export waits at most 1 s to connect to the destination', function () {
    bootExport();
    $connects = [];
    Http::fake(function ($request, array $options) use (&$connects) {
        $connects[] = $options['connect_timeout'] ?? null;

        return Http::response('', 200);
    });

    sendSpan();

    expect($connects)->toBe([1]);
});

it('an export in this process that runs out of time drops the trees it did not finish, with one warning', function () {
    $log = bootExport();
    app()->instance(Exporter::class, $exporter = new MemoryExporter);
    $exporter->results = [ExportResult::unfinished(1)];

    app(Drain::class)->send([runTree(['a']), runTree(['b']), runTree(['c'])]);

    expect($exporter->trees)->toHaveCount(1)
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('2 of 3 run trees')->toContain('5 seconds');
});

it('given a time limit, a request is never started with less than a second of it left, and the result counts the whole trees done', function () {
    bootExport();
    Http::fake(function () {
        usleep(600_000);

        return Http::response('', 200);
    });

    // Three trees of 3 MB: one request each.
    $trees = array_map(fn () => runTree([randomText(3_000_000)]), range(1, 3));

    $result = app(Exporter::class)->export($trees, 1.5);

    Http::assertSentCount(1);
    expect($result->isUnfinished())->toBeTrue()
        ->and($result->done)->toBe(1);
});

it('a tree split over requests is done only once its last request is answered', function () {
    bootExport();
    Http::fake(function () {
        usleep(600_000);

        return Http::response('', 200);
    });

    $small = runTree([randomText(1_000)]);
    $big = runTree(array_map(fn () => randomText(1_500_000), range(1, 4)));

    $result = app(Exporter::class)->export([$small, $big], 1.5);

    // The first request took the small tree, the big tree's run span and its first two results.
    Http::assertSentCount(1);
    expect($result->isUnfinished())->toBeTrue()
        ->and($result->done)->toBe(1)
        ->and(array_column($result->rest, 'span_id'))->toBe(array_column(array_slice($big, 3), 'span_id'));
});

it('a split tree too slow to send in one export finishes over later ones, each span sent once', function () {
    bootExport();
    $sent = [];
    Http::fake(function ($request) use (&$sent) {
        usleep(600_000);

        foreach (json_decode(gzdecode($request->body()), true)['resourceSpans'][0]['scopeSpans'][0]['spans'] as $span) {
            $sent[] = $span['spanId'];
        }

        return Http::response('', 200);
    });

    // A run and four results of 3 MB: four requests, and time for one per export.
    $tree = runTree(array_map(fn () => randomText(3_000_000), range(1, 4)));
    $exports = 0;

    do {
        $result = app(Exporter::class)->export([$tree], 1.5);
        $exports++;
        $tree = $result->rest;
    } while ($result->isUnfinished() && $exports < 10);

    expect($result->isOk())->toBeTrue()
        ->and($exports)->toBe(4)
        ->and($sent)->toHaveCount(5)
        ->and(array_unique($sent))->toHaveCount(5);
});

it('a split tree keeps tied siblings in order, in distinct milliseconds, inside their parent on Langfuse, across requests and later exports', function () {
    bootExport();
    $sent = [];
    Http::fake(function ($request) use (&$sent) {
        usleep(600_000);

        foreach (json_decode(gzdecode($request->body()), true)['resourceSpans'][0]['scopeSpans'][0]['spans'] as $span) {
            $sent[$span['spanId']] = $span;
        }

        return Http::response('', 200);
    });

    // Ten tool spans that start in the same nanosecond, too big for one request, with time for one request per export.
    $tree = runTree(array_map(fn () => randomText(600_000), range(0, 9)));
    $original = $tree;
    $exports = 0;

    do {
        $result = app(Exporter::class)->export([$tree], 1.5);
        $exports++;
        $tree = $result->rest;
    } while ($result->isUnfinished() && $exports < 10);

    $run = $sent[$original[0]['span_id']];
    $tools = array_map(fn (array $span) => $sent[$span['span_id']], array_slice($original, 1));
    $milliseconds = array_map(fn (array $span) => intdiv((int) $span['startTimeUnixNano'], 1_000_000), $tools);
    $sorted = $milliseconds;
    sort($sorted);

    expect($result->isOk())->toBeTrue()
        ->and($exports)->toBeGreaterThanOrEqual(2)
        ->and($sent)->toHaveCount(11)
        ->and(array_unique($milliseconds))->toHaveCount(10)
        ->and($milliseconds)->toBe($sorted);

    foreach ($tools as $tool) {
        expect((int) $tool['startTimeUnixNano'])->toBeGreaterThanOrEqual((int) $run['startTimeUnixNano'])
            ->and((int) $tool['endTimeUnixNano'])->toBeLessThanOrEqual((int) $run['endTimeUnixNano']);
    }
});

it('a cut between whole trees carries no unsent spans', function () {
    bootExport();
    Http::fake(function () {
        usleep(600_000);

        return Http::response('', 200);
    });

    $trees = array_map(fn () => runTree([randomText(3_000_000)]), range(1, 2));

    $result = app(Exporter::class)->export($trees, 1.5);

    expect($result->isUnfinished())->toBeTrue()
        ->and($result->done)->toBe(1)
        ->and($result->rest)->toBe([]);
});

it('given a time limit, a request waits at most what is left of it', function () {
    bootExport();
    $timeouts = [];
    Http::fake(function ($request, array $options) use (&$timeouts) {
        $timeouts[] = $options['timeout'] ?? null;

        return Http::response('', 200);
    });

    $result = app(Exporter::class)->export([runTree(['x'])], 3);

    expect($result->isOk())->toBeTrue()
        ->and($timeouts)->toHaveCount(1)
        ->and($timeouts[0])->toBeGreaterThan(2)->toBeLessThanOrEqual(3);
});

it('a request that waits out what was left of the time limit is cut short, not failed', function () {
    bootExport();
    Http::fake(function () {
        usleep(700_000);

        throw new ConnectionException('Operation timed out');
    });

    $result = app(Exporter::class)->export([runTree(['x'])], 1.5);

    expect($result->isUnfinished())->toBeTrue()
        ->and($result->done)->toBe(0);
});

it('a destination that cannot be reached within a time limit is still a failure', function () {
    bootExport();
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $result = app(Exporter::class)->export([runTree(['x'])], 1.5);

    expect($result->isRetryable())->toBeTrue();
});

it('a request refused for good between others counts only its trees as refused', function () {
    bootExport();
    $requests = 0;
    Http::fake(function () use (&$requests) {
        return ++$requests === 2 ? Http::response('bad', 400) : Http::response('', 200);
    });

    // Three trees of 3 MB: one request each.
    $result = app(Exporter::class)->export(array_map(fn () => runTree([randomText(3_000_000)]), range(1, 3)));

    expect($result->isOk())->toBeTrue()
        ->and($result->refused)->toBe(1);
});

it('a tree refused for good before a request that fails is counted with the failure', function () {
    bootExport();
    $requests = 0;
    Http::fake(function () use (&$requests) {
        return match (++$requests) {
            1 => Http::response('bad', 400),
            default => Http::response('', 503),
        };
    });

    $result = app(Exporter::class)->export(array_map(fn () => runTree([randomText(3_000_000)]), range(1, 3)));

    expect($result->isRetryable())->toBeTrue()
        ->and($result->done)->toBe(1)
        ->and($result->refused)->toBe(1);
});

it('in the sync drain, a destination that never answers within its 5 s pauses exports', function () {
    bootExport();
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;
        // Just short of the 5 s the sync drain gives an export, as a read timeout would end.
        usleep(4_200_000);

        throw new ConnectionException('Operation timed out');
    });

    sendSpan();
    sendSpan();

    expect($calls)->toBe(1);
});

it('a split tree refused in its first request, then a 503, is counted once', function () {
    bootExport();
    $requests = 0;
    Http::fake(function () use (&$requests) {
        return ++$requests === 1 ? Http::response('bad', 400) : Http::response('', 503);
    });

    // One tree of two spans too big for one request together, then a small tree.
    $split = runTree([str_repeat('first ', 500_000), str_repeat('second ', 500_000)]);

    app(Drain::class)->send([$split, runTree(['x'])]);

    expect(array_sum(Stats::read()['dropped']))->toBe(2);
});

it('in the sync drain, an export that delivers a request and then fails records the success and the failure', function () {
    bootExport();
    $this->travelTo('2026-11-24 10:00:00');
    $requests = 0;
    Http::fake(function () use (&$requests) {
        return ++$requests === 1 ? Http::response('', 200) : Http::response('', 503);
    });

    // Two trees of 3 MB: one request each.
    app(Drain::class)->send(array_map(fn () => runTree([randomText(3_000_000)]), range(1, 2)));

    expect(Stats::read())->toMatchArray([
        'last_success' => strtotime('2026-11-24 10:00:00'),
        'failing_since' => strtotime('2026-11-24 10:00:00'),
        'dropped' => ['unavailable' => 1],
    ]);
});

it('a split tree with one request taken and one refused for good is rejected, and still records the success', function () {
    bootExport();
    $this->travelTo('2026-11-24 10:00:00');
    $requests = 0;
    Http::fake(function () use (&$requests) {
        return ++$requests === 1 ? Http::response('', 200) : Http::response('bad', 400);
    });

    // One tree of two spans too big for one request together.
    app(Drain::class)->send([runTree([str_repeat('first ', 500_000), str_repeat('second ', 500_000)])]);

    expect(Stats::read())->toMatchArray([
        'last_success' => strtotime('2026-11-24 10:00:00'),
        'dropped' => ['rejected' => 1],
    ]);
});

it('with less than a second of the time limit left, nothing is sent and no tree is done', function () {
    bootExport();
    fakeDestination(200);

    $result = app(Exporter::class)->export([runTree(['x'])], 0.5);

    Http::assertNothingSent();
    expect($result->isUnfinished())->toBeTrue()
        ->and($result->done)->toBe(0);
});
