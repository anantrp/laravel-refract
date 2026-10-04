<?php

use Anantrp\Refract\Capture\RecordAgentRuns;
use Anantrp\Refract\Capture\RunContext;
use Anantrp\Refract\Contracts\Transport;
use Anantrp\Refract\RefractServiceProvider;
use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Tests\Support\Env;
use Anantrp\Refract\Tests\Support\Otlp;
use Anantrp\Refract\Tests\Support\WarningLog;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Events\PromptingAgent;
use Workbench\App\Ai\Agents\TimeAgent;

/*
 * Each case sets real environment variables and loads config/refract.php
 * again, the way the app does at boot, so the whole path is tested:
 * env var, config file, Settings, the built service.
 */

/**
 * Every env var Refract reads, and its value before each case. Null means not set.
 */
const BASE_ENV = [
    'REFRACT_ENABLED' => null,
    'OTEL_SERVICE_NAME' => null,
    'REFRACT_ENVIRONMENT' => null,
    'REFRACT_TRANSPORT' => 'sync',
    'REFRACT_DESTINATION' => 'otlp',
    'REFRACT_OTLP_ENDPOINT' => 'https://otlp.test/v1/traces',
    'REFRACT_OTLP_HEADERS' => null,
    'OTEL_EXPORTER_OTLP_ENDPOINT' => null,
    'OTEL_EXPORTER_OTLP_HEADERS' => null,
    'LANGFUSE_BASE_URL' => null,
    'LANGFUSE_PUBLIC_KEY' => null,
    'LANGFUSE_SECRET_KEY' => null,
    'REFRACT_CONTEXT_PARTICIPANT_TYPE' => null,
    'REFRACT_CONTEXT_PARTICIPANT_ID' => null,
];

/**
 * Load config/refract.php again from the current env, rebuild Refract's services and record warnings.
 */
function loadRefract(): WarningLog
{
    config(['refract' => [], 'app.name' => 'Refract Test App']);

    (new RefractServiceProvider(app()))->register();

    Diagnostics::reset();
    Log::swap($log = new WarningLog);
    Http::fake();

    return $log;
}

/**
 * Send one run span through the configured transport and get the request it made, or null.
 *
 * @return array{url: string, headers: array<string, string>, resource: array<string, mixed>}|null
 */
function exported(): ?array
{
    app(Transport::class)->send([[
        'v' => 1, 'trace_id' => str_repeat('a', 32), 'span_id' => str_repeat('b', 16), 'parent_span_id' => null,
        'kind' => 'invoke_agent', 'start' => 1, 'end' => 2, 'status' => 'ok', 'status_message' => null,
        'call' => [], 'content' => [], 'context' => [], 'events' => [],
    ]]);

    $recorded = Http::recorded();

    if ($recorded->isEmpty()) {
        return null;
    }

    /** @var Request $request */
    $request = $recorded->first()[0];

    $headers = [];

    foreach ($request->headers() as $name => $values) {
        if (! in_array(strtolower($name), ['content-type', 'content-length', 'user-agent', 'host'], true)) {
            $headers[strtolower($name)] = implode(',', $values);
        }
    }

    ksort($headers);

    return ['url' => $request->url(), 'headers' => $headers, 'resource' => Otlp::resource()];
}

/**
 * Boot Refract on a fresh event dispatcher and check whether it listens to agent runs.
 */
function listening(): bool
{
    app()->instance('events', $events = new Dispatcher(app()));
    app()->forgetInstance(RecordAgentRuns::class);

    (new RefractServiceProvider(app()))->boot();

    return $events->hasListeners(PromptingAgent::class);
}

/**
 * The cases for each env var: the env the var is tested in, an invalid value,
 * how to read the result, the default result and the warnings the default gives.
 *
 * @return array<string, array{env: array<string, string|null>, invalid: string, read: Closure(): mixed, default: mixed, warnings: int}>
 */
