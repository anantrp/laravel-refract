<?php

use Anantrp\Refract\Capture\Recorder;
use Anantrp\Refract\Tests\Support\Ai\BrokenToolAgent;
use Anantrp\Refract\Tests\Support\ClockRecorder;
use Anantrp\Refract\Tests\Support\Otlp;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Events\ToolApprovalResolved;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\TextResponse;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use Workbench\App\Ai\Agents\AlarmAgent;
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

it('prompt() with 2 steps and 1 tool gives invoke_agent > chat, execute_tool, chat in start order', function () {
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

it('a sub-agent run called from a tool nests under that tool span', function () {
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

it('provider failover gives one run span showing the provider that answered, with the failover as an event', function () {
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

it('a stream read to the end gives the same tree as prompt()', function () {
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

it('a stream stopped early has its open spans closed as abandoned at flush', function () {
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

it('a flush closes open children before their parent, so no abandoned child ends after its parent', function () {
    // A monotonic clock that moves on every read, so each end gets its own time.
    $this->extendBeforeBoot(Recorder::class, fn (Recorder $recorder, $app) => new class($app) extends ClockRecorder
    {
        protected function monotonic(): int
        {
            return $this->monotonicNow += 1_000;
        }
    });
    $transport = $this->captureNeutralSpans();
    $recorder = app(Recorder::class);

    $recorder->start('run', 'invoke_agent', null, []);
    $recorder->start('step', 'chat', 'run', []);
    $recorder->start('tool', 'execute_tool', 'run', []);
    $recorder->start('sub', 'invoke_agent', 'tool', []);
    $recorder->start('sub:step', 'chat', 'sub', []);

    $recorder->flush();

    $spans = array_column($transport->spans, null, 'span_id');

    expect($spans)->toHaveCount(5)
        ->and(array_unique(array_column($spans, 'status')))->toBe(['abandoned']);

    foreach ($spans as $span) {
        if ($span['parent_span_id'] !== null) {
            expect($span['end'])->toBeLessThanOrEqual($spans[$span['parent_span_id']]['end']);
        }
    }
});

it('a stream read again after it stopped is a separate run, not a failover', function () {
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

it('approval events sit inside the run span and a resumed run records no prompt', function () {
    $this->environmentConfig['refract.capture.content'] = true;
    $memory = $this->captureNeutralSpans();

    TimeAgent::fake([
        (new TextResponse('', new TextUsage, new Meta('openai', 'fake')))
            ->withPendingApprovals(collect([new PendingApproval('call_1', 'CurrentTime', [])])),
    ]);

    TimeAgent::make()->withMessages([])->prompt('What time is it?');

    TimeAgent::fake(['It is 12:00.']);

    TimeAgent::make()->withMessages([
        new AssistantMessage('', collect([
            new ToolCall('call_1', 'CurrentTime', []),
            new ToolCall('call_2', 'CurrentTime', []),
        ])),
    ])->prompt(Decisions::from([
        'call_1' => Decision::edit(['zone' => 'UTC']),
        'call_2' => Decision::reject('Not now.'),
        'call_3' => Decision::approve(),
        '*' => Decision::approve(),
    ]));

    app(Recorder::class)->flush();

    $runs = array_values(array_filter($memory->spans, fn (array $span) => $span['kind'] === 'invoke_agent'));

    expect($runs)->toHaveCount(2);

    [$asked, $resume] = $runs;

    // A decision for a call not in the prompt's messages, or for every other call, has no tool name.
    expect(array_column($asked['events'], 'kind'))->toBe(['approval_requested'])
        ->and($asked['events'][0]['call'])->toBe(['tool' => 'CurrentTime', 'tool_call_id' => 'call_1'])
        ->and(array_column($resume['events'], 'kind'))->toBe(['approval_resolved', 'approval_resolved', 'approval_resolved', 'approval_resolved'])
        ->and(array_column($resume['events'], 'call'))->toBe([
            ['tool' => 'CurrentTime', 'tool_call_id' => 'call_1', 'action' => 'edit', 'approved' => true],
            ['tool' => 'CurrentTime', 'tool_call_id' => 'call_2', 'action' => 'reject', 'approved' => false],
            ['tool_call_id' => 'call_3', 'action' => 'approve', 'approved' => true],
            ['tool_call_id' => '*', 'action' => 'approve', 'approved' => true],
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

it('a streamed run that pauses for approval records the request on its run span', function () {
    $memory = $this->captureNeutralSpans();

    TimeAgent::fake([
        (new TextResponse('', new TextUsage, new Meta('openai', 'fake')))
            ->withPendingApprovals(collect([new PendingApproval('call_1', 'CurrentTime', [])])),
    ]);

    foreach (TimeAgent::make()->withMessages([])->stream('What time is it?') as $event) {
        //
    }

    app(Recorder::class)->flush();

    $run = array_values(array_filter($memory->spans, fn (array $span) => $span['kind'] === 'invoke_agent'))[0];

    expect($run['status'])->toBe('ok')
        ->and(array_column($run['events'], 'call'))->toBe([['tool' => 'CurrentTime', 'tool_call_id' => 'call_1']]);
});

it('the SDK approval events after a run ended add nothing to it', function () {
    $memory = $this->captureNeutralSpans();

    TimeAgent::fake(['It is 12:00.']);

    $response = TimeAgent::make()->prompt('What time is it?');

    event(new ToolApprovalRequested($response->invocationId, new TimeAgent, collect([new PendingApproval('call_1', 'CurrentTime', [])])));
    event(new ToolApprovalResolved($response->invocationId, new TimeAgent, collect([new ToolResult('call_1', 'CurrentTime', [], '12:00')])));

    app(Recorder::class)->flush();

    expect(array_merge(...array_column($memory->spans, 'events')))->toBe([]);
});

it('a run resumed with an approval runs the approved tool under its own run span, with the decision on it', function () {
    AlarmAgent::fakeWithApproval();

    $asked = AlarmAgent::make()->withMessages([])->prompt('Wake me at 7.');

    AlarmAgent::make()->withMessages($asked->messages->all())->prompt(Decisions::from(['call_1' => Decision::approve()]));

    app(Recorder::class)->flush();

    $spans = Otlp::spans();

    expect(array_column($spans, 'name'))->toBe([
        'invoke_agent AlarmAgent',
        'chat fake',
        'invoke_agent AlarmAgent',
        'execute_tool SetAlarm',
        'chat fake',
    ]);

    [$first, $firstChat, $resumed, $tool, $resumedChat] = $spans;

    expect($firstChat['parentSpanId'])->toBe($first['spanId'])
        ->and($tool['parentSpanId'])->toBe($resumed['spanId'])
        ->and($resumedChat['parentSpanId'])->toBe($resumed['spanId'])
        ->and(array_column($first['events'], 'name'))->toBe(['laravel.ai.tool_approval.requested'])
        ->and(array_column($resumed['events'], 'name'))->toBe(['laravel.ai.tool_approval.resolved'])
        ->and(Otlp::attributes($resumed['events'][0]))->toBe([
            'gen_ai.tool.name' => 'SetAlarm',
            'gen_ai.tool.call.id' => 'call_1',
            'laravel.ai.tool_approval.action' => 'approve',
            'laravel.ai.tool_approval.approved' => true,
        ]);
});

it('approval events are exported with the tool name, call id and decision', function () {
    TimeAgent::fake([
        (new TextResponse('', new TextUsage, new Meta('openai', 'fake')))
            ->withPendingApprovals(collect([new PendingApproval('call_1', 'CurrentTime', [])])),
        'It is 12:00.',
    ]);

    $response = TimeAgent::make()->withMessages([])->prompt('What time is it?');

    TimeAgent::make()->withMessages($response->messages->all())->prompt(Decisions::from(['call_1' => Decision::reject()]));

    app(Recorder::class)->flush();

    [$asked, $resume] = array_values(array_filter(Otlp::spans(), fn (array $span) => str_starts_with($span['name'], 'invoke_agent')));

    expect(array_column($asked['events'], 'name'))->toBe(['laravel.ai.tool_approval.requested'])
        ->and(Otlp::attributes($asked['events'][0]))->toBe([
            'gen_ai.tool.name' => 'CurrentTime',
            'gen_ai.tool.call.id' => 'call_1',
        ])
        ->and(array_column($resume['events'], 'name'))->toBe(['laravel.ai.tool_approval.resolved'])
        ->and(Otlp::attributes($resume['events'][0]))->toBe([
            'gen_ai.tool.name' => 'CurrentTime',
            'gen_ai.tool.call.id' => 'call_1',
            'laravel.ai.tool_approval.action' => 'reject',
            'laravel.ai.tool_approval.approved' => false,
        ]);

    foreach ([$asked, $resume] as $run) {
        foreach ($run['events'] as $event) {
            expect($event['timeUnixNano'] >= $run['startTimeUnixNano'])->toBeTrue()
                ->and($event['timeUnixNano'] <= $run['endTimeUnixNano'])->toBeTrue();
        }
    }
});

it('a run that throws has status error with the exception class and the app gets the same exception', function () {
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

it('a run that throws closes its open children as abandoned before it ends', function () {
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

it('a failed run closes its open children deepest first, a re-started key too, so no abandoned child ends after its parent', function () {
    // A monotonic clock that moves on every read, so each end gets its own time.
    $this->extendBeforeBoot(Recorder::class, fn (Recorder $recorder, $app) => new class($app) extends ClockRecorder
    {
        protected function monotonic(): int
        {
            return $this->monotonicNow += 1_000;
        }
    });
    $transport = $this->captureNeutralSpans();
    $recorder = app(Recorder::class);

    $recorder->start('other', 'invoke_agent', null, []);
    $recorder->start('run', 'invoke_agent', null, []);
    $recorder->start('tool', 'execute_tool', 'run', []);
    $recorder->start('sub', 'invoke_agent', 'tool', []);
    // The same key again: the first tool and its sub-agent close, the new tool goes after them.
    $recorder->start('tool', 'execute_tool', 'run', []);
    $recorder->start('sub', 'invoke_agent', 'tool', []);
    $recorder->start('sub:step', 'chat', 'sub', []);
    $recorder->start('sub:tool', 'execute_tool', 'sub', []);
    $recorder->start('step', 'chat', 'run', []);

    $recorder->abandonChildren('run');
    $recorder->end('run', 'error', RuntimeException::class);

    expect($recorder->isOpen('other'))->toBeTrue();

    $recorder->flush();

    $spans = array_column($transport->spans, null, 'span_id');
    $run = array_values(array_filter($spans, fn (array $span) => $span['status'] === 'error'))[0];

    expect($spans)->toHaveCount(9)
        ->and(array_count_values(array_column($spans, 'status')))->toBe(['abandoned' => 8, 'error' => 1]);

    foreach ($spans as $span) {
        if ($span['parent_span_id'] !== null) {
            expect($span['end'])->toBeLessThanOrEqual($spans[$span['parent_span_id']]['end']);
        }

        if ($span['span_id'] !== $run['span_id'] && $span['trace_id'] === $run['trace_id']) {
            expect($span['end'])->toBeLessThan($run['end']);
        }
    }
});

it('a failed run and its failed step have error.type set to the exception class', function () {
    TimeAgent::fake(fn () => throw new RuntimeException('secret detail from the provider'));

    rescue(fn () => TimeAgent::make()->prompt('What time is it?'), report: false);

    app(Recorder::class)->flush();

    [$run, $step] = Otlp::spans();

    expect($run['name'])->toBe('invoke_agent TimeAgent')
        ->and(Otlp::attributes($run))->toHaveKey('error.type', RuntimeException::class)
        ->and($step['name'])->toBe('chat fake')
        ->and(Otlp::attributes($step))->toHaveKey('error.type', RuntimeException::class);
});

it('a failed tool has error.type set to the exception class, and the run it recovered in has none', function () {
    BrokenToolAgent::fakeSteps();

    BrokenToolAgent::make()->prompt('Use the tool.');

    app(Recorder::class)->flush();

    $spans = collect(Otlp::spans())->keyBy('name');
    $tool = $spans['execute_tool BrokenTool'];
    $run = $spans['invoke_agent BrokenToolAgent'];

    expect($tool['status'])->toBe(['code' => 2, 'message' => LogicException::class])
        ->and(Otlp::attributes($tool))->toHaveKey('error.type', LogicException::class)
        ->and($run['status']['code'])->toBe(1)
        ->and(Otlp::attributes($run))->not->toHaveKey('error.type')
        ->and(Otlp::body(Http::recorded()[0][0]))->not->toContain('secret detail');
});

it('a tool that throws is a failed tool span, and the run goes on to its next step and ends ok', function () {
    BrokenToolAgent::fakeSteps();

    $response = BrokenToolAgent::make()->prompt('Use the tool.');

    app(Recorder::class)->flush();

    $spans = Otlp::spans();

    expect($response->text)->toBe('The tool failed.')
        ->and(array_column($spans, 'name'))->toBe([
            'invoke_agent BrokenToolAgent',
            'chat fake',
            'execute_tool BrokenTool',
            'chat fake',
        ]);

    [$run, $first, $tool, $second] = $spans;

    expect($tool['status']['code'])->toBe(2)
        ->and($tool['parentSpanId'])->toBe($run['spanId'])
        ->and($second['parentSpanId'])->toBe($run['spanId'])
        ->and($second['startTimeUnixNano'] >= $tool['endTimeUnixNano'])->toBeTrue()
        ->and($second['status']['code'])->toBe(1)
        ->and($run['status']['code'])->toBe(1);
});

it('a sub-agent that throws is a failed run under its tool span, and the run that called it ends ok', function () {
    SupervisorAgent::fake([
        new ToolCall('call_1', 'TimeAgent', ['task' => 'What time is it?']),
        'TimeAgent could not tell.',
    ]);

    TimeAgent::fake(fn () => throw new RuntimeException('down'));

    SupervisorAgent::make()->prompt('Ask for the time.');

    app(Recorder::class)->flush();

    $spans = collect(Otlp::spans())->keyBy('name');
    $run = $spans['invoke_agent SupervisorAgent'];
    $tool = $spans['execute_tool TimeAgent'];
    $subRun = $spans['invoke_agent TimeAgent'];

    expect($subRun['parentSpanId'])->toBe($tool['spanId'])
        ->and($subRun['status'])->toBe(['code' => 2, 'message' => RuntimeException::class])
        ->and($run['status']['code'])->toBe(1)
        ->and(Otlp::attributes($run))->not->toHaveKey('error.type');
});

it('a span that did not fail has no error.type', function () {
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

it('a run joins the app\'s active OTel trace as a child', function () {
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

it('two runs in one trace each keep their own session.id', function () {
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

it('the run that titles a new conversation nests under the run that started it, in the same trace', function () {
    $this->loadMigrationsFrom(dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations');

    config(['ai.conversations.generate_title' => true]);

    ChatAgent::fake(['Hello.']);

    $response = ChatAgent::make()->forUser((object) ['id' => 1])->prompt('Hello');

    app(Recorder::class)->flush();

    $runs = array_values(array_filter(Otlp::spans(), fn (array $span) => str_starts_with($span['name'], 'invoke_agent')));

    expect($runs)->toHaveCount(2);

    // The SDK titles with an anonymous agent, or with ConversationTitleAgent in later releases.
    [$run, $title] = $runs;

    expect($run['name'])->toBe('invoke_agent ChatAgent')
        ->and($title['parentSpanId'])->toBe($run['spanId'])
        ->and($title['traceId'])->toBe($run['traceId'])
        ->and($title['startTimeUnixNano'] >= $run['startTimeUnixNano'])->toBeTrue()
        ->and($title['endTimeUnixNano'] <= $run['endTimeUnixNano'])->toBeTrue()
        ->and(Otlp::attributes($title))->toHaveKey('session.id', $response->conversationId);
});

it('a failed run of an agent that only uses the conversation trait keeps its session.id', function () {
    $this->loadMigrationsFrom(dirname(__DIR__, 2).'/vendor/laravel/ai/database/migrations');

    TraitChatAgent::fake(fn () => throw new RuntimeException('down'));

    expect(fn () => TraitChatAgent::make()->continue('conversation-1', as: (object) ['id' => 1])->prompt('Again'))
        ->toThrow(RuntimeException::class);

    app(Recorder::class)->flush();

    [$run] = Otlp::spans();

    expect($run['status']['code'])->toBe(2)
        ->and(Otlp::attributes($run))->toHaveKey('session.id', 'conversation-1');
});

it('chat spans export the final_step flag as laravel.ai.final_step', function () {
    TimeAgent::fakeTwoSteps();

    TimeAgent::make()->prompt('What time is it?');

    app(Recorder::class)->flush();

    $chats = array_values(array_filter(Otlp::spans(), fn (array $span) => str_starts_with($span['name'], 'chat')));

    expect(array_map(fn (array $chat) => Otlp::attributes($chat)['laravel.ai.final_step'] ?? null, $chats))
        ->toBe([false, true]);
});
