<?php

use Anantrp\Refract\Capture\Content;
use Anantrp\Refract\Capture\FlushPoints;
use Anantrp\Refract\Capture\RecordAgentRuns;
use Anantrp\Refract\Capture\RunContext;
use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Contracts\Transport;
use Anantrp\Refract\Export\Platforms\Langfuse\LangfusePlatform;
use Anantrp\Refract\Export\Platforms\Otlp\OtlpPlatform;
use Anantrp\Refract\RefractServiceProvider;
use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Tests\Support\Env;
use Anantrp\Refract\Tests\Support\Otlp;
use Anantrp\Refract\Tests\Support\WarningLog;
use Illuminate\Config\Repository;
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
    'OTEL_EXPORTER_OTLP_COMPRESSION' => null,
    'LANGFUSE_BASE_URL' => null,
    'LANGFUSE_PUBLIC_KEY' => null,
    'LANGFUSE_SECRET_KEY' => null,
    'REFRACT_CONTEXT_PARTICIPANT_TYPE' => null,
    'REFRACT_CONTEXT_PARTICIPANT_ID' => null,
    'OTEL_INSTRUMENTATION_GENAI_CAPTURE_MESSAGE_CONTENT' => null,
    'REFRACT_CAPTURE_MAX_BYTES' => null,
];

/**
 * The env of a working Langfuse destination.
 */
const LANGFUSE_ENV = [
    'REFRACT_DESTINATION' => 'langfuse',
    'LANGFUSE_PUBLIC_KEY' => 'pk-test',
    'LANGFUSE_SECRET_KEY' => 'sk-test',
];

/**
 * The traces URL of Langfuse Cloud.
 */
const LANGFUSE_CLOUD = 'https://cloud.langfuse.com/api/public/otel/v1/traces';

/**
 * Other values for every env var, set after the config is cached. A cached config must not see them.
 */
const CHANGED_ENV = [
    'REFRACT_ENABLED' => 'false',
    'OTEL_SERVICE_NAME' => 'changed',
    'REFRACT_ENVIRONMENT' => 'changed',
    'REFRACT_TRANSPORT' => 'queue',
    'REFRACT_DESTINATION' => 'langfuse',
    'REFRACT_OTLP_ENDPOINT' => 'https://changed.test/v1/traces',
    'REFRACT_OTLP_HEADERS' => 'x-changed=1',
    'OTEL_EXPORTER_OTLP_ENDPOINT' => 'https://changed.test',
    'OTEL_EXPORTER_OTLP_HEADERS' => 'x-changed=1',
    'OTEL_EXPORTER_OTLP_COMPRESSION' => 'none',
    'LANGFUSE_BASE_URL' => 'https://changed.test',
    'LANGFUSE_PUBLIC_KEY' => 'pk-changed',
    'LANGFUSE_SECRET_KEY' => 'sk-changed',
    'REFRACT_CONTEXT_PARTICIPANT_TYPE' => 'changed_type',
    'REFRACT_CONTEXT_PARTICIPANT_ID' => 'changed_id',
    'OTEL_INSTRUMENTATION_GENAI_CAPTURE_MESSAGE_CONTENT' => 'true',
    'REFRACT_CAPTURE_MAX_BYTES' => '1000',
];

/**
 * Load config/refract.php again from the current env, rebuild Refract's services and record warnings.
 *
 * Cached: write the loaded config the way config:cache does, change every
 * env var, then load the app from that file the way Laravel loads a cached
 * config, so config/refract.php is not read again.
 */
function loadRefract(bool $cached = false): WarningLog
{
    config(['refract' => [], 'app.name' => 'Refract Test App']);

    (new RefractServiceProvider(app()))->register();

    if ($cached) {
        $path = tempnam(sys_get_temp_dir(), 'refract-config');
        file_put_contents($path, '<?php return '.var_export(config()->all(), true).';'.PHP_EOL);

        Env::set(CHANGED_ENV);

        app()->instance('config_loaded_from_cache', true);
        app()->instance('config', new Repository(require $path));
        unlink($path);

        (new RefractServiceProvider(app()))->register();
    }

    Diagnostics::reset();
    Log::swap($log = new WarningLog);
    Http::fake();

    return $log;
}

