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