function envCases(): array
{
    $url = fn () => exported()['url'] ?? null;
    $headers = fn () => exported()['headers'] ?? null;
    $collector = ['REFRACT_OTLP_ENDPOINT' => null, 'OTEL_EXPORTER_OTLP_ENDPOINT' => 'https://collector.test:4318'];

    $resource = fn (string $name) => fn () => exported()['resource'][$name] ?? null;
    $participant = fn () => participantFromKeys(app(RunContext::class), 'refract.participant_type', 'refract.participant_id');

    return [
        'REFRACT_ENABLED' => [
            'env' => [], 'invalid' => 'maybe', 'read' => fn () => listening(), 'default' => true, 'warnings' => 0,
        ],
        'OTEL_SERVICE_NAME' => [
            'env' => [], 'invalid' => 'true', 'read' => $resource('service.name'), 'default' => 'Refract Test App', 'warnings' => 0,
        ],
        'REFRACT_ENVIRONMENT' => [
            'env' => [], 'invalid' => 'true', 'read' => $resource('deployment.environment.name'), 'default' => 'testing', 'warnings' => 0,
        ],
        'REFRACT_TRANSPORT' => [
            'env' => [], 'invalid' => 'log', 'read' => fn () => exported() !== null, 'default' => true, 'warnings' => 0,
        ],
        'REFRACT_DESTINATION' => [
            'env' => [], 'invalid' => 'datadog', 'read' => $url, 'default' => 'https://otlp.test/v1/traces', 'warnings' => 0,
        ],
        'REFRACT_CONTEXT_PARTICIPANT_TYPE' => [
            'env' => [], 'invalid' => 'true', 'read' => $participant, 'default' => ['type' => 'App\Models\User', 'id' => '42'], 'warnings' => 0,
        ],
        'REFRACT_CONTEXT_PARTICIPANT_ID' => [
            'env' => [], 'invalid' => 'true', 'read' => $participant, 'default' => ['type' => 'App\Models\User', 'id' => '42'], 'warnings' => 0,
        ],
        'REFRACT_OTLP_ENDPOINT' => [
            'env' => ['OTEL_EXPORTER_OTLP_ENDPOINT' => 'https://collector.test:4318'],
            'invalid' => 'not a url', 'read' => $url, 'default' => 'https://collector.test:4318/v1/traces', 'warnings' => 0,
        ],
        'REFRACT_OTLP_HEADERS' => [
            'env' => [], 'invalid' => 'x-team', 'read' => $headers, 'default' => [], 'warnings' => 0,
        ],
        'OTEL_EXPORTER_OTLP_ENDPOINT' => [
            'env' => ['REFRACT_OTLP_ENDPOINT' => null],
            'invalid' => 'not a url', 'read' => fn () => exported(), 'default' => null, 'warnings' => 1,
        ],
        'OTEL_EXPORTER_OTLP_HEADERS' => [
            'env' => $collector, 'invalid' => 'x-team', 'read' => $headers, 'default' => [], 'warnings' => 0,
        ],
    ];
}

/**
 * Run the given env var case with the var set to the given value (null: not set).
 *
 * @return array{mixed, list<string>}
 */
function runEnvCase(string $var, ?string $value): array
{
    $case = envCases()[$var];

    Env::set([...BASE_ENV, ...$case['env'], $var => $value]);

    $log = loadRefract();

    return [($case['read'])(), $log->warnings];
}

afterEach(function () {
    Env::restore();
});

dataset('env vars', fn () => array_keys(envCases()));

it('C1: an env var that is missing gives the default', function (string $var) {
    [$result, $warnings] = runEnvCase($var, null);

    expect($result)->toBe(envCases()[$var]['default'])
        ->and($warnings)->toHaveCount(envCases()[$var]['warnings']);
})->with('env vars');

it('C2: an env var set to empty gives the default with no warning about it', function (string $var) {
    [$result, $warnings] = runEnvCase($var, '');

    expect($result)->toBe(envCases()[$var]['default'])
        ->and($warnings)->toHaveCount(envCases()[$var]['warnings']);
})->with('env vars');

