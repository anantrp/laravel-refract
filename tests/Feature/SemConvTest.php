<?php

use Anantrp\Refract\Capture\Recorder;
use Anantrp\Refract\Export\GenAiTranslator;
use Anantrp\Refract\Tests\Support\Otlp;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\TextResponse;
use OpenTelemetry\SemConv\Attributes\ErrorAttributes;
use Workbench\App\Ai\Agents\ChatAgent;
use Workbench\App\Ai\Agents\SupervisorAgent;
use Workbench\App\Ai\Agents\TimeAgent;
use Workbench\App\Models\User;

/*
 * Row A6: every attribute name Refract emits that the OTel semantic conventions define is spelled
 * the way open-telemetry/sem-conv (require-dev only, rule 11) spells it, and is not deprecated there.
 */

/**
 * The emitted names open-telemetry/sem-conv does not define yet. The PHP package ships no GenAI
 * incubating attributes: its gen_ai names come only from the archived TraceAttributes (schema 1.32),
 * older than these. Any other name sem-conv does not define fails, so a typo cannot pass.
 */
const SEM_CONV_NOT_DEFINED = [
    'gen_ai.input.messages',
    'gen_ai.output.messages',
    'gen_ai.provider.name',
    'gen_ai.tool.call.arguments',
    'gen_ai.tool.call.result',
];

/**
 * The prefixes of Refract's own and the platform's attribute names, outside the OTel conventions.
 */
const SEM_CONV_OWN_PREFIXES = ['laravel.', 'langfuse.'];

/**
 * Get every attribute name and value constant of open-telemetry/sem-conv, by its value.
 *
 * Each entry is the constant, whether it is a name or a value, where it comes from (stable,
 * incubating or archived) and its deprecation note, if any.
 *
 * @return array<string, list<array{constant: string, value: bool, source: string, deprecated: ?string}>>
 */
function semConvConstants(): array
{
    static $constants = null;

    if ($constants !== null) {
        return $constants;
    }

    $root = dirname(__DIR__, 2).'/vendor/open-telemetry/sem-conv';
    $classes = [
        'OpenTelemetry\\SemConv\\TraceAttributes' => 'archived',
        'OpenTelemetry\\SemConv\\ResourceAttributes' => 'archived',
        'OpenTelemetry\\SemConv\\TraceAttributeValues' => 'archived',
        'OpenTelemetry\\SemConv\\ResourceAttributeValues' => 'archived',
    ];

    foreach (['Attributes' => 'stable', 'Incubating/Attributes' => 'incubating'] as $folder => $source) {
        foreach (glob("{$root}/{$folder}/*.php") ?: [] as $file) {
            $classes['OpenTelemetry\\SemConv\\'.str_replace('/', '\\', $folder).'\\'.basename($file, '.php')] = $source;
        }
    }

    $constants = [];

    foreach ($classes as $class => $source) {
        foreach ((new ReflectionClass($class))->getReflectionConstants() as $constant) {
            $value = $constant->getValue();

            if (! is_string($value) || $constant->getName() === 'SCHEMA_URL') {
                continue;
            }

            preg_match('/@deprecated\s+(.+)/', (string) $constant->getDocComment(), $deprecated);

            $constants[$value][] = [
                'constant' => $class.'::'.$constant->getName(),
                'value' => str_ends_with($class, 'Values') || str_contains($constant->getName(), '_VALUE_'),
                'source' => $source,
                'deprecated' => $deprecated[1] ?? null,
            ];
        }
    }

    return $constants;
}

/**
 * Get the sem-conv attribute name constants of the given name, deprecated or not.
 *
 * @return list<array{constant: string, value: bool, source: string, deprecated: ?string}>
 */
function semConvNames(string $name): array
{
    return array_values(array_filter(semConvConstants()[$name] ?? [], fn (array $constant) => ! $constant['value']));
}

/**
 * Get the sem-conv attribute names spelled differently from the given name under the same constant name.
 *
 * @return list<string>
 */
function semConvOtherSpellings(string $name): array
{
    $constant = strtoupper(str_replace('.', '_', $name));
    $spellings = [];

    foreach (semConvConstants() as $value => $constants) {
        foreach ($constants as $found) {
            if (! $found['value'] && str_ends_with($found['constant'], "::{$constant}") && $value !== $name) {
                $spellings[] = "{$found['constant']} = {$value}";
            }
        }
    }

    return $spellings;
}

/**
 * Run agents that make every kind of span, event and attribute Refract emits, and get what was exported.
 *
 * @return array{names: list<string>, operations: list<string>}
 */