/**
 * Send one run span through the configured transport and get the request it made, or null.
 *
 * @return array{url: string, headers: array<string, string>, encoding: string|null, resource: array<string, mixed>}|null
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
        if (! in_array(strtolower($name), ['content-type', 'content-length', 'content-encoding', 'user-agent', 'host'], true)) {
            $headers[strtolower($name)] = implode(',', $values);
        }
    }

    ksort($headers);

    $encoding = $request->header('Content-Encoding');

    return ['url' => $request->url(), 'headers' => $headers, 'encoding' => $encoding === [] ? null : implode(',', $encoding), 'resource' => Otlp::resource()];
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
 * A var whose invalid value does not give the default names the result it
 * gives ("on_invalid") and the warnings in all ("invalid_warnings").
 *
 * @return array<string, array{env: array<string, string|null>, invalid: string, read: Closure(): mixed, default: mixed, warnings: int, on_invalid?: mixed, invalid_warnings?: int}>
 */
function envCases(): array
{
    $url = fn () => exported()['url'] ?? null;
    $headers = fn () => exported()['headers'] ?? null;
    $collector = ['REFRACT_OTLP_ENDPOINT' => null, 'OTEL_EXPORTER_OTLP_ENDPOINT' => 'https://collector.test:4318'];

    $resource = fn (string $name) => fn () => exported()['resource'][$name] ?? null;
    $participant = fn () => participantFromKeys(app(RunContext::class), 'refract.participant_type', 'refract.participant_id');
    $captures = fn () => app(Content::class)->toolArguments(['topic' => 'release']) !== [];
    $cap = fn () => strlen(app(Content::class)->value(str_repeat('a', 200_000)));

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
            // No fallback to the OTEL endpoint: REFRACT_OTLP_HEADERS never go to a host the user did not name.
            'on_invalid' => null, 'invalid_warnings' => 1,
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
        'OTEL_EXPORTER_OTLP_COMPRESSION' => [
            'env' => [], 'invalid' => 'brotli', 'read' => fn () => exported()['encoding'] ?? null, 'default' => 'gzip', 'warnings' => 0,
        ],
        'LANGFUSE_BASE_URL' => [
            'env' => LANGFUSE_ENV, 'invalid' => 'not a url', 'read' => $url, 'default' => LANGFUSE_CLOUD, 'warnings' => 0,
            // No fallback to Langfuse Cloud: the keys never go to a host the user did not name.
            'on_invalid' => null, 'invalid_warnings' => 1,
        ],
        'LANGFUSE_PUBLIC_KEY' => [
            'env' => LANGFUSE_ENV, 'invalid' => 'true', 'read' => fn () => exported(), 'default' => null, 'warnings' => 1,
        ],
        'LANGFUSE_SECRET_KEY' => [
            'env' => LANGFUSE_ENV, 'invalid' => 'true', 'read' => fn () => exported(), 'default' => null, 'warnings' => 1,
        ],
        'OTEL_INSTRUMENTATION_GENAI_CAPTURE_MESSAGE_CONTENT' => [
            'env' => [], 'invalid' => 'maybe', 'read' => $captures, 'default' => false, 'warnings' => 0,
        ],
        'REFRACT_CAPTURE_MAX_BYTES' => [
            'env' => [], 'invalid' => 'lots', 'read' => $cap, 'default' => 131_072, 'warnings' => 0,
        ],
    ];
}

/**
 * Run the given env var case with the var set to the given value (null: not set).
 *
 * @return array{mixed, list<string>}
 */
