<?php

use Anantrp\Refract\Capture\Recorder;
use Anantrp\Refract\Tests\Support\Ai\BrokenToolAgent;
use Anantrp\Refract\Tests\Support\Otlp;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolApprovalResolved;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\TextResponse;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use Workbench\App\Ai\Agents\ChatAgent;
use Workbench\App\Ai\Agents\SupervisorAgent;
use Workbench\App\Ai\Agents\TimeAgent;
use Workbench\App\Ai\Agents\TraitChatAgent;

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

it('R3: provider failover gives one run span showing the provider that answered, with the failover as an event', function () {
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
        ->and($run['events'])->toHaveCount(1)
        ->and(Otlp::attributes($run))->toMatchArray([
            'gen_ai.provider.name' => 'anthropic',
            'gen_ai.request.model' => 'claude-b',
        ]);

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

it('R4: a stream read to the end gives the same tree as prompt()', function () {
    TimeAgent::fakeTwoSteps();

    foreach (TimeAgent::make()->stream('What time is it?') as $event) {
        //
    }

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
        ->and(array_column(array_column($spans, 'status'), 'code'))->toBe([1, 1, 1, 1]);
});

it('R5: a stream stopped early has its open spans closed as abandoned at flush', function () {
    TimeAgent::fakeTwoSteps();

    foreach (TimeAgent::make()->stream('What time is it?') as $event) {
        break;
    }

    app(Recorder::class)->flush();

    $spans = Otlp::spans();

    expect(array_column($spans, 'name'))->toBe([
        'invoke_agent TimeAgent',
        'chat fake',
    ]);

    foreach ($spans as $span) {
        expect($span['status'])->toBe(['code' => 0])
            ->and(Otlp::attributes($span))->toMatchArray([
                'laravel.ai.abandoned' => true,
                'langfuse.observation.level' => 'WARNING',
                'langfuse.observation.status_message' => 'abandoned',
            ]);
    }

    expect($spans[1]['parentSpanId'])->toBe($spans[0]['spanId']);
});

it('R6: a stream read again after it stopped is a separate run, not a failover', function () {
    TimeAgent::fake(['First answer.', 'Second answer.']);

    $stream = TimeAgent::make()->stream('What time is it?');

    foreach ($stream as $event) {
        break;
    }

    foreach ($stream as $event) {
        //
    }

    app(Recorder::class)->flush();

    $spans = Otlp::spans();

    expect(array_column($spans, 'name'))->toBe([
        'invoke_agent TimeAgent',
        'chat fake',
        'invoke_agent TimeAgent',
        'chat fake',
    ]);

    [$stopped, $stoppedChat, $again, $againChat] = $spans;

    expect(Otlp::attributes($stopped))->toHaveKey('laravel.ai.abandoned', true)
        ->and(Otlp::attributes($stoppedChat))->toHaveKey('laravel.ai.abandoned', true)
        ->and(Otlp::attributes($again))->not->toHaveKey('laravel.ai.abandoned')
        ->and($again['status']['code'])->toBe(1)
        ->and($againChat['status']['code'])->toBe(1)
        ->and($stoppedChat['parentSpanId'])->toBe($stopped['spanId'])
        ->and($againChat['parentSpanId'])->toBe($again['spanId'])
        ->and($stopped['endTimeUnixNano'] <= $again['startTimeUnixNano'])->toBeTrue()
        ->and(array_merge(...array_column($spans, 'events')))->toBe([]);
});

it('R7: approval events sit inside the run span and a resumed run records no prompt', function () {
    $this->environmentConfig['refract.capture.content'] = true;
    $memory = $this->captureNeutralSpans();

    TimeAgent::fake([
        (new TextResponse('', new TextUsage, new Meta('openai', 'fake')))
            ->withPendingApprovals(collect([new PendingApproval('call_1', 'CurrentTime', [])])),
    ]);

    TimeAgent::make()->withMessages([])->prompt('What time is it?');

    TimeAgent::fake(['It is 12:00.']);

    $resumed = TimeAgent::make()->withMessages([])->prompt(Decision::approveAll());

    // A faked gateway does not run approved tools, so the SDK sends no resolved event here.
    event(new ToolApprovalResolved($resumed->invocationId, new TimeAgent, collect([
        new ToolResult('call_1', 'CurrentTime', [], '12:00'),
        new ToolResult('call_2', 'CurrentTime', [], 'Denied.', denied: true),
    ])));

    app(Recorder::class)->flush();

    $runs = array_values(array_filter($memory->spans, fn (array $span) => $span['kind'] === 'invoke_agent'));

    expect($runs)->toHaveCount(2);

    [$asked, $resume] = $runs;

    expect(array_column($asked['events'], 'kind'))->toBe(['approval_requested'])
        ->and($asked['events'][0]['call'])->toBe(['tool' => 'CurrentTime', 'tool_call_id' => 'call_1'])
        ->and(array_column($resume['events'], 'kind'))->toBe(['approval_resolved', 'approval_resolved'])
        ->and(array_column($resume['events'], 'call'))->toBe([
            ['tool' => 'CurrentTime', 'tool_call_id' => 'call_1', 'approved' => true],
            ['tool' => 'CurrentTime', 'tool_call_id' => 'call_2', 'approved' => false],
        ]);

    foreach ($runs as $run) {
        foreach ($run['events'] as $event) {
            expect($event['time'])->toBeGreaterThanOrEqual($run['start'])
                ->and($event['time'])->toBeLessThanOrEqual($run['end']);
        }
    }

    // Content capture is on: the first run records its prompt, the resumed run sent none.
    expect($asked['call'])->not->toHaveKey('resumed')
        ->and($resume['call'])->toHaveKey('resumed', true)
        ->and($asked['content']['input'])->toBe([['role' => 'user', 'parts' => [['type' => 'text', 'content' => 'What time is it?']]]])
        ->and($resume['content'])->not->toHaveKey('input')
        ->and($resume['content']['output'])->toBe([['role' => 'assistant', 'parts' => [['type' => 'text', 'content' => 'It is 12:00.']]]]);
});

it('R7: approval events are exported with the tool name, call id and decision', function () {
    TimeAgent::fake([
        (new TextResponse('', new TextUsage, new Meta('openai', 'fake')))
            ->withPendingApprovals(collect([new PendingApproval('call_1', 'CurrentTime', [])])),
    ]);

    $response = TimeAgent::make()->withMessages([])->prompt('What time is it?');

    event(new ToolApprovalResolved($response->invocationId, new TimeAgent, collect([
        new ToolResult('call_1', 'CurrentTime', [], 'Denied.', denied: true),
    ])));

    app(Recorder::class)->flush();

    $run = Otlp::spans()[0];

    expect(array_column($run['events'], 'name'))->toBe([
        'laravel.ai.tool_approval.requested',
        'laravel.ai.tool_approval.resolved',
    ])->and(Otlp::attributes($run['events'][0]))->toBe([
        'gen_ai.tool.name' => 'CurrentTime',
        'gen_ai.tool.call.id' => 'call_1',
    ])->and(Otlp::attributes($run['events'][1]))->toBe([
        'gen_ai.tool.name' => 'CurrentTime',
        'gen_ai.tool.call.id' => 'call_1',
        'laravel.ai.tool_approval.approved' => false,
    ]);

    foreach ($run['events'] as $event) {
        expect($event['timeUnixNano'] >= $run['startTimeUnixNano'])->toBeTrue()
            ->and($event['timeUnixNano'] <= $run['endTimeUnixNano'])->toBeTrue();
    }
});

it('R8: a run that throws has status error with the exception class and the app gets the same exception', function () {
    $thrown = new RuntimeException('secret detail from the provider');

    TimeAgent::fake(fn () => throw $thrown);

    $caught = null;

    try {
        TimeAgent::make()->prompt('What time is it?');
    } catch (Throwable $e) {
        $caught = $e;
    }

    app(Recorder::class)->flush();

    expect($caught)->toBe($thrown);

    [$run, $step] = Otlp::spans();

    expect($run['name'])->toBe('invoke_agent TimeAgent')
        ->and($run['status'])->toBe(['code' => 2, 'message' => RuntimeException::class])
        ->and($step['status'])->toBe(['code' => 2, 'message' => RuntimeException::class])
        ->and(Otlp::body(Http::recorded()[0][0]))->not->toContain('secret detail');
});

it('R8: a run that throws closes its open children as abandoned before it ends', function () {
    // The app's own listener throws after Refract opened the tool span; no ToolFailed follows.
    Event::listen(InvokingTool::class, fn () => throw new RuntimeException('The app listener failed.'));

    TimeAgent::fakeTwoSteps();

    expect(fn () => TimeAgent::make()->prompt('What time is it?'))->toThrow(RuntimeException::class, 'The app listener failed.');

    app(Recorder::class)->flush();

    $spans = Otlp::spans();
    $run = $spans[0];
    $children = array_slice($spans, 1);
    $abandoned = array_values(array_filter($children, fn (array $span) => (Otlp::attributes($span)['laravel.ai.abandoned'] ?? false) === true));

    expect($run['name'])->toBe('invoke_agent TimeAgent')
        ->and($run['status']['code'])->toBe(2)
        ->and($abandoned)->not->toBeEmpty()
        ->and(array_column($abandoned, 'name'))->toContain('execute_tool CurrentTime');

    foreach ($children as $child) {
        expect($child['endTimeUnixNano'] <= $run['endTimeUnixNano'])->toBeTrue();
    }
});

it('R13: a failed run and its failed step have error.type set to the exception class', function () {
    TimeAgent::fake(fn () => throw new RuntimeException('secret detail from the provider'));

    rescue(fn () => TimeAgent::make()->prompt('What time is it?'), report: false);

    app(Recorder::class)->flush();

    [$run, $step] = Otlp::spans();

    expect($run['name'])->toBe('invoke_agent TimeAgent')
        ->and(Otlp::attributes($run))->toHaveKey('error.type', RuntimeException::class)
        ->and($step['name'])->toBe('chat fake')
        ->and(Otlp::attributes($step))->toHaveKey('error.type', RuntimeException::class);
});

it('R13: a failed tool has error.type set to the exception class', function () {
    BrokenToolAgent::fakeSteps();

    rescue(fn () => BrokenToolAgent::make()->prompt('Use the tool.'), report: false);

    app(Recorder::class)->flush();

    $spans = collect(Otlp::spans())->keyBy('name');
    $tool = $spans['execute_tool BrokenTool'];

    expect($tool['status'])->toBe(['code' => 2, 'message' => LogicException::class])
        ->and(Otlp::attributes($tool))->toHaveKey('error.type', LogicException::class)
        ->and(Otlp::attributes($spans['invoke_agent BrokenToolAgent']))->toHaveKey('error.type', LogicException::class)
        ->and(Otlp::body(Http::recorded()[0][0]))->not->toContain('secret detail');
});

it('R13: a span that did not fail has no error.type', function () {
    TimeAgent::fakeTwoSteps();
    TimeAgent::make()->prompt('What time is it?');

    TimeAgent::fakeTwoSteps();

    foreach (TimeAgent::make()->stream('What time is it?') as $event) {
        break;
    }

    app(Recorder::class)->flush();

    foreach (Otlp::spans() as $span) {
        expect(Otlp::attributes($span))->not->toHaveKey('error.type');
    }
});

it('R9: a run joins the app\'s active OTel trace as a child', function () {
    $traceId = str_repeat('ab', 16);
    $spanId = str_repeat('cd', 8);

    $scope = Span::wrap(SpanContext::create($traceId, $spanId, TraceFlags::SAMPLED))->activate();

    try {
        TimeAgent::fakeTwoSteps();

        TimeAgent::make()->prompt('What time is it?');
    } finally {
        $scope->detach();
    }

    app(Recorder::class)->flush();

    $spans = Otlp::spans();

    expect(array_unique(array_column($spans, 'traceId')))->toBe([$traceId])
        ->and($spans[0]['parentSpanId'])->toBe($spanId);
});

it('R10: two runs in one trace each keep their own session.id', function () {
    $this->loadMigrationsFrom(dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations');

    ChatAgent::fake(['First.', 'Second.', 'Third.']);

    $scope = Span::wrap(SpanContext::create(str_repeat('ab', 16), str_repeat('cd', 8), TraceFlags::SAMPLED))->activate();

    try {
        $first = ChatAgent::make()->forUser((object) ['id' => 1])->prompt('Hello');
        $second = ChatAgent::make()->forUser((object) ['id' => 2])->prompt('Hello');
        ChatAgent::make()->continue((string) $first->conversationId, as: (object) ['id' => 1])->prompt('Again');
    } finally {
        $scope->detach();
    }

    app(Recorder::class)->flush();

    $runs = array_values(array_filter(Otlp::spans(), fn (array $span) => str_starts_with($span['name'], 'invoke_agent')));
    $sessions = array_map(fn (array $run) => Otlp::attributes($run)['session.id'] ?? null, $runs);

    expect(array_unique(array_column($runs, 'traceId')))->toHaveCount(1)
        ->and($first->conversationId)->not->toBeNull()
        ->and($second->conversationId)->not->toBe($first->conversationId)
        ->and($sessions)->toBe([$first->conversationId, $second->conversationId, $first->conversationId])
        ->and(Otlp::attributes($runs[1]))->toHaveKey('gen_ai.conversation.id', $second->conversationId);
});

it('R10: a failed run of an agent that only uses the conversation trait keeps its session.id', function () {
    $this->loadMigrationsFrom(dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations');

    TraitChatAgent::fake(fn () => throw new RuntimeException('down'));

    expect(fn () => TraitChatAgent::make()->continue('conversation-1', as: (object) ['id' => 1])->prompt('Again'))
        ->toThrow(RuntimeException::class);

    app(Recorder::class)->flush();

    [$run] = Otlp::spans();

    expect($run['status']['code'])->toBe(2)
        ->and(Otlp::attributes($run))->toHaveKey('session.id', 'conversation-1');
});

it('R12: chat spans export the final_step flag as laravel.ai.final_step', function () {
    TimeAgent::fakeTwoSteps();

    TimeAgent::make()->prompt('What time is it?');

    app(Recorder::class)->flush();

    $chats = array_values(array_filter(Otlp::spans(), fn (array $span) => str_starts_with($span['name'], 'chat')));

    expect(array_map(fn (array $chat) => Otlp::attributes($chat)['laravel.ai.final_step'] ?? null, $chats))
        ->toBe([false, true]);
});