function semConvEmitted(): array
{
    // A sub-agent run under a tool, with content: runs, steps, tools, messages, tool arguments and results.
    SupervisorAgent::fakeTwoSteps();
    SupervisorAgent::make()->prompt('Ask for the time.');

    // A saved conversation of a user: session.id, gen_ai.conversation.id and user.id.
    ChatAgent::fake(['Hello.']);
    ChatAgent::make()->forUser((new User)->forceFill(['id' => 42]))->prompt('Hello');

    // A failover event, and a failed run: error.type.
    TimeAgent::fake(fn (string $prompt, $attachments, TextProvider $provider) => $provider->name() === 'openai'
        ? throw RateLimitedException::forProvider('openai')
        : 'It is 12:00.');
    TimeAgent::make()->prompt('What time is it?', provider: ['openai' => 'gpt-a', 'anthropic' => 'claude-b']);

    TimeAgent::fake(fn () => throw new RuntimeException('down'));
    rescue(fn () => TimeAgent::make()->prompt('What time is it?'), report: false);

    // An approval event: gen_ai.tool.call.id.
    TimeAgent::fake([
        (new TextResponse('', new TextUsage, new Meta('openai', 'fake')))
            ->withPendingApprovals(collect([new PendingApproval('call_1', 'CurrentTime', [])])),
    ]);
    TimeAgent::make()->withMessages([])->prompt('What time is it?');

    app(Recorder::class)->flush();

    $names = array_keys(Otlp::resource());
    $operations = [];

    foreach (Otlp::spans() as $span) {
        $attributes = Otlp::attributes($span);
        $names = [...$names, ...array_keys($attributes)];
        $operations[] = $attributes['gen_ai.operation.name'] ?? null;

        foreach ($span['events'] ?? [] as $event) {
            $names = [...$names, ...array_keys(Otlp::attributes($event))];
        }
    }

    $names = array_values(array_unique($names));
    sort($names);

    return [
        'names' => $names,
        'operations' => array_values(array_unique(array_filter($operations, is_string(...)))),
    ];
}

beforeEach(function () {
    $this->refreshApplicationWithConfig([
        'refract.transport' => 'sync',
        'refract.destination' => 'langfuse',
        'refract.destinations.langfuse.public_key' => 'pk-test',
        'refract.destinations.langfuse.secret_key' => 'sk-test',
        'refract.capture.content' => true,
        'ai.providers.anthropic' => ['driver' => 'anthropic', 'key' => 'test'],
    ]);

    $this->loadMigrationsFrom(dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations');

    Http::fake();
});

it('A6: the agent runs here emit every OTel attribute name Refract knows', function () {
    expect(semConvEmitted()['names'])->toContain(
        'gen_ai.operation.name', 'gen_ai.provider.name', 'gen_ai.request.model', 'gen_ai.response.model',
        'gen_ai.response.finish_reasons', 'gen_ai.usage.input_tokens', 'gen_ai.usage.output_tokens',
        'gen_ai.agent.name', 'gen_ai.tool.name', 'gen_ai.tool.type', 'gen_ai.tool.call.id',
        'gen_ai.input.messages', 'gen_ai.output.messages', 'gen_ai.tool.call.arguments', 'gen_ai.tool.call.result',
        'gen_ai.conversation.id', 'error.type', 'session.id', 'user.id', 'service.name', 'deployment.environment.name',
    );
});

it('A6: every emitted attribute name outside laravel.* and langfuse.* is an OTel semantic convention name, and none is deprecated', function () {
    $otel = array_values(array_filter(
        semConvEmitted()['names'],
        fn (string $name) => ! array_filter(SEM_CONV_OWN_PREFIXES, fn (string $prefix) => str_starts_with($name, $prefix)),
    ));

    $unknown = [];
    $deprecated = [];

    foreach ($otel as $name) {
        $constants = semConvNames($name);

        if ($constants === []) {
            $unknown[] = $name;
        } elseif (array_filter($constants, fn (array $constant) => $constant['deprecated'] === null) === []) {
            $deprecated[$name] = array_column($constants, 'deprecated');
        }
    }

    expect($otel)->not->toBe([])
        ->and($deprecated)->toBe([])
        ->and(array_values(array_diff($unknown, SEM_CONV_NOT_DEFINED)))->toBe([]);
});

it('A6: no emitted name is spelled differently from the sem-conv name of the same constant', function () {
    $mismatched = [];

    foreach (semConvEmitted()['names'] as $name) {
        array_push($mismatched, ...semConvOtherSpellings($name));
    }

    expect($mismatched)->toBe([]);
});

it('A6: every emitted gen_ai.operation.name is a sem-conv operation value', function () {
    $values = [];

    foreach (semConvConstants() as $value => $constants) {
        foreach ($constants as $constant) {
            if ($constant['value'] && preg_match('/::GEN_AI_OPERATION_NAME_/', $constant['constant']) === 1) {
                $values[] = $value;
            }
        }
    }

    expect(semConvEmitted()['operations'])->toEqualCanonicalizing(['invoke_agent', 'chat', 'execute_tool'])
        ->and($values)->toContain('invoke_agent', 'chat', 'execute_tool');
});

it('A6: the error.type of an unknown exception is the sem-conv _OTHER value', function () {
    expect(GenAiTranslator::OTHER_ERROR)->toBe(ErrorAttributes::ERROR_TYPE_VALUE_OTHER);
});
