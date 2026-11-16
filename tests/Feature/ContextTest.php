<?php

use Anantrp\Refract\Capture\Recorder;
use Anantrp\Refract\Tests\Support\Otlp;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Workbench\App\Ai\Agents\ChatAgent;
use Workbench\App\Ai\Agents\TimeAgent;
use Workbench\App\Ai\Agents\TraitChatAgent;
use Workbench\App\Models\Team;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->refreshApplicationWithConfig([
        'refract.destination' => 'langfuse',
        'refract.destinations.langfuse.public_key' => 'pk-test',
        'refract.destinations.langfuse.secret_key' => 'sk-test',
    ]);

    $this->loadMigrationsFrom(dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations');

    Http::fake();
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

it('a saved run with a User participant records the full class, the id and Langfuse user.id', function () {
    ChatAgent::fake(['Hello.']);

    ChatAgent::make()->forUser((new User)->forceFill(['id' => 42]))->prompt('Hello');

    [$run] = runAttributes();

    expect($run)->toMatchArray([
        'laravel.ai.participant.type' => User::class,
        'laravel.ai.participant.id' => '42',
        'user.id' => '42',
    ]);
});

it('a saved run of an agent that only uses the conversation trait records its participant', function () {
    TraitChatAgent::fake(['Hello.']);

    TraitChatAgent::make()->forUser((new User)->forceFill(['id' => 42]))->prompt('Hello');

    [$run] = runAttributes();

    expect($run)->toMatchArray([
        'laravel.ai.participant.type' => User::class,
        'laravel.ai.participant.id' => '42',
        'user.id' => '42',
    ]);
});

it('a saved run with a Team participant records the participant but no user.id', function () {
    ChatAgent::fake(['Hello.']);

    ChatAgent::make()->forParticipant((new Team)->forceFill(['id' => 7]))->prompt('Hello');

    [$run] = runAttributes();

    expect($run)->toMatchArray([
        'laravel.ai.participant.type' => Team::class,
        'laravel.ai.participant.id' => '7',
    ])->and($run)->not->toHaveKey('user.id');
});

it('a run not saved records no participant and no user.id', function () {
    TimeAgent::fakeTwoSteps();

    TimeAgent::make()->prompt('What time is it?');

    [$run] = runAttributes();

    expect($run)->not->toHaveKey('laravel.ai.participant.type')
        ->and($run)->not->toHaveKey('laravel.ai.participant.id')
        ->and($run)->not->toHaveKey('user.id')
        ->and($run)->not->toHaveKey('session.id');
});

it('a participant id 0 is recorded as "0"', function () {
    ChatAgent::fake(['Hello.']);

    ChatAgent::make()->forUser((new User)->forceFill(['id' => 0]))->prompt('Hello');

    [$run] = runAttributes();

    expect($run)->toMatchArray([
        'laravel.ai.participant.id' => '0',
        'user.id' => '0',
    ]);
});

it('a sub-agent run uses its parent run\'s participant and session', function () {
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

it('a mapped Context key named like a GenAI attribute loses to the real value', function () {
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

it('a mapped Context key never fills a real attribute that is empty, so it cannot make a participant', function () {
    $this->refreshApplicationWithConfig([
        ...$this->environmentConfig,
        'refract.context.attributes' => [
            'owner_type' => 'laravel.ai.participant.type',
            'owner_id' => 'laravel.ai.participant.id',
            'conversation' => 'gen_ai.conversation.id',
            'tenant' => 'app.tenant',
        ],
    ]);

    Http::fake();

    Context::add('owner_type', User::class);
    Context::add('owner_id', '42');
    Context::add('conversation', 'thread-1');
    Context::add('tenant', 'acme');

    TimeAgent::fakeTwoSteps();

    TimeAgent::make()->prompt('What time is it?');

    [$run] = runAttributes();

    expect($run)->not->toHaveKey('laravel.ai.participant.type')
        ->and($run)->not->toHaveKey('laravel.ai.participant.id')
        ->and($run)->not->toHaveKey('gen_ai.conversation.id')
        ->and($run)->toHaveKey('app.tenant', 'acme');
});

it('a run not saved takes user.id and session.id from Context keys mapped to them', function () {
    $this->refreshApplicationWithConfig([
        ...$this->environmentConfig,
        'refract.context.attributes' => ['uid' => 'user.id', 'thread' => 'session.id'],
    ]);

    Http::fake();

    Context::add('uid', '77');
    Context::add('thread', 'thread-1');

    TimeAgent::fakeTwoSteps();
    TimeAgent::make()->prompt('What time is it?');

    [$run] = runAttributes();

    expect($run)->toMatchArray(['user.id' => '77', 'session.id' => 'thread-1'])
        ->and($run)->not->toHaveKey('laravel.ai.participant.id');
});

it('a saved run keeps its own session.id and user participant over the mapped ones, and a Team participant keeps the mapped user.id', function () {
    $this->refreshApplicationWithConfig([
        ...$this->environmentConfig,
        'refract.context.attributes' => ['uid' => 'user.id', 'thread' => 'session.id'],
    ]);

    Http::fake();

    Context::add('uid', '77');
    Context::add('thread', 'thread-1');

    ChatAgent::fake(['Hello.', 'Hello again.']);

    $user = ChatAgent::make()->forUser((new User)->forceFill(['id' => 42]))->prompt('Hello');
    $team = ChatAgent::make()->forParticipant((new Team)->forceFill(['id' => 7]))->prompt('Hello');

    [$userRun, $teamRun] = runAttributes();

    expect($userRun)->toMatchArray(['user.id' => '42', 'session.id' => $user->conversationId])
        ->and($teamRun)->toMatchArray(['user.id' => '77', 'session.id' => $team->conversationId, 'laravel.ai.participant.id' => '7']);
});

it('APP_ENV "Staging EU 1" is sent to Langfuse as staging-eu-1', function () {
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

it('service.name is the app name', function () {
    $this->refreshApplicationWithConfig([
        ...$this->environmentConfig,
        'app.name' => 'Shop',
    ]);

    Http::fake();

    TimeAgent::fakeTwoSteps();

    TimeAgent::make()->prompt('What time is it?');

    app(Recorder::class)->flush();

    expect(Otlp::resource())->toHaveKey('service.name', 'Shop');
});

it('the OTLP destination gets the participant attributes and no user.id', function () {
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

it('the OTLP destination gets the environment name as it is', function () {
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

it('a Context key mapped to a numeric attribute name is sent with the name as a JSON string', function () {
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
        ->and(Otlp::body(Http::recorded()->first()[0]))->toContain('{"key":"123","value":{"stringValue":"acme"}}')
        ->not->toContain('"key":123');
});

it('a Context key mapped to a langfuse.* attribute is not sent to Langfuse; its own attributes still are', function () {
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
