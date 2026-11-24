<?php

use Anantrp\Refract\Export\HttpExporter;
use Anantrp\Refract\Transport\ExportBreaker;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache as CacheFacade;
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
    $this->freezeSecond();

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
    $cache->shouldReceive('get', 'put', 'add', 'forget')->andThrow(new RuntimeException('cache is down'));
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

it('when the pause ends, only the process that claims the probe tries the destination; the others keep dropping', function () {
    $log = bootExport();
    fakeDestination('network');

    sendSpan();
    $this->travel(31)->seconds();

    // Another process (a concurrent FPM request) claimed the probe and is still waiting on it.
    CacheFacade::put('refract:sync:probe', true, 10);

    sendSpan();
    Http::assertSentCount(1);
    expect($log->warnings[1])->toContain('dropped without trying');

    // That process died before answering: its claim expires, and the next export probes.
    $this->travel(11)->seconds();
    sendSpan();

    Http::assertSentCount(2);
});

it('a process keeps the pause it read from the cache, so a cache that fails during the pause does not let exports through', function () {
    bootExport();
    fakeDestination('network');

    sendSpan();
    ExportBreaker::reset();
    sendSpan(); // reads the pause from the cache

    $cache = Mockery::mock(Cache::class);
    $cache->shouldReceive('get', 'put', 'add', 'forget')->andThrow(new RuntimeException('cache is down'));
    app()->instance(Cache::class, $cache);

    sendSpan();

    Http::assertSentCount(1);
});

it('when another process closes the breaker, this process resumes at once instead of waiting out its own pause', function () {
    bootExport();
    Http::fake(['*' => Http::sequence()->pushFailedConnection()->whenEmpty(Http::response('', 200))]);

    sendSpan();

    // Another process probed, the destination answered, and the shared pause was cleared.
    CacheFacade::forget('refract:sync:breaker');

    sendSpan();

    Http::assertSentCount(2);
});

it('a process that read the pause just before another process\'s probe failed does not probe again', function () {
    bootExport();
    fakeDestination(503);

    sendSpan();
    $this->travel(31)->seconds();

    // B probes, gets a quick 503, and starts a 60-second pause.
    sendSpan();
    Http::assertSentCount(2);

    // A read the cache a moment before B's probe failed, so it still sees the expired pause.
    ExportBreaker::reset();
    CacheFacade::put('refract:sync:breaker', ['until' => now()->getTimestamp() - 1, 'failures' => 1], 600);

    sendSpan();

    Http::assertSentCount(2);
});

/*
 * Every way the cache can hold the pause, crossed with every way saving to
 * it can go. Whatever the cache does, a failed export pauses this process
 * for the right time, it sends nothing during the pause, and one export
 * probes when it ends.
 */
it('a failed export pauses this process for the right time, whatever the cache holds and whether it can save', function (string $holds, string $save, int $pause) {
    $log = bootExport();
    // The pause is checked a second before and after it ends: a real second passing must not move it.
    $this->freezeSecond();
    fakeDestination('network');

    $store = $holds === 'an old pause' ? ['refract:sync:breaker' => ['until' => now()->getTimestamp() - 10, 'failures' => 1]] : [];
    $fail = fn () => $save === 'throws' ? throw new RuntimeException('OOM command not allowed') : false;

    $cache = Mockery::mock(Cache::class);
    $expires = [];
    $cache->shouldReceive('get')->andReturnUsing(function ($key) use (&$store, &$expires, $holds) {
        if ($holds === 'throws') {
            throw new RuntimeException('cache is down');
        }

        if (isset($expires[$key]) && $expires[$key] <= now()->getTimestamp()) {
            unset($store[$key], $expires[$key]);
        }

        return $store[$key] ?? null;
    });
    $cache->shouldReceive('put')->andReturnUsing(function ($key, $value) use (&$store, $save, $fail) {
        return $save === 'works' ? (bool) ($store[$key] = $value) : $fail();
    });
    $cache->shouldReceive('add')->andReturnUsing(function ($key, $value, $ttl) use (&$store, &$expires, $save, $fail) {
        if ($save !== 'works') {
            return $fail();
        }

        if (isset($store[$key]) && ($expires[$key] ?? PHP_INT_MAX) > now()->getTimestamp()) {
            return false;
        }

        $expires[$key] = now()->getTimestamp() + $ttl;

        return (bool) ($store[$key] = $value);
    });
    $cache->shouldReceive('forget')->andReturnUsing(function ($key) use (&$store, $save, $fail) {
        if ($save !== 'works') {
            return $fail();
        }

        unset($store[$key]);

        return true;
    });
    app()->instance(Cache::class, $cache);

    sendSpan();
    expect($log->warnings[0])->toContain("pause for {$pause} seconds");

    sendSpan();
    $this->travel($pause - 1)->seconds();
    sendSpan();
    Http::assertSentCount(1);

    $this->travel(2)->seconds();
    sendSpan();
    Http::assertSentCount(2);
})->with([
    // [what the cache holds, how saving goes, the pause]
    ['nothing', 'works', 30],
    ['nothing', 'returns false', 30],
    ['nothing', 'throws', 30],
    ['an old pause', 'works', 60],
    ['an old pause', 'returns false', 60],
    ['an old pause', 'throws', 60],
    ['throws', 'works', 30],
    ['throws', 'returns false', 30],
    ['throws', 'throws', 30],
]);