it('C3: an invalid env var gives the default and one warning', function (string $var) {
    [$result, $warnings] = runEnvCase($var, envCases()[$var]['invalid']);

    $about = array_filter($warnings, fn (string $message) => str_contains($message, 'Refract config [refract.'));

    expect($result)->toBe(envCases()[$var]['default'])
        ->and($warnings)->toHaveCount(envCases()[$var]['warnings'] + 1)
        ->and($about)->toHaveCount(1);
})->with('env vars');

it('C4: OTEL headers are not sent to the REFRACT_OTLP_ENDPOINT', function () {
    Env::set([
        ...BASE_ENV,
        'REFRACT_OTLP_ENDPOINT' => 'https://otlp.test/v1/traces',
        'REFRACT_OTLP_HEADERS' => 'x-refract=1',
        'OTEL_EXPORTER_OTLP_ENDPOINT' => 'https://collector.test:4318',
        'OTEL_EXPORTER_OTLP_HEADERS' => 'authorization=Bearer%20secret,x-otel=1',
    ]);

    $log = loadRefract();

    expect(exported())->toMatchArray([
        'url' => 'https://otlp.test/v1/traces',
        'headers' => ['x-refract' => '1'],
    ])->and($log->warnings)->toBe([]);
});

it('C4: OTEL_EXPORTER_OTLP_ENDPOINT gets the traces path and the URL-decoded OTEL headers', function (string $endpoint) {
    Env::set([
        ...BASE_ENV,
        'REFRACT_OTLP_ENDPOINT' => null,
        'OTEL_EXPORTER_OTLP_ENDPOINT' => $endpoint,
        'OTEL_EXPORTER_OTLP_HEADERS' => ' Authorization = Bearer%20secret , x-otel=a%3Db ',
    ]);

    $log = loadRefract();

    expect(exported())->toMatchArray([
        'url' => 'https://collector.test:4318/base/v1/traces',
        'headers' => ['authorization' => 'Bearer secret', 'x-otel' => 'a=b'],
    ])->and($log->warnings)->toBe([]);
})->with(['https://collector.test:4318/base', 'https://collector.test:4318/base/']);

it('C4: REFRACT_OTLP_HEADERS win over OTEL headers on the OTEL endpoint', function () {
    Env::set([
        ...BASE_ENV,
        'REFRACT_OTLP_ENDPOINT' => null,
        'REFRACT_OTLP_HEADERS' => 'x-team=refract',
        'OTEL_EXPORTER_OTLP_ENDPOINT' => 'https://collector.test:4318',
        'OTEL_EXPORTER_OTLP_HEADERS' => 'x-team=otel,x-otel=1',
    ]);

    loadRefract();

    expect(exported()['headers'] ?? null)->toBe(['x-otel' => '1', 'x-team' => 'refract']);
});

it('C5: no OTLP endpoint at all exports nothing and warns once', function () {
    Env::set([...BASE_ENV, 'REFRACT_OTLP_ENDPOINT' => null, 'OTEL_EXPORTER_OTLP_ENDPOINT' => null]);

    $log = loadRefract();

    expect(exported())->toBeNull()
        ->and(exported())->toBeNull()
        ->and($log->warnings)->toHaveCount(1);
});

/**
 * Get the participant a run not saved records under the given key names.
 */
function participantFromKeys(RunContext $context, string $typeKey, string $idKey): mixed
{
    Context::add($typeKey, 'App\Models\User');
    Context::add($idKey, '42');

    return $context->of(new TimeAgent)['participant'] ?? null;
}

it('reads the participant from the Context keys named in config', function () {
    Env::set([...BASE_ENV, 'REFRACT_CONTEXT_PARTICIPANT_TYPE' => 'owner_type', 'REFRACT_CONTEXT_PARTICIPANT_ID' => 'owner_id']);

    loadRefract();

    expect(participantFromKeys(app(RunContext::class), 'owner_type', 'owner_id'))->toBe(['type' => 'App\Models\User', 'id' => '42']);
});
