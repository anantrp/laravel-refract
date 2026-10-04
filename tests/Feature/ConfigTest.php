<?php

use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Tests\Support\Otlp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * Each env var is read through config: a missing var is null, an empty one
 * is "", and "true" is the boolean true, which no name setting accepts.
 */

/**
 * Build the given service again from the given config, with the log spied on.
 */
function resolveWithConfig(string $abstract, array $config): mixed
{
    config($config);

    Diagnostics::reset();
    Log::spy();

    app()->forgetInstance($abstract);

    return app($abstract);
}

/**
 * Get the service.name the exporter sends.
 */
function exportedServiceName(Exporter $exporter): mixed
{
    Http::fake();

    $exporter->export([[
        'v' => 1, 'trace_id' => str_repeat('a', 32), 'span_id' => str_repeat('b', 16), 'parent_span_id' => null,
        'kind' => 'invoke_agent', 'start' => 1, 'end' => 2, 'status' => 'ok', 'status_message' => null,
        'call' => [], 'content' => [], 'context' => [], 'events' => [],
    ]]);

    return Otlp::resource()['service.name'] ?? null;
}

beforeEach(function () {
    $this->refreshApplicationWithConfig([
        'refract.destination' => 'langfuse',
        'refract.destinations.langfuse.public_key' => 'pk-test',
        'refract.destinations.langfuse.secret_key' => 'sk-test',
        'app.name' => 'Shop',
    ]);
});

it('C1: OTEL_SERVICE_NAME missing gives the app name', function () {
    expect(exportedServiceName(resolveWithConfig(Exporter::class, ['refract.service_name' => null])))->toBe('Shop');

    Log::shouldNotHaveReceived('warning');
});

it('C2: OTEL_SERVICE_NAME empty gives the app name with no warning', function () {
    expect(exportedServiceName(resolveWithConfig(Exporter::class, ['refract.service_name' => ''])))->toBe('Shop');

    Log::shouldNotHaveReceived('warning');
});

it('C3: OTEL_SERVICE_NAME invalid gives the app name and one warning', function () {
    expect(exportedServiceName(resolveWithConfig(Exporter::class, ['refract.service_name' => true])))->toBe('Shop');

    Log::shouldHaveReceived('warning')->once();
});
