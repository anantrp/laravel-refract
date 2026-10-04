<?php

use Anantrp\Refract\Capture\Recorder;
use Anantrp\Refract\Tests\Support\Otlp;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Workbench\App\Ai\Agents\ChatAgent;
use Workbench\App\Ai\Agents\TimeAgent;
use Workbench\App\Ai\Agents\TraitChatAgent;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->refreshApplicationWithConfig([
        'refract.transport' => 'sync',
        'refract.destination' => 'langfuse',
        'refract.destinations.langfuse.public_key' => 'pk-test',
        'refract.destinations.langfuse.secret_key' => 'sk-test',
    ]);

    $this->loadMigrationsFrom(dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations');

    Http::fake();
});

afterEach(function () {
    Relation::morphMap([], merge: false);
});

/**
 * Get the exported attributes of each run span, in start order.
 *
 * @return list<array<string, mixed>>
 */
function runAttributes(): array
{
    app(Recorder::class)->flush();

    $runs = array_filter(Otlp::spans(), fn (array $span) => str_starts_with($span['name'], 'invoke_agent'));

    return array_values(array_map(Otlp::attributes(...), $runs));
}

it('X1: a saved run with a User participant records the full class, the id and Langfuse user.id', function () {
    ChatAgent::fake(['Hello.']);

    ChatAgent::make()->forUser((new User)->forceFill(['id' => 42]))->prompt('Hello');

    [$run] = runAttributes();

    expect($run)->toMatchArray([
        'laravel.ai.participant.type' => User::class,
        'laravel.ai.participant.id' => '42',
        'user.id' => '42',
    ]);
});

it('X1: a saved run of an agent that only uses the conversation trait records its participant', function () {
    Context::add('refract.participant_type', Team::class);
    Context::add('refract.participant_id', '99');

    TraitChatAgent::fake(['Hello.']);

    TraitChatAgent::make()->forUser((new User)->forceFill(['id' => 42]))->prompt('Hello');

    [$run] = runAttributes();

    expect($run)->toMatchArray([
        'laravel.ai.participant.type' => User::class,
        'laravel.ai.participant.id' => '42',
        'user.id' => '42',
    ]);
});

it('X2: a saved run with a Team participant records the participant but no user.id', function () {
    ChatAgent::fake(['Hello.']);

    ChatAgent::make()->forParticipant((new Team)->forceFill(['id' => 7]))->prompt('Hello');

    [$run] = runAttributes();

    expect($run)->toMatchArray([
        'laravel.ai.participant.type' => Team::class,
        'laravel.ai.participant.id' => '7',
    ])->and($run)->not->toHaveKey('user.id');
});

it('X3: a saved run ignores the participant Context keys', function () {
    Context::add('refract.participant_type', Team::class);
    Context::add('refract.participant_id', '99');

    ChatAgent::fake(['Hello.']);

    ChatAgent::make()->forUser((new User)->forceFill(['id' => 42]))->prompt('Hello');

    [$run] = runAttributes();

    expect($run)->toMatchArray([
        'laravel.ai.participant.type' => User::class,
        'laravel.ai.participant.id' => '42',
        'user.id' => '42',
    ]);
});

it('X4: a run not saved takes the participant from both Context keys and turns a morph alias into the class', function () {
    Relation::morphMap(['user' => User::class]);

    Context::add('refract.participant_type', 'user');
    Context::add('refract.participant_id', 5);

    TimeAgent::fakeTwoSteps();

    TimeAgent::make()->prompt('What time is it?');

    [$run] = runAttributes();

    expect($run)->toMatchArray([
        'laravel.ai.participant.type' => User::class,
        'laravel.ai.participant.id' => '5',
        'user.id' => '5',
    ]);
});

it('X4: a Context type that is already a class name is kept', function () {
    Context::add('refract.participant_type', Team::class);
    Context::add('refract.participant_id', 'team-1');

    TimeAgent::fakeTwoSteps();

    TimeAgent::make()->prompt('What time is it?');

    [$run] = runAttributes();

    expect($run)->toMatchArray([
        'laravel.ai.participant.type' => Team::class,
        'laravel.ai.participant.id' => 'team-1',
    ])->and($run)->not->toHaveKey('user.id');
});