function runEnvCase(string $var, ?string $value, bool $cached = false): array
{
    $case = envCases()[$var];

    Env::set([...BASE_ENV, ...$case['env'], $var => $value]);

    $log = loadRefract($cached);

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

/**
 * Get the result and the number of warnings the given env var case gives with an invalid value.
 *
 * @return array{mixed, int}
 */
function invalidOutcome(string $var): array
{
    $case = envCases()[$var];

    return array_key_exists('on_invalid', $case)
        ? [$case['on_invalid'], $case['invalid_warnings'] ?? $case['warnings'] + 1]
        : [$case['default'], $case['warnings'] + 1];
}

it('C3: an invalid env var gives the default and one warning', function (string $var) {
    [$result, $warnings] = runEnvCase($var, envCases()[$var]['invalid']);
    [$expected, $count] = invalidOutcome($var);

    $about = array_filter($warnings, fn (string $message) => str_contains($message, 'Refract config [refract.'));

    expect($result)->toBe($expected)
        ->and($warnings)->toHaveCount($count)
        ->and($about)->toHaveCount(1);
})->with('env vars');

it('C3: an invalid LANGFUSE_BASE_URL or REFRACT_OTLP_ENDPOINT exports nothing, with one warning, to no fallback host', function (array $env, string $var, string $value) {
    Env::set([...BASE_ENV, ...$env, $var => $value]);

    $log = loadRefract();

    expect(exported())->toBeNull()
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain($var === 'LANGFUSE_BASE_URL' ? 'refract.destinations.langfuse.url' : 'refract.destinations.otlp.endpoint')
        ->and($log->warnings[0])->toContain('Nothing is exported');
    Http::assertNothingSent();
})->with([
    'LANGFUSE_BASE_URL' => [LANGFUSE_ENV, 'LANGFUSE_BASE_URL'],
    'REFRACT_OTLP_ENDPOINT' => [['OTEL_EXPORTER_OTLP_ENDPOINT' => 'https://collector.test:4318', 'REFRACT_OTLP_HEADERS' => 'x-secret=1'], 'REFRACT_OTLP_ENDPOINT'],
])->with([
    // env() turns "true" and "(false)" into booleans, which are not URLs either.
    'text' => 'not a url',
    'true' => 'true',
    '(false)' => '(false)',
]);

it('C3: a destination whose platform is not a Platform class exports nothing, with one warning', function (mixed $platform) {
    Env::set(BASE_ENV);

    $log = loadRefract();
    config(['refract.destinations.otlp.platform' => $platform]);

    expect(exported())->toBeNull()
        ->and(exported())->toBeNull()
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('refract.destinations.otlp.platform');
    Http::assertNothingSent();
})->with([
    'missing class' => ['App\\Platforms\\Missing'],
    'not a platform' => [stdClass::class],
    'not a string' => [['App\\Platforms\\Missing']],
    'empty' => [null],
]);

it('C2: with no otlp destination in the config and REFRACT_DESTINATION missing or empty, one warning names the missing default destination and nothing is exported', function (?string $destination) {
    Env::set([...BASE_ENV, 'REFRACT_DESTINATION' => $destination]);

    $log = loadRefract();
    config(['refract.destinations' => ['langfuse' => config('refract.destinations.langfuse')]]);

    expect(exported())->toBeNull()
        ->and(exported())->toBeNull()
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('refract.destinations.otlp')
        ->and($log->warnings[0])->toContain('REFRACT_DESTINATION is not set')
        ->and($log->warnings[0])->toContain('Nothing is exported')
        ->and($log->warnings[0])->not->toContain('[refract.destination]');
    Http::assertNothingSent();
})->with([
    'missing' => [null],
    'empty' => [''],
]);

it('C1: each destination names its platform class in the config', function () {
    Env::set(BASE_ENV);

    loadRefract();

    expect(config('refract.destinations.otlp.platform'))->toBe(OtlpPlatform::class)
        ->and(config('refract.destinations.langfuse.platform'))->toBe(LangfusePlatform::class);
});

/**
 * The URL env vars, the env each is tested in, a value with an underscore or
 * non-ASCII host, and the URL Refract posts to with that value.
 *
 * @return array<string, array{array<string, string|null>, string, string, string}>
 */
function urlHostCases(): array
{
    return [
        'REFRACT_OTLP_ENDPOINT underscore' => [[], 'REFRACT_OTLP_ENDPOINT', 'http://otel_collector:4318/v1/traces', 'http://otel_collector:4318/v1/traces'],
        'OTEL_EXPORTER_OTLP_ENDPOINT underscore' => [['REFRACT_OTLP_ENDPOINT' => null], 'OTEL_EXPORTER_OTLP_ENDPOINT', 'http://otel_collector:4318', 'http://otel_collector:4318/v1/traces'],
        'LANGFUSE_BASE_URL underscore' => [LANGFUSE_ENV, 'LANGFUSE_BASE_URL', 'http://langfuse_web:3000', 'http://langfuse_web:3000/api/public/otel/v1/traces'],
        'LANGFUSE_BASE_URL non-ASCII' => [LANGFUSE_ENV, 'LANGFUSE_BASE_URL', 'https://langfuse.bücher.test', 'https://langfuse.bücher.test/api/public/otel/v1/traces'],
    ];
}

it('C1: a URL with an underscore or non-ASCII host is used, not the default', function (array $env, string $var, string $value, string $url) {
    Env::set([...BASE_ENV, ...$env, $var => $value]);

    loadRefract();

    expect(exported()['url'] ?? null)->toBe($url);
})->with(urlHostCases());

it('C3: a URL with an underscore or non-ASCII host is not invalid and gives no warning', function (array $env, string $var, string $value) {
    Env::set([...BASE_ENV, ...$env, $var => $value]);

    $log = loadRefract();
    exported();

    expect($log->warnings)->toBe([]);
})->with(urlHostCases());

it('C3: a URL with a scheme other than http or https, or with no host, is invalid', function (array $env, string $var) {
    foreach (['ftp://otel_collector:4318', 'http://', 'otel_collector:4318', 'http:///v1/traces'] as $value) {
        Env::set([...BASE_ENV, ...$env, $var => $value]);

        $log = loadRefract();

        expect(exported()['url'] ?? '')->not->toContain('otel_collector')
            ->and(array_filter($log->warnings, fn (string $message) => str_contains($message, 'must be an http or https URL')))->toHaveCount(1);
    }
})->with(urlHostCases());

it('C3: a byte cap under 64, 0, negative, a fraction or text gives 128 KB and one warning', function (string $value) {
    [$result, $warnings] = runEnvCase('REFRACT_CAPTURE_MAX_BYTES', $value);

    expect($result)->toBe(131_072)
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0])->toContain('refract.capture.max_bytes');
})->with(['63', '1', '0', '-5', '1.5', '1e3', '128KB']);

