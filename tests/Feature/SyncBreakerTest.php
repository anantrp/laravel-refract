<?php

use Anantrp\Refract\Export\HttpExporter;
use Anantrp\Refract\Transport\ExportBreaker;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
 * The sync drain remembers a destination that failed: for a while after a
 * network error, timeout, 408, 429 or 5xx, exports in every process of the
 * app drop their trees at once, without a request. Then one export tries
 * again, and an answer closes the breaker.
 */

it('after a destination fails, exports make no request until 30 s pass, then one probe goes through and success resumes', function () {
    $log = bootExport();
    Http::fake(['*' => Http::sequence()->pushFailedConnection()->whenEmpty(Http::response('', 200))]);

    sendSpan();
    Http::assertSentCount(1);

    foreach (range(1, 20) as $run) {
        sendSpan();
    }

    Http::assertSentCount(1);

    $this->travel(29)->seconds();
    sendSpan();
    Http::assertSentCount(1);

    $this->travel(2)->seconds();
    sendSpan();
    sendSpan();

    Http::assertSentCount(3);
    expect($log->warnings)->toHaveCount(2)
        ->and($log->warnings[0])->toContain('could not be exported')->toContain('30 seconds')
        ->and($log->warnings[1])->toContain('dropped without trying');
});

it('the pause doubles on each failure in a row, up to 5 minutes', function () {
    bootExport();
    fakeDestination(503);

    $pauses = [];

    foreach (range(1, 6) as $failure) {
        sendSpan();
        $sent = count(Http::recorded());

        // Find the pause: the first second at which an export is tried again.
        $waited = 0;

        while (count(Http::recorded()) === $sent && $waited < 1_000) {
            $this->travel(1)->seconds();
            $waited++;
            sendSpan();
        }

        $pauses[] = $waited;
    }

    expect($pauses)->toBe([30, 60, 120, 240, 300, 300]);
});

it('a rejected export (4xx) answers, so it does not pause exports', function () {
    bootExport();
    fakeDestination(400);

    sendSpan();
    sendSpan();

    Http::assertSentCount(2);
});

it('the pause is shared through the cache, so a fresh process (a new FPM request) skips the destination too', function () {
    bootExport();
    fakeDestination('network');

    sendSpan();
    ExportBreaker::reset();

    sendSpan();

    Http::assertSentCount(1);
});

it('when the cache fails, the pause is kept in the process and the export is unaffected', function () {
    bootExport();
    $cache = Mockery::mock(Cache::class);
    $cache->shouldReceive('get', 'put', 'forget')->andThrow(new RuntimeException('cache is down'));
    app()->instance(Cache::class, $cache);
    fakeDestination('network');

    sendSpan();
    sendSpan();

    Http::assertSentCount(1);
});

it('Refract sends through its own HTTP client: the app\'s fakes, global middleware and request events never see it', function () {
    $this->shareAppHttpClient = false;
    bootExport();

    $refract = app(HttpExporter::CLIENT)->fake(fn () => Http::response('', 200));

    Http::fake();
    Http::globalRequestMiddleware(fn ($request) => $request->withHeader('X-App-Secret', 'leak'));
    Event::listen(RequestSending::class, fn () => throw new LogicException('the app saw a Refract request'));

    sendSpan();

    expect($refract)->not->toBe(app(HttpFactory::class));
    $refract->assertSentCount(1);
    $refract->assertSent(fn (Request $request) => ! $request->hasHeader('X-App-Secret'));
    Http::assertNothingSent();
});