it('X5: a run not saved with only one Context key set records no participant', function (string $key) {
    Context::add($key, $key === 'refract.participant_type' ? User::class : '5');

    TimeAgent::fakeTwoSteps();

    TimeAgent::make()->prompt('What time is it?');

    [$run] = runAttributes();

    expect($run)->not->toHaveKey('laravel.ai.participant.type')
        ->and($run)->not->toHaveKey('laravel.ai.participant.id')
        ->and($run)->not->toHaveKey('user.id');
})->with(['refract.participant_type', 'refract.participant_id']);

it('X6: a participant id 0 is recorded as "0"', function () {
    ChatAgent::fake(['Hello.']);
    TimeAgent::fakeTwoSteps();

    ChatAgent::make()->forUser((new User)->forceFill(['id' => 0]))->prompt('Hello');

    Context::add('refract.participant_type', User::class);
    Context::add('refract.participant_id', 0);

    TimeAgent::make()->prompt('What time is it?');

    $runs = runAttributes();

    expect(array_column($runs, 'laravel.ai.participant.id'))->toBe(['0', '0'])
        ->and(array_column($runs, 'user.id'))->toBe(['0', '0']);
});

it('X7: a sub-agent run uses its parent run\'s participant and session', function () {
    ChatAgent::fakeWithSubAgent();

    $response = ChatAgent::make()->forUser((new User)->forceFill(['id' => 42]))->prompt('Ask for the time.');

    [$parent, $sub] = runAttributes();

    expect($response->conversationId)->not->toBeNull()
        ->and($parent['gen_ai.agent.name'])->toBe('ChatAgent')
        ->and($sub['gen_ai.agent.name'])->toBe('TimeAgent');

    foreach ([$parent, $sub] as $run) {
        expect($run)->toMatchArray([
            'session.id' => $response->conversationId,
            'laravel.ai.participant.type' => User::class,
            'laravel.ai.participant.id' => '42',
            'user.id' => '42',
        ]);
    }
});

it('X8: a mapped Context key named like a GenAI attribute loses to the real value', function () {
    $this->refreshApplicationWithConfig([
        ...$this->environmentConfig,
        'refract.context.attributes' => [
            'agent' => 'gen_ai.agent.name',
            'tenant' => 'app.tenant',
        ],
    ]);

    Http::fake();

    Context::add('agent', 'NotTheAgent');
    Context::add('tenant', 'acme');

    TimeAgent::fakeTwoSteps();

    TimeAgent::make()->prompt('What time is it?');

    [$run] = runAttributes();

    expect($run)->toMatchArray([
        'gen_ai.agent.name' => 'TimeAgent',
        'app.tenant' => 'acme',
    ]);
});

it('X8: a mapped Context key never fills a real attribute that is empty, so it cannot make a participant or user.id', function () {
    $this->refreshApplicationWithConfig([
        ...$this->environmentConfig,
        'refract.context.attributes' => [
            'owner_type' => 'laravel.ai.participant.type',
            'owner_id' => 'laravel.ai.participant.id',
            'thread' => 'session.id',
            'tenant' => 'app.tenant',
        ],
    ]);

    Http::fake();

    Context::add('owner_type', User::class);
    Context::add('owner_id', '42');
    Context::add('thread', 'thread-1');
    Context::add('tenant', 'acme');

    TimeAgent::fakeTwoSteps();

    TimeAgent::make()->prompt('What time is it?');

    [$run] = runAttributes();

    expect($run)->not->toHaveKey('laravel.ai.participant.type')
        ->and($run)->not->toHaveKey('laravel.ai.participant.id')
        ->and($run)->not->toHaveKey('user.id')
        ->and($run)->not->toHaveKey('session.id')
        ->and($run)->toHaveKey('app.tenant', 'acme');
});