it('C1: a byte cap of 64, the minimum, is used', function () {
    [$result, $warnings] = runEnvCase('REFRACT_CAPTURE_MAX_BYTES', '64');

    expect($result)->toBe(64)
        ->and($warnings)->toBe([]);
});

it('C1: capture turned on and a byte cap set are used', function () {
    Env::set([...BASE_ENV, 'OTEL_INSTRUMENTATION_GENAI_CAPTURE_MESSAGE_CONTENT' => 'true', 'REFRACT_CAPTURE_MAX_BYTES' => '1000']);

    $log = loadRefract();

    expect(app(Content::class)->toolArguments(['topic' => 'release']))->toBe(['arguments' => '{"topic":"release"}'])
        ->and(strlen(app(Content::class)->value(str_repeat('a', 200_000))))->toBe(1000)
        ->and($log->warnings)->toBe([]);
});

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

it('C5: the transport and exporter are built on the first send, so no endpoint warns only once spans are sent', function () {
    $this->refreshApplicationWithConfig(['refract.transport' => 'sync', 'refract.destination' => 'otlp']);

    expect(app()->resolved(Transport::class))->toBeFalse()
        ->and(app()->resolved(Exporter::class))->toBeFalse();

    Diagnostics::reset();
    Log::swap($log = new WarningLog);
    Http::fake();

    // A flush point with nothing recorded, as in a request or command that runs no agent.
    app(FlushPoints::class)->flush();

    expect($log->warnings)->toBe([])
        ->and(app()->resolved(Transport::class))->toBeFalse();

    TimeAgent::fakeTwoSteps();
    TimeAgent::make()->prompt('What time is it?');
    app(FlushPoints::class)->flush();

    expect($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain('No OTLP endpoint')
        ->and(app()->resolved(Exporter::class))->toBeTrue();
    Http::assertNothingSent();
});

it('C6: LANGFUSE_BASE_URL empty sends to Langfuse Cloud', function () {
    Env::set([...BASE_ENV, ...LANGFUSE_ENV, 'LANGFUSE_BASE_URL' => '']);

    $log = loadRefract();

    expect(exported()['url'] ?? null)->toBe(LANGFUSE_CLOUD)
        ->and($log->warnings)->toBe([]);
});

it('C6: LANGFUSE_BASE_URL set sends to that Langfuse', function () {
    Env::set([...BASE_ENV, ...LANGFUSE_ENV, 'LANGFUSE_BASE_URL' => 'https://langfuse.example.test/']);

    loadRefract();

    expect(exported()['url'] ?? null)->toBe('https://langfuse.example.test/api/public/otel/v1/traces');
});

it('C8: Langfuse keys missing export nothing and warn once', function (array $keys) {
    Env::set([...BASE_ENV, ...LANGFUSE_ENV, ...$keys]);

    $log = loadRefract();

    expect(exported())->toBeNull()
        ->and(exported())->toBeNull()
        ->and($log->warnings)->toHaveCount(1);
})->with([
    'both' => [['LANGFUSE_PUBLIC_KEY' => null, 'LANGFUSE_SECRET_KEY' => null]],
    'public key' => [['LANGFUSE_PUBLIC_KEY' => null]],
    'secret key' => [['LANGFUSE_SECRET_KEY' => '']],
]);

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

/**
 * The C4 to C8 cases: the env, how to read the result, the result and the warnings it gives.
 *
 * @return array<string, array{env: array<string, string|null>, read: Closure(): mixed, expected: mixed, warnings: int}>
 */
function destinationCases(): array
{
    $sent = fn () => ($request = exported()) === null ? null : ['url' => $request['url'], 'headers' => $request['headers']];

    return [
        'C4: OTEL headers not sent to the Refract endpoint' => [
            'env' => [
                'REFRACT_OTLP_HEADERS' => 'x-refract=1',
                'OTEL_EXPORTER_OTLP_ENDPOINT' => 'https://collector.test:4318',
                'OTEL_EXPORTER_OTLP_HEADERS' => 'x-otel=1',
            ],
            'read' => $sent, 'expected' => ['url' => 'https://otlp.test/v1/traces', 'headers' => ['x-refract' => '1']], 'warnings' => 0,
        ],
        'C4: OTEL endpoint with its headers' => [
            'env' => [
                'REFRACT_OTLP_ENDPOINT' => null,
                'OTEL_EXPORTER_OTLP_ENDPOINT' => 'https://collector.test:4318',
                'OTEL_EXPORTER_OTLP_HEADERS' => 'x-otel=a%3Db',
            ],
            'read' => $sent, 'expected' => ['url' => 'https://collector.test:4318/v1/traces', 'headers' => ['x-otel' => 'a=b']], 'warnings' => 0,
        ],
        'C5: no OTLP endpoint' => [
            'env' => ['REFRACT_OTLP_ENDPOINT' => null], 'read' => $sent, 'expected' => null, 'warnings' => 1,
        ],
        'C6: LANGFUSE_BASE_URL empty' => [
            'env' => [...LANGFUSE_ENV, 'LANGFUSE_BASE_URL' => ''], 'read' => $sent,
            'expected' => ['url' => LANGFUSE_CLOUD, 'headers' => [
                'authorization' => 'Basic '.base64_encode('pk-test:sk-test'),
                'x-langfuse-ingestion-version' => '4',
            ]],
            'warnings' => 0,
        ],
        'C8: Langfuse keys missing' => [
            'env' => [...LANGFUSE_ENV, 'LANGFUSE_SECRET_KEY' => null], 'read' => $sent, 'expected' => null, 'warnings' => 1,
        ],
    ];
}

/**
 * Every C1 to C6 and C8 case, by name.
 *
 * @return list<string>
 */
function allConfigCases(): array
{
    $cases = [];

    foreach (['C1', 'C2', 'C3'] as $column) {
        foreach (array_keys(envCases()) as $var) {
            $cases[] = "{$column}: {$var}";
        }
    }

    return [...$cases, ...array_keys(destinationCases())];
}

it('C7: under config:cache every case gives the same result, and env changes after caching do not matter', function (string $case) {
    [$column, $name] = explode(': ', $case, 2);

    if (in_array($column, ['C1', 'C2', 'C3'], true)) {
        $value = ['C1' => null, 'C2' => '', 'C3' => envCases()[$name]['invalid']][$column];

        [$fresh] = runEnvCase($name, $value);
        [$cached, $cachedWarnings] = runEnvCase($name, $value, cached: true);

        [$expected, $warnings] = $column === 'C3'
            ? invalidOutcome($name)
            : [envCases()[$name]['default'], envCases()[$name]['warnings']];
    } else {
        $destination = destinationCases()[$case];

        Env::set([...BASE_ENV, ...$destination['env']]);
        loadRefract();
        $fresh = ($destination['read'])();

        Env::set([...BASE_ENV, ...$destination['env']]);
        $log = loadRefract(cached: true);
        $cached = ($destination['read'])();
        $cachedWarnings = $log->warnings;

        $expected = $destination['expected'];
        $warnings = $destination['warnings'];
    }

    expect(app()->configurationIsCached())->toBeTrue()
        ->and($fresh)->toBe($expected)
        ->and($cached)->toBe($expected)
        ->and($cachedWarnings)->toHaveCount($warnings);
})->with(allConfigCases());
