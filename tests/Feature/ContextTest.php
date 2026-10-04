<?php

use Anantrp\Refract\Capture\Recorder;
use Anantrp\Refract\Tests\Support\Otlp;
use Illuminate\Support\Facades\Http;
use Workbench\App\Ai\Agents\TimeAgent;

beforeEach(function () {
    $this->refreshApplicationWithConfig([
        'refract.transport' => 'sync',
        'refract.destination' => 'langfuse',
        'refract.destinations.langfuse.public_key' => 'pk-test',
        'refract.destinations.langfuse.secret_key' => 'sk-test',
    ]);

    Http::fake();
});

it('X9: APP_ENV "Staging EU 1" is sent to Langfuse as staging-eu-1', function () {
    $this->refreshApplicationWithConfig([
        ...$this->environmentConfig,
        'app.env' => 'Staging EU 1',
    ]);

    Http::fake();

    TimeAgent::fakeTwoSteps();

    TimeAgent::make()->prompt('What time is it?');

    app(Recorder::class)->flush();

    expect(app()->environment())->toBe('Staging EU 1')
        ->and(Otlp::resource())->toHaveKey('deployment.environment.name', 'staging-eu-1');
});

it('X9: REFRACT_ENVIRONMENT wins over APP_ENV', function () {
    $this->refreshApplicationWithConfig([
        ...$this->environmentConfig,
        'refract.environment' => 'Production',
    ]);

    Http::fake();

    TimeAgent::fakeTwoSteps();

    TimeAgent::make()->prompt('What time is it?');

    app(Recorder::class)->flush();

    expect(Otlp::resource())->toHaveKey('deployment.environment.name', 'production');
});

it('X11: service.name is OTEL_SERVICE_NAME when set, else the app name', function (?string $serviceName, string $expected) {
    $this->refreshApplicationWithConfig([
        ...$this->environmentConfig,
        'app.name' => 'Shop',
        'refract.service_name' => $serviceName,
    ]);

    Http::fake();

    TimeAgent::fakeTwoSteps();

    TimeAgent::make()->prompt('What time is it?');

    app(Recorder::class)->flush();

    expect(Otlp::resource())->toHaveKey('service.name', $expected);
})->with([
    'set' => ['checkout-api', 'checkout-api'],
    'not set' => [null, 'Shop'],
]);
