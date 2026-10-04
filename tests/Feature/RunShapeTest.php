<?php

use Anantrp\Refract\Capture\Recorder;
use Anantrp\Refract\Tests\Support\Otlp;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Exceptions\RateLimitedException;
use Workbench\App\Ai\Agents\SupervisorAgent;
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

it('R1: prompt() with 2 steps and 1 tool gives invoke_agent > chat, execute_tool, chat in start order', function () {
    TimeAgent::fakeTwoSteps();

    TimeAgent::make()->prompt('What time is it?');

    app(Recorder::class)->flush();

    $spans = Otlp::spans();

    expect(array_column($spans, 'name'))->toBe([
        'invoke_agent TimeAgent',
        'chat fake',
        'execute_tool CurrentTime',
        'chat fake',
    ]);

    [$run, $first, $tool, $second] = $spans;

    expect($run)->not->toHaveKey('parentSpanId')
        ->and($first['parentSpanId'])->toBe($run['spanId'])
        ->and($tool['parentSpanId'])->toBe($run['spanId'])
        ->and($second['parentSpanId'])->toBe($run['spanId'])
        ->and(array_unique(array_column($spans, 'traceId')))->toHaveCount(1)
        ->and($first['endTimeUnixNano'] <= $tool['startTimeUnixNano'])->toBeTrue()
        ->and($tool['endTimeUnixNano'] <= $second['startTimeUnixNano'])->toBeTrue()
        ->and($second['endTimeUnixNano'] <= $run['endTimeUnixNano'])->toBeTrue();

    expect(Otlp::attributes($run))->toMatchArray([
        'gen_ai.operation.name' => 'invoke_agent',
        'gen_ai.agent.name' => 'TimeAgent',
        'gen_ai.provider.name' => 'openai',
        'gen_ai.request.model' => 'fake',
        'laravel.ai.agent.class' => TimeAgent::class,
    ])->and(Otlp::attributes($first))->toMatchArray([
        'gen_ai.operation.name' => 'chat',
        'gen_ai.request.model' => 'fake',
        'laravel.ai.step' => 0,
    ])->and(Otlp::attributes($tool))->toMatchArray([
        'gen_ai.operation.name' => 'execute_tool',
        'gen_ai.tool.name' => 'CurrentTime',
    ]);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://cloud.langfuse.com/api/public/otel/v1/traces'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('pk-test:sk-test')));
});

it('R2: a sub-agent run called from a tool nests under that tool span', function () {
    SupervisorAgent::fakeTwoSteps();

    SupervisorAgent::make()->prompt('Ask for the time.');

    app(Recorder::class)->flush();

    $spans = Otlp::spans();

    expect(array_column($spans, 'name'))->toBe([
        'invoke_agent SupervisorAgent',
        'chat fake',
        'execute_tool TimeAgent',
        'invoke_agent TimeAgent',
        'chat fake',
        'execute_tool CurrentTime',
        'chat fake',
        'chat fake',
    ]);

    [$run, $first, $tool, $subRun, $subFirst, $subTool, $subSecond, $second] = $spans;

    expect($tool['parentSpanId'])->toBe($run['spanId'])
        ->and($subRun['parentSpanId'])->toBe($tool['spanId'])
        ->and($subFirst['parentSpanId'])->toBe($subRun['spanId'])
        ->and($subTool['parentSpanId'])->toBe($subRun['spanId'])
        ->and($subSecond['parentSpanId'])->toBe($subRun['spanId'])
        ->and($second['parentSpanId'])->toBe($run['spanId'])
        ->and(array_unique(array_column($spans, 'traceId')))->toHaveCount(1)
        ->and($subRun['startTimeUnixNano'] >= $tool['startTimeUnixNano'])->toBeTrue()
        ->and($subRun['endTimeUnixNano'] <= $tool['endTimeUnixNano'])->toBeTrue();
});

it('R3: provider failover gives one run span with the failover as an event', function () {
    config(['ai.providers.anthropic' => ['driver' => 'anthropic', 'key' => 'test']]);

    TimeAgent::fake(fn (string $prompt, $attachments, TextProvider $provider) => $provider->name() === 'openai'
        ? throw RateLimitedException::forProvider('openai')
        : 'It is 12:00.');

    TimeAgent::make()->prompt('What time is it?', provider: ['openai' => 'gpt-a', 'anthropic' => 'claude-b']);

    app(Recorder::class)->flush();

    $spans = Otlp::spans();

    expect(array_column($spans, 'name'))->toBe([
        'invoke_agent TimeAgent',
        'chat gpt-a',
        'chat claude-b',
    ]);

    [$run, $failed, $answered] = $spans;

    expect($run['status']['code'])->toBe(1)
        ->and($failed['status'])->toBe(['code' => 2, 'message' => RateLimitedException::class])
        ->and($answered['status']['code'])->toBe(1)
        ->and($failed['parentSpanId'])->toBe($run['spanId'])
        ->and($answered['parentSpanId'])->toBe($run['spanId'])
        ->and($run['events'])->toHaveCount(1);

    $event = $run['events'][0];

    expect($event['name'])->toBe('laravel.ai.failover')
        ->and(Otlp::attributes($event))->toBe([
            'gen_ai.provider.name' => 'openai',
            'gen_ai.request.model' => 'gpt-a',
            'error.type' => RateLimitedException::class,
        ])
        ->and($event['timeUnixNano'] >= $failed['endTimeUnixNano'])->toBeTrue()
        ->and($event['timeUnixNano'] <= $answered['startTimeUnixNano'])->toBeTrue();
});
