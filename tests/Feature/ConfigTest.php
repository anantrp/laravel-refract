<?php

use Anantrp\Refract\Capture\Content;
use Anantrp\Refract\Capture\FlushPoints;
use Anantrp\Refract\Capture\RecordAgentRuns;
use Anantrp\Refract\Contracts\Collector;
use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\RefractServiceProvider;
use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Tests\Support\Env;
use Anantrp\Refract\Tests\Support\Otlp;
use Anantrp\Refract\Tests\Support\WarningLog;
use Anantrp\Refract\Transport\Collectors\RedisCollector;
use Anantrp\Refract\Transport\Drain;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Redis\Connections\Connection as RedisConnection;
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
    'REFRACT_DESTINATION' => 'otlp',
    'REFRACT_OTLP_ENDPOINT' => 'https://otlp.test/v1/traces',
    'REFRACT_OTLP_HEADERS' => null,
    'LANGFUSE_BASE_URL' => null,
    'LANGFUSE_PUBLIC_KEY' => null,
    'LANGFUSE_SECRET_KEY' => null,
    'REFRACT_DRAIN' => null,
    'REFRACT_COLLECTOR' => null,
    'OTEL_INSTRUMENTATION_GENAI_CAPTURE_MESSAGE_CONTENT' => null,
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
    'REFRACT_DESTINATION' => 'langfuse',
    'REFRACT_OTLP_ENDPOINT' => 'https://changed.test/v1/traces',
    'REFRACT_OTLP_HEADERS' => 'x-changed=1',
    'LANGFUSE_BASE_URL' => 'https://changed.test',
    'LANGFUSE_PUBLIC_KEY' => 'pk-changed',
    'LANGFUSE_SECRET_KEY' => 'sk-changed',
    'REFRACT_DRAIN' => 'queue',
    'REFRACT_COLLECTOR' => 'database',
    'OTEL_INSTRUMENTATION_GENAI_CAPTURE_MESSAGE_CONTENT' => 'true',
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
 * Send one run span through the drain and get the request it made, or null when Refract registered nothing.
 *
 * @return array{url: string, headers: array<string, string>, encoding: string|null, resource: array<string, mixed>}|null
 */