it('X8: a Context key mapped to user.id never sets user.id; only a user participant does', function () {
    $this->refreshApplicationWithConfig([
        ...$this->environmentConfig,
        'refract.context.attributes' => ['uid' => 'user.id', 'tenant' => 'app.tenant'],
    ]);

    Http::fake();

    Context::add('uid', '77');
    Context::add('tenant', 'acme');

    ChatAgent::fake(['Hello.', 'Hello again.']);

    TimeAgent::fakeTwoSteps();
    TimeAgent::make()->prompt('What time is it?');
    ChatAgent::make()->forParticipant((new Team)->forceFill(['id' => 7]))->prompt('Hello');

    [$unsaved, $team] = runAttributes();

    expect($unsaved)->not->toHaveKey('user.id')
        ->and($unsaved)->toHaveKey('app.tenant', 'acme')
        ->and($team)->not->toHaveKey('user.id')
        ->and($team)->toHaveKey('laravel.ai.participant.id', '7');
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

it('X1: the OTLP destination gets the participant attributes and no user.id', function () {
    $this->refreshApplicationWithConfig([
        ...$this->environmentConfig,
        'refract.destination' => 'otlp',
        'refract.destinations.otlp.endpoint' => 'https://otlp.test/v1/traces',
    ]);

    Http::fake();

    ChatAgent::fake(['Hello.']);

    ChatAgent::make()->forUser((new User)->forceFill(['id' => 42]))->prompt('Hello');

    [$run] = runAttributes();

    expect(Http::recorded()->first()[0]->url())->toBe('https://otlp.test/v1/traces')
        ->and($run)->toMatchArray([
            'laravel.ai.participant.type' => User::class,
            'laravel.ai.participant.id' => '42',
        ])->and($run)->not->toHaveKey('user.id');
});

it('X9: the OTLP destination gets the environment name as it is', function () {
    $this->refreshApplicationWithConfig([
        ...$this->environmentConfig,
        'app.env' => 'Staging EU 1',
        'refract.destination' => 'otlp',
        'refract.destinations.otlp.endpoint' => 'https://otlp.test/v1/traces',
    ]);

    Http::fake();

    TimeAgent::fakeTwoSteps();

    TimeAgent::make()->prompt('What time is it?');

    app(Recorder::class)->flush();

    expect(Http::recorded()->first()[0]->url())->toBe('https://otlp.test/v1/traces')
        ->and(Otlp::resource())->toHaveKey('deployment.environment.name', 'Staging EU 1');
});

it('X12: a Context key mapped to a numeric attribute name is sent with the name as a JSON string', function () {
    $this->refreshApplicationWithConfig([
        ...$this->environmentConfig,
        'refract.context.attributes' => ['tenant' => '123'],
    ]);

    Http::fake();

    Context::add('tenant', 'acme');

    TimeAgent::fakeTwoSteps();

    TimeAgent::make()->prompt('What time is it?');

    [$run] = runAttributes();

    expect($run)->toHaveKey('123', 'acme')
        ->and(Http::recorded()->first()[0]->body())->toContain('{"key":"123","value":{"stringValue":"acme"}}')
        ->not->toContain('"key":123');
});

it('X13: a Context key mapped to a langfuse.* attribute is not sent to Langfuse; its own attributes still are', function () {
    $this->refreshApplicationWithConfig([
        ...$this->environmentConfig,
        'refract.context.attributes' => [
            'level' => 'langfuse.observation.level',
            'status' => 'langfuse.observation.status_message',
            'trace_name' => 'langfuse.trace.name',
            'tenant' => 'app.tenant',
        ],
    ]);

    Http::fake();

    Context::add('level', 'DEBUG');
    Context::add('status', 'fine');
    Context::add('trace_name', 'not-from-context');
    Context::add('tenant', 'acme');

    // A finished run, then a stream stopped early, whose spans are abandoned.
    TimeAgent::fakeTwoSteps();
    TimeAgent::make()->prompt('What time is it?');

    TimeAgent::fakeTwoSteps();

    foreach (TimeAgent::make()->stream('What time is it?') as $event) {
        break;
    }

    [$finished, $abandoned] = runAttributes();

    expect(array_filter(array_keys($finished), fn (string $name) => str_starts_with($name, 'langfuse.')))->toBe([])
        ->and($finished)->toHaveKey('app.tenant', 'acme')
        ->and($abandoned)->toMatchArray([
            'langfuse.observation.level' => 'WARNING',
            'langfuse.observation.status_message' => 'abandoned',
            'app.tenant' => 'acme',
        ])
        ->and($abandoned)->not->toHaveKey('langfuse.trace.name');
});
