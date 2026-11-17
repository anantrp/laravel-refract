<?php

use Anantrp\Refract\Tests\Support\Otlp;
use Anantrp\Refract\Transport\ExportBreaker;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/*
 * php artisan refract:test sends one test trace straight to the
 * destination, past the drain and any pause, and says what came back.
 */

it('sends one trace and names it when the destination takes it', function () {
    bootExport();
    fakeDestination(200);

    $exit = Artisan::call('refract:test');

    Http::assertSentCount(1);

    $span = Otlp::spans()[0];

    expect($exit)->toBe(0)
        ->and($span['name'])->toContain('refract:test')
        ->and(Artisan::output())->toBe("Sent. Look for trace {$span['traceId']} in langfuse.\n");
});

it('says the keys or endpoint are wrong when the destination rejects it', function () {
    bootExport();
    fakeDestination(400);

    $this->artisan('refract:test')
        ->expectsOutputToContain('Rejected: check the keys and endpoint.')
        ->assertExitCode(1);
});

it('says the destination could not be reached', function (int|string $status) {
    bootExport();
    fakeDestination($status);

    $this->artisan('refract:test')
        ->expectsOutputToContain('Could not reach the destination, or it answered 408, 429 or 5xx.')
        ->assertExitCode(1);
})->with(['network' => ['network'], '503' => [503]]);

it('the breaker being open does not stop the test send', function () {
    bootExport(['refract.drain' => 'sync']);
    (new ExportBreaker(app()))->failed();
    fakeDestination(200);

    $this->artisan('refract:test')->assertExitCode(0);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'otel'));
    Http::assertSentCount(1);
});

it('says when the destination is not set up', function () {
    $this->refreshApplicationWithConfig(['refract.destinations.otlp.endpoint' => '']);

    $this->artisan('refract:test')
        ->expectsOutputToContain('cannot send')
        ->assertExitCode(1);
});