function exported(): ?array
{
    if (! listening()) {
        return null;
    }

    app(Drain::class)->send([[[
        'v' => 1, 'trace_id' => str_repeat('a', 32), 'span_id' => str_repeat('b', 16), 'parent_span_id' => null,
        'kind' => 'invoke_agent', 'start' => 1, 'end' => 2, 'status' => 'ok', 'status_message' => null,
        'call' => [], 'content' => [], 'context' => [], 'events' => [],
    ]]]);

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
    $captures = fn () => app(Content::class)->toolArguments(['topic' => 'release']) !== [];

    return [
        'REFRACT_ENABLED' => [
            'env' => [], 'invalid' => 'maybe', 'read' => fn () => listening(), 'default' => true, 'warnings' => 0,
        ],
        'REFRACT_DESTINATION' => [
            'env' => [], 'invalid' => 'datadog', 'read' => $url, 'default' => 'https://otlp.test/v1/traces', 'warnings' => 0,
        ],
        'REFRACT_OTLP_ENDPOINT' => [
            // No endpoint: one warning, and Refract registers nothing.
            'env' => [], 'invalid' => 'not a url', 'read' => $url, 'default' => null, 'warnings' => 1,
            // No fallback host: REFRACT_OTLP_HEADERS never go to a host the user did not name.
            'on_invalid' => null, 'invalid_warnings' => 1,
        ],
        'REFRACT_OTLP_HEADERS' => [
            'env' => [], 'invalid' => 'x-team', 'read' => $headers, 'default' => [], 'warnings' => 0,
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
        'REFRACT_DRAIN' => [
            'env' => [], 'invalid' => 'later', 'read' => fn () => (fn () => $this->drain)->call(app(Drain::class)), 'default' => 'sync', 'warnings' => 0,
        ],
        'REFRACT_COLLECTOR' => [
            'env' => [], 'invalid' => 'file', 'read' => fn () => collectorClass(), 'default' => RedisCollector::class, 'warnings' => 0,
        ],
        'OTEL_INSTRUMENTATION_GENAI_CAPTURE_MESSAGE_CONTENT' => [
            'env' => [], 'invalid' => 'maybe', 'read' => $captures, 'default' => false, 'warnings' => 0,
        ],
    ];
}

/**
 * Get the class of the collector Refract builds, with no Redis server needed.
 */
function collectorClass(): string
{
    app()->instance(RedisFactory::class, new class implements RedisFactory
    {
        public function connection($name = null)
        {
            return Mockery::mock(RedisConnection::class);
        }
    });

    return app(Collector::class)::class;
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

it('an env var that is missing gives the default', function (string $var) {
    [$result, $warnings] = runEnvCase($var, null);

    expect($result)->toBe(envCases()[$var]['default'])
        ->and($warnings)->toHaveCount(envCases()[$var]['warnings']);
})->with('env vars');

it('an env var set to empty gives the default with no warning about it', function (string $var) {
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

it('an invalid env var gives the default and one warning', function (string $var) {
    [$result, $warnings] = runEnvCase($var, envCases()[$var]['invalid']);
    [$expected, $count] = invalidOutcome($var);

    $about = array_filter($warnings, fn (string $message) => str_contains($message, 'Refract config [refract.'));

    expect($result)->toBe($expected)
        ->and($warnings)->toHaveCount($count)
        ->and($about)->toHaveCount(1);
})->with('env vars');

it('an invalid LANGFUSE_BASE_URL or REFRACT_OTLP_ENDPOINT registers nothing, with one warning, and goes to no fallback host', function (array $env, string $var, string $value) {
    Env::set([...BASE_ENV, ...$env, $var => $value]);

    $log = loadRefract();

    expect(listening())->toBeFalse()
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain($var === 'LANGFUSE_BASE_URL' ? 'refract.destinations.langfuse.url' : 'refract.destinations.otlp.endpoint')
        ->and($log->warnings[0])->toContain('Refract records nothing');
    Http::assertNothingSent();
})->with([
    'LANGFUSE_BASE_URL' => [LANGFUSE_ENV, 'LANGFUSE_BASE_URL'],
    'REFRACT_OTLP_ENDPOINT' => [['REFRACT_OTLP_HEADERS' => 'x-secret=1'], 'REFRACT_OTLP_ENDPOINT'],
])->with([
    // env() turns "true" and "(false)" into booleans, which are not URLs either.
    'text' => 'not a url',
    'true' => 'true',
    '(false)' => '(false)',
]);

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
        'LANGFUSE_BASE_URL underscore' => [LANGFUSE_ENV, 'LANGFUSE_BASE_URL', 'http://langfuse_web:3000', 'http://langfuse_web:3000/api/public/otel/v1/traces'],
        'LANGFUSE_BASE_URL non-ASCII' => [LANGFUSE_ENV, 'LANGFUSE_BASE_URL', 'https://langfuse.bücher.test', 'https://langfuse.bücher.test/api/public/otel/v1/traces'],
    ];
}

it('a URL with an underscore or non-ASCII host is used, not the default', function (array $env, string $var, string $value, string $url) {
    Env::set([...BASE_ENV, ...$env, $var => $value]);

    loadRefract();

    expect(exported()['url'] ?? null)->toBe($url);
})->with(urlHostCases());

it('a URL with an underscore or non-ASCII host is not invalid and gives no warning', function (array $env, string $var, string $value) {
    Env::set([...BASE_ENV, ...$env, $var => $value]);

    $log = loadRefract();
    exported();

    expect($log->warnings)->toBe([]);
})->with(urlHostCases());

it('a URL with a scheme other than http or https, or with no host, is invalid', function (array $env, string $var) {
    foreach (['ftp://otel_collector:4318', 'http://', 'otel_collector:4318', 'http:///v1/traces'] as $value) {
        Env::set([...BASE_ENV, ...$env, $var => $value]);

        $log = loadRefract();

        expect(exported()['url'] ?? '')->not->toContain('otel_collector')
            ->and(array_filter($log->warnings, fn (string $message) => str_contains($message, 'must be an http or https URL')))->toHaveCount(1);
    }
})->with(urlHostCases());

it('a destination that cannot send warns once at boot, and Refract registers nothing, as if disabled', function (array $env, string $warning) {
    Env::set([...BASE_ENV, ...$env]);

    $log = loadRefract();

    expect(listening())->toBeFalse()
        ->and(listening())->toBeFalse()
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain($warning);
    Http::assertNothingSent();
})->with([
    'no OTLP endpoint' => [['REFRACT_OTLP_ENDPOINT' => null], 'No OTLP endpoint'],
    'invalid OTLP endpoint' => [['REFRACT_OTLP_ENDPOINT' => 'otlp.test'], 'refract.destinations.otlp.endpoint'],
    'Langfuse keys missing' => [[...LANGFUSE_ENV, 'LANGFUSE_SECRET_KEY' => null], 'Langfuse keys missing'],
    'invalid Langfuse URL' => [[...LANGFUSE_ENV, 'LANGFUSE_BASE_URL' => 'langfuse.test'], 'refract.destinations.langfuse.url'],
]);

it('with a destination that can send, the drain and exporter are built on the first send, not at boot', function () {
    $this->refreshApplicationWithConfig(['refract.destination' => 'otlp', 'refract.destinations.otlp.endpoint' => 'https://otlp.test/v1/traces']);

    expect(app()->resolved(Drain::class))->toBeFalse()
        ->and(app()->resolved(Exporter::class))->toBeFalse();

    Http::fake();

    // A flush point with nothing recorded, as in a request or command that runs no agent.
    app(FlushPoints::class)->flush();

    expect(app()->resolved(Drain::class))->toBeFalse();

    TimeAgent::fakeTwoSteps();
    TimeAgent::make()->prompt('What time is it?');
    app(FlushPoints::class)->flush();

    expect(app()->resolved(Exporter::class))->toBeTrue();
    Http::assertSentCount(1);
});

it('service.name is the app name, "Laravel" when it is empty, and the environment is the app environment', function (?string $name, string $expected) {
    Env::set(BASE_ENV);

    loadRefract();
    config(['app.name' => $name]);

    expect(exported()['resource'] ?? null)->toMatchArray([
        'service.name' => $expected,
        'deployment.environment.name' => 'testing',
    ]);
})->with([
    'app name' => ['Refract Test App', 'Refract Test App'],
    'empty' => ['', 'Laravel'],
    'missing' => [null, 'Laravel'],
]);

it('every export is gzipped', function () {
    Env::set(BASE_ENV);

    loadRefract();

    expect(exported()['encoding'] ?? null)->toBe('gzip');
});

it('config/refract.php reads exactly the ten env vars', function () {
    preg_match_all("/env\\('([A-Z_]+)'\\)/", (string) file_get_contents(dirname(__DIR__, 2).'/config/refract.php'), $matches);

    expect($matches[1])->toEqualCanonicalizing(array_keys(BASE_ENV))
        ->and($matches[1])->toHaveCount(10);
});

it('LANGFUSE_BASE_URL empty sends to Langfuse Cloud', function () {
    Env::set([...BASE_ENV, ...LANGFUSE_ENV, 'LANGFUSE_BASE_URL' => '']);

    $log = loadRefract();

    expect(exported()['url'] ?? null)->toBe(LANGFUSE_CLOUD)
        ->and($log->warnings)->toBe([]);
});

it('LANGFUSE_BASE_URL set sends to that Langfuse', function () {
    Env::set([...BASE_ENV, ...LANGFUSE_ENV, 'LANGFUSE_BASE_URL' => 'https://langfuse.example.test/']);

    loadRefract();

    expect(exported()['url'] ?? null)->toBe('https://langfuse.example.test/api/public/otel/v1/traces');
});

it('Langfuse keys missing register nothing and warn once', function (array $keys) {
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
 * The destination cases: the env, how to read the result, the result and the warnings it gives.
 *
 * @return array<string, array{env: array<string, string|null>, read: Closure(): mixed, expected: mixed, warnings: int}>
 */
function destinationCases(): array
{
    $sent = fn () => ($request = exported()) === null ? null : ['url' => $request['url'], 'headers' => $request['headers']];

    return [
        'REFRACT_OTLP_HEADERS sent to the endpoint' => [
            'env' => ['REFRACT_OTLP_HEADERS' => 'x-refract=a%3Db'],
            'read' => $sent, 'expected' => ['url' => 'https://otlp.test/v1/traces', 'headers' => ['x-refract' => 'a=b']], 'warnings' => 0,
        ],
        'no OTLP endpoint' => [
            'env' => ['REFRACT_OTLP_ENDPOINT' => null], 'read' => $sent, 'expected' => null, 'warnings' => 1,
        ],
        'LANGFUSE_BASE_URL empty' => [
            'env' => [...LANGFUSE_ENV, 'LANGFUSE_BASE_URL' => ''], 'read' => $sent,
            'expected' => ['url' => LANGFUSE_CLOUD, 'headers' => [
                'authorization' => 'Basic '.base64_encode('pk-test:sk-test'),
                'x-langfuse-ingestion-version' => '4',
            ]],
            'warnings' => 0,
        ],
        'Langfuse keys missing' => [
            'env' => [...LANGFUSE_ENV, 'LANGFUSE_SECRET_KEY' => null], 'read' => $sent, 'expected' => null, 'warnings' => 1,
        ],
    ];
}

/**
 * Every env case (missing, empty, invalid) and every destination case, by name.
 *
 * @return list<string>
 */
function allConfigCases(): array
{
    $cases = [];

    foreach (['missing', 'empty', 'invalid'] as $column) {
        foreach (array_keys(envCases()) as $var) {
            $cases[] = "{$column}: {$var}";
        }
    }

    return [...$cases, ...array_map(fn (string $name) => "destination: {$name}", array_keys(destinationCases()))];
}

it('under config:cache every case gives the same result, and env changes after caching do not matter', function (string $case) {
    [$column, $name] = explode(': ', $case, 2);

    if ($column !== 'destination') {
        $value = ['missing' => null, 'empty' => '', 'invalid' => envCases()[$name]['invalid']][$column];

        [$fresh] = runEnvCase($name, $value);
        [$cached, $cachedWarnings] = runEnvCase($name, $value, cached: true);

        [$expected, $warnings] = $column === 'invalid'
            ? invalidOutcome($name)
            : [envCases()[$name]['default'], envCases()[$name]['warnings']];
    } else {
        $destination = destinationCases()[$name];

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
