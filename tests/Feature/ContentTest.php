<?php

use Anantrp\Refract\Capture\Recorder;
use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Tests\Support\Ai\FileAgent;
use Anantrp\Refract\Tests\Support\Ai\FileTool;
use Anantrp\Refract\Tests\Support\Ai\NotesAgent;
use Anantrp\Refract\Tests\Support\Ai\NotesTool;
use Anantrp\Refract\Tests\Support\Ai\RecordingMask;
use Anantrp\Refract\Tests\Support\Ai\ThrowingMask;
use Anantrp\Refract\Tests\Support\Ai\TrapImage;
use Anantrp\Refract\Tests\Support\Ai\TrapUpload;
use Anantrp\Refract\Tests\Support\Otlp;
use Anantrp\Refract\Tests\Support\WarningLog;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\TextResponse;
use Workbench\App\Ai\Agents\SupervisorAgent;
use Workbench\App\Ai\Agents\TimeAgent;

/**
 * The config of a working Langfuse destination, with content capture set as given.
 *
 * @param  array<string, mixed>  $capture
 * @return array<string, mixed>
 */
function contentConfig(array $capture = []): array
{
    return [
        'refract.transport' => 'sync',
        'refract.destination' => 'langfuse',
        'refract.destinations.langfuse.public_key' => 'pk-test',
        'refract.destinations.langfuse.secret_key' => 'sk-test',
        ...$capture,
    ];
}

/**
 * Flush and get the neutral spans of the given kind, in the order they ended.
 *
 * @param  array<int, array<string, mixed>>  $spans
 * @return list<array<string, mixed>>
 */
function ofKind(array $spans, string $kind): array
{
    return array_values(array_filter($spans, fn (array $span) => $span['kind'] === $kind));
}

/**
 * Get the bodies of every request sent so far, as one string.
 */
function sentBodies(): string
{
    return implode("\n", Http::recorded()->map(fn (array $pair) => $pair[0]->body())->all());
}

/**
 * Get the exported spans of the given name prefix, keyed in start order, with their attributes.
 *
 * @return list<array<string, mixed>>
 */
function exportedAttributes(string $prefix): array
{
    $spans = array_filter(Otlp::spans(), fn (array $span) => str_starts_with($span['name'], $prefix));

    return array_values(array_map(Otlp::attributes(...), $spans));
}

beforeEach(function () {
    RecordingMask::$seen = [];
    TrapImage::$calls = [];
    NotesTool::$note = 'Ship on Friday.';
});

it('P1: with capture off no span or event holds a prompt, output, tool argument, tool result or error message', function () {
    $this->refreshApplicationWithConfig(contentConfig());

    Http::fake();

    config(['ai.providers.anthropic' => ['driver' => 'anthropic', 'key' => 'test']]);

    NotesTool::$note = 'RESULT-SECRET';

    // A run with a tool, an attachment and history.
    NotesAgent::fakeTwoSteps('ARGUMENT-SECRET', 'OUTPUT-SECRET');
    NotesAgent::make()
        ->withMessages([new UserMessage('HISTORY-SECRET'), new AssistantMessage('HISTORY-SECRET')])
        ->prompt('PROMPT-SECRET', attachments: [Image::fromUrl('https://cdn.test/URL-SECRET.png')]);

    // A sub-agent called from a tool.
    SupervisorAgent::fake([new ToolCall('call_1', 'TimeAgent', ['task' => 'TASK-SECRET']), 'OUTPUT-SECRET']);
    TimeAgent::fake([new ToolCall('call_2', 'CurrentTime', []), 'OUTPUT-SECRET']);
    SupervisorAgent::make()->prompt('PROMPT-SECRET');

    // A failover.
    TimeAgent::fake(fn (string $prompt, $attachments, TextProvider $provider) => $provider->name() === 'openai'
        ? throw new RateLimitedException('FAILOVER-SECRET')
        : 'OUTPUT-SECRET');
    TimeAgent::make()->prompt('PROMPT-SECRET', provider: ['openai' => 'gpt-a', 'anthropic' => 'claude-b']);

    // An approval asked for, then a resumed run.
    TimeAgent::fake([
        (new TextResponse('OUTPUT-SECRET', new TextUsage, new Meta('openai', 'fake')))
            ->withPendingApprovals(collect([new PendingApproval('call_3', 'CurrentTime', ['zone' => 'APPROVAL-SECRET'], 'REASON-SECRET')])),
    ]);
    TimeAgent::make()->withMessages([])->prompt('PROMPT-SECRET');
    TimeAgent::fake(['OUTPUT-SECRET']);
    TimeAgent::make()->withMessages([])->prompt(Decision::approveAll());

    // A run that throws.
    TimeAgent::fake(fn () => throw new RuntimeException('ERROR-SECRET'));
    expect(fn () => TimeAgent::make()->prompt('PROMPT-SECRET'))->toThrow(RuntimeException::class);

    // A stream read to the end, and one stopped early.
    NotesAgent::fakeTwoSteps('ARGUMENT-SECRET', 'OUTPUT-SECRET');
    foreach (NotesAgent::make()->stream('PROMPT-SECRET') as $event) {
        //
    }

    NotesAgent::fakeTwoSteps('ARGUMENT-SECRET', 'OUTPUT-SECRET');
    foreach (NotesAgent::make()->stream('PROMPT-SECRET') as $event) {
        break;
    }

    app(Recorder::class)->flush();

    $body = sentBodies();

    expect(Http::recorded())->toHaveCount(1)
        ->and(count(Otlp::spans()))->toBeGreaterThan(20)
        ->and(array_merge(...array_column(Otlp::spans(), 'events')))->not->toBeEmpty()
        ->and($body)->not->toContain('SECRET')
        ->and($body)->not->toContain('gen_ai.input.messages')
        ->and($body)->not->toContain('gen_ai.output.messages')
        ->and($body)->not->toContain('gen_ai.tool.call.arguments')
        ->and($body)->not->toContain('gen_ai.tool.call.result');
});

it('P1: with capture off the neutral spans have an empty content bucket', function () {
    $memory = $this->captureNeutralSpans();

    NotesAgent::fakeTwoSteps();
    NotesAgent::make()->prompt('What is new?');

    app(Recorder::class)->flush();

    expect($memory->spans)->toHaveCount(4)
        ->and(array_column($memory->spans, 'content'))->toBe([[], [], [], []]);
});

it('P2: with capture on the prompt, step messages, output, tool arguments and tool result are recorded', function () {
    $this->environmentConfig = contentConfig(['refract.capture.content' => true]);
    $memory = $this->captureNeutralSpans();

    NotesAgent::fakeTwoSteps();
    NotesAgent::make()
        ->withMessages([new UserMessage('Hi.'), new AssistantMessage('Hello.')])
        ->prompt('What is new?');

    app(Recorder::class)->flush();

    [$run] = ofKind($memory->spans, 'invoke_agent');
    [$first, $second] = ofKind($memory->spans, 'chat');
    [$tool] = ofKind($memory->spans, 'execute_tool');

    $prompt = ['role' => 'user', 'parts' => [['type' => 'text', 'content' => 'What is new?']]];
    $history = [
        ['role' => 'user', 'parts' => [['type' => 'text', 'content' => 'Hi.']]],
        ['role' => 'assistant', 'parts' => [['type' => 'text', 'content' => 'Hello.']]],
        $prompt,
    ];
    $call = ['type' => 'tool_call', 'id' => 'call_1', 'name' => 'NotesTool', 'arguments' => '{"topic":"release"}'];
    $result = '{"topic":"release","note":"Ship on Friday."}';
    $answer = ['type' => 'text', 'content' => 'The release is on Friday.'];

    expect($run['content'])->toBe([
        'input' => [$prompt],
        'output' => [['role' => 'assistant', 'parts' => [$answer]]],
    ])->and($first['content'])->toBe([
        'input' => $history,
        'output' => [['role' => 'assistant', 'parts' => [$call], 'finish_reason' => 'tool_calls']],
    ])->and($tool['content'])->toBe([
        'arguments' => '{"topic":"release"}',
        'result' => $result,
    ])->and($second['content'])->toBe([
        'input' => [
            ...$history,
            ['role' => 'assistant', 'parts' => [$call]],
            ['role' => 'tool', 'parts' => [['type' => 'tool_call_response', 'id' => 'call_1', 'name' => 'NotesTool', 'response' => $result]]],
        ],
        'output' => [['role' => 'assistant', 'parts' => [$answer], 'finish_reason' => 'stop']],
    ]);
});

it('P2: content is exported as the OTel GenAI input, output and tool call attributes', function () {
    $this->refreshApplicationWithConfig(contentConfig(['refract.capture.content' => true]));

    Http::fake();

    NotesAgent::fakeTwoSteps();
    NotesAgent::make()->prompt('What is new?');

    app(Recorder::class)->flush();

    [$run] = exportedAttributes('invoke_agent');
    [$first, $second] = exportedAttributes('chat');
    [$tool] = exportedAttributes('execute_tool');

    $prompt = ['role' => 'user', 'parts' => [['type' => 'text', 'content' => 'What is new?']]];
    $answer = ['type' => 'text', 'content' => 'The release is on Friday.'];
    $call = ['type' => 'tool_call', 'id' => 'call_1', 'name' => 'NotesTool', 'arguments' => ['topic' => 'release']];
    $result = '{"topic":"release","note":"Ship on Friday."}';

    expect(json_decode($run['gen_ai.input.messages'], true))->toBe([$prompt])
        ->and(json_decode($run['gen_ai.output.messages'], true))->toBe([['role' => 'assistant', 'parts' => [$answer]]])
        ->and(json_decode($first['gen_ai.input.messages'], true))->toBe([$prompt])
        ->and(json_decode($first['gen_ai.output.messages'], true))->toBe([['role' => 'assistant', 'parts' => [$call], 'finish_reason' => 'tool_calls']])
        ->and(json_decode($second['gen_ai.input.messages'], true))->toBe([
            $prompt,
            ['role' => 'assistant', 'parts' => [$call]],
            ['role' => 'tool', 'parts' => [['type' => 'tool_call_response', 'id' => 'call_1', 'name' => 'NotesTool', 'response' => $result]]],
        ])
        ->and(json_decode($second['gen_ai.output.messages'], true))->toBe([['role' => 'assistant', 'parts' => [$answer], 'finish_reason' => 'stop']])
        ->and($tool['gen_ai.tool.call.arguments'])->toBe('{"topic":"release"}')
        ->and($tool['gen_ai.tool.call.result'])->toBe($result);
});

it('P2: empty tool call arguments are exported as a JSON object, not a list', function () {
    $this->refreshApplicationWithConfig(contentConfig(['refract.capture.content' => true]));

    Http::fake();

    TimeAgent::fakeTwoSteps();
    TimeAgent::make()->prompt('What time is it?');

    app(Recorder::class)->flush();

    [$first] = exportedAttributes('chat');
    [$tool] = exportedAttributes('execute_tool');

    expect($first['gen_ai.output.messages'])->toContain('"arguments":{}')
        ->and($tool['gen_ai.tool.call.arguments'])->toBe('{}');
});

it('P2: a stream records the same content as prompt()', function () {
    $this->environmentConfig = contentConfig(['refract.capture.content' => true]);
    $memory = $this->captureNeutralSpans();

    NotesAgent::fakeTwoSteps();

    foreach (NotesAgent::make()->stream('What is new?') as $event) {
        //
    }

    app(Recorder::class)->flush();

    [$run] = ofKind($memory->spans, 'invoke_agent');
    [$tool] = ofKind($memory->spans, 'execute_tool');

    expect($run['content'])->toBe([
        'input' => [['role' => 'user', 'parts' => [['type' => 'text', 'content' => 'What is new?']]]],
        'output' => [['role' => 'assistant', 'parts' => [['type' => 'text', 'content' => 'The release is on Friday.']]]],
    ])->and($tool['content'])->toBe([
        'arguments' => '{"topic":"release"}',
        'result' => '{"topic":"release","note":"Ship on Friday."}',
    ]);
});

it('P2: abandoned and failed spans keep their input and get no output', function () {
    $this->environmentConfig = contentConfig(['refract.capture.content' => true]);
    $memory = $this->captureNeutralSpans();

    NotesAgent::fakeTwoSteps();

    foreach (NotesAgent::make()->stream('Stopped early') as $event) {
        break;
    }

    TimeAgent::fake(fn () => throw new RuntimeException('down'));

    expect(fn () => TimeAgent::make()->prompt('Fails'))->toThrow(RuntimeException::class);

    app(Recorder::class)->flush();

    foreach ($memory->spans as $span) {
        expect($span['content'])->toHaveKey('input')
            ->and($span['content'])->not->toHaveKey('output')
            ->and($span['status'])->toBeIn(['abandoned', 'error']);
    }
});

it('P3: a value over the byte cap is cut and marked with its original size', function () {
    $this->environmentConfig = contentConfig(['refract.capture.content' => true, 'refract.capture.max_bytes' => 64]);
    $memory = $this->captureNeutralSpans();

    $prompt = str_repeat('a', 1000);

    TimeAgent::fake(['Short.']);
    TimeAgent::make()->prompt($prompt);

    app(Recorder::class)->flush();

    [$run] = ofKind($memory->spans, 'invoke_agent');

    $cut = $run['content']['input'][0]['parts'][0]['content'];

    expect(strlen($cut))->toBe(64)
        ->and($cut)->toEndWith('[cut, original size 1000 bytes]')
        ->and($cut)->toStartWith(str_repeat('a', 10))
        ->and($run['content']['output'][0]['parts'][0]['content'])->toBe('Short.');
});

it('P3: a cut never splits a multibyte character', function () {
    $this->environmentConfig = contentConfig(['refract.capture.content' => true, 'refract.capture.max_bytes' => 64]);
    $memory = $this->captureNeutralSpans();

    TimeAgent::fake(['Short.']);
    TimeAgent::make()->prompt(str_repeat('é', 500));

    app(Recorder::class)->flush();

    $cut = ofKind($memory->spans, 'invoke_agent')[0]['content']['input'][0]['parts'][0]['content'];

    expect(mb_check_encoding($cut, 'UTF-8'))->toBeTrue()
        ->and(strlen($cut))->toBeLessThanOrEqual(64)
        ->and($cut)->toEndWith('[cut, original size 1000 bytes]')
        ->and($cut)->toStartWith('éé');
});

it('P3: the default byte cap is 128 KB', function () {
    $this->environmentConfig = contentConfig(['refract.capture.content' => true]);
    $memory = $this->captureNeutralSpans();

    TimeAgent::fake([str_repeat('b', 131_072), str_repeat('c', 131_073)]);
    TimeAgent::make()->prompt(str_repeat('a', 131_072));
    TimeAgent::make()->prompt('Again');

    app(Recorder::class)->flush();

    [$first, $second] = ofKind($memory->spans, 'invoke_agent');

    expect($first['content']['input'][0]['parts'][0]['content'])->toBe(str_repeat('a', 131_072))
        ->and($first['content']['output'][0]['parts'][0]['content'])->toBe(str_repeat('b', 131_072))
        ->and(strlen($second['content']['output'][0]['parts'][0]['content']))->toBe(131_072)
        ->and($second['content']['output'][0]['parts'][0]['content'])->toEndWith('[cut, original size 131073 bytes]');
});

it('P4: the mask runs on every captured value before export, and before the cut', function () {
    $this->refreshApplicationWithConfig(contentConfig([
        'refract.capture.content' => true,
        'refract.capture.mask' => RecordingMask::class,
        'refract.capture.max_bytes' => 2000,
    ]));

    Http::fake();

    NotesTool::$note = 'the secret note';
    NotesAgent::fakeTwoSteps('secret topic', 'The secret answer.');
    NotesAgent::make()->prompt('Tell me the secret.', attachments: [Image::fromUrl('https://cdn.test/secret.png')]);

    TimeAgent::fake(['Short.']);
    TimeAgent::make()->prompt(str_repeat('a', 3000).'secret');

    app(Recorder::class)->flush();

    $body = sentBodies();

    expect($body)->not->toContain('secret')
        ->and($body)->toContain('[masked]')
        ->and(RecordingMask::$seen)->toContain('Tell me the secret.')
        ->and(RecordingMask::$seen)->toContain('The secret answer.')
        ->and(RecordingMask::$seen)->toContain('{"topic":"secret topic"}')
        ->and(RecordingMask::$seen)->toContain('{"topic":"secret topic","note":"the secret note"}')
        ->and(RecordingMask::$seen)->toContain(str_repeat('a', 3000).'secret');

    [$tool] = exportedAttributes('execute_tool');

    expect($tool['gen_ai.tool.call.arguments'])->toBe('{"topic":"[masked] topic"}')
        ->and($tool['gen_ai.tool.call.result'])->toBe('{"topic":"[masked] topic","note":"the [masked] note"}');
});

it('P5: a mask that throws fully masks the value, the run continues and one warning names no value', function () {
    $this->environmentConfig = contentConfig([
        'refract.capture.content' => true,
        'refract.capture.mask' => ThrowingMask::class,
    ]);
    $memory = $this->captureNeutralSpans();

    Diagnostics::reset();
    Log::swap($log = new WarningLog);

    NotesAgent::fakeTwoSteps('secret topic', 'The secret answer.');
    $response = NotesAgent::make()->prompt('Tell me the secret.');

    app(Recorder::class)->flush();

    $masked = '<fully masked due to failed mask function>';

    [$run] = ofKind($memory->spans, 'invoke_agent');
    [$tool] = ofKind($memory->spans, 'execute_tool');

    expect($response->text)->toBe('The secret answer.')
        ->and($run['content'])->toBe([
            'input' => [['role' => 'user', 'parts' => [['type' => 'text', 'content' => $masked]]]],
            'output' => [['role' => 'assistant', 'parts' => [['type' => 'text', 'content' => $masked]]]],
        ])
        ->and($tool['content'])->toBe(['arguments' => $masked, 'result' => $masked])
        ->and(json_encode($memory->spans))->not->toContain('secret')
        ->and($log->warnings)->toHaveCount(1)
        ->and($log->warnings[0])->toContain(ThrowingMask::class)
        ->and($log->warnings[0])->not->toContain('secret');
});

it('P5: a mask class that cannot be made fully masks every value with one warning', function (mixed $mask) {
    $this->environmentConfig = contentConfig([
        'refract.capture.content' => true,
        'refract.capture.mask' => $mask,
    ]);
    $memory = $this->captureNeutralSpans();

    Diagnostics::reset();
    Log::swap($log = new WarningLog);

    TimeAgent::fake(['The secret answer.']);
    $response = TimeAgent::make()->prompt('Tell me the secret.');

    app(Recorder::class)->flush();

    expect($response->text)->toBe('The secret answer.')
        ->and(json_encode($memory->spans))->not->toContain('secret')
        ->and(json_encode($memory->spans))->toContain('fully masked due to failed mask function')
        ->and($log->warnings)->toHaveCount(1);
})->with([
    'missing class' => ['App\\Masks\\Missing'],
    'not invokable' => [stdClass::class],
    'not a string' => [['App\\Masks\\Missing']],
]);

it('P6: with capture on no attachment of any kind leaves a trace, in the run input or the step history', function () {
    $this->refreshApplicationWithConfig(contentConfig(['refract.capture.content' => true]));

    Http::fake();

    $base64 = base64_encode('BYTES-SECRET');
    $attachments = fn () => [
        Image::fromBase64($base64, 'image/png')->as('NAME-SECRET.png'),
        Image::fromUrl('https://bob:hunter2@cdn.test/URL-SECRET.png?token=tok3n#frag'),
        Audio::fromPath('/nowhere/PATH-SECRET.wav', 'audio/wav'),
        Document::fromStorage('reports/STORED-SECRET.pdf', 'local'),
        Document::fromId('file_ID-SECRET'),
        UploadedFile::fake()->create('UPLOAD-SECRET.pdf', 3, 'application/pdf'),
        new TrapImage('VFJBUA==', 'image/trap'),
        new TrapUpload,
    ];

    TimeAgent::fake(['Seen.']);
    TimeAgent::make()
        ->withMessages([new UserMessage('Earlier.', $attachments())])
        ->prompt('Look.', attachments: $attachments());

    app(Recorder::class)->flush();

    [$run] = exportedAttributes('invoke_agent');
    [$step] = exportedAttributes('chat');

    $body = sentBodies();

    expect(json_decode($run['gen_ai.input.messages'], true))->toBe([
        ['role' => 'user', 'parts' => [['type' => 'text', 'content' => 'Look.']]],
    ])->and(json_decode($step['gen_ai.input.messages'], true))->toBe([
        ['role' => 'user', 'parts' => [['type' => 'text', 'content' => 'Earlier.']]],
        ['role' => 'user', 'parts' => [['type' => 'text', 'content' => 'Look.']]],
    ])->and(Http::recorded())->toHaveCount(1);

    foreach (['SECRET', $base64, 'hunter2', 'tok3n', 'frag', 'cdn.test', 'nowhere', 'TRAP', 'image/', 'audio/', 'application/', '3072', 'media', 'uri', 'file_id', 'size'] as $trace) {
        expect($body)->not->toContain($trace);
    }

    expect(TrapImage::$calls)->toBe([]);
});

it('P6: a tool result that is a file, or holds files, records [file] for each file and calls no file method', function (Closure $result, string $expected) {
    $this->environmentConfig = contentConfig(['refract.capture.content' => true]);
    $memory = $this->captureNeutralSpans();

    FileTool::$result = $result();
    TrapImage::$calls = [];

    FileAgent::fakeTwoSteps();
    FileAgent::make()->prompt('Get the file.');

    app(Recorder::class)->flush();

    [$tool] = ofKind($memory->spans, 'execute_tool');
    [, $second] = ofKind($memory->spans, 'chat');

    expect(TrapImage::$calls)->toBe([])
        ->and($tool['content']['result'])->toBe($expected)
        ->and(end($second['content']['input']))->toBe(['role' => 'tool', 'parts' => [
            ['type' => 'tool_call_response', 'id' => 'call_1', 'name' => 'FileTool', 'response' => $expected],
        ]])
        ->and(json_encode($memory->spans))->not->toContain('TRAP');
})->with([
    'a file' => [fn () => new TrapImage('VFJBUA==', 'image/trap'), '[file]'],
    'an upload' => [fn () => new TrapUpload, '[file]'],
    'files in a Collection, an array, a nested Collection and a JsonSerializable' => [
        fn () => collect([
            'image' => new TrapImage('VFJBUA==', 'image/trap'),
            'list' => [new TrapImage('VFJBUA==', 'image/trap'), 'text'],
            'nested' => collect(['upload' => new TrapUpload]),
            'json' => new class implements JsonSerializable
            {
                public function jsonSerialize(): mixed
                {
                    return ['image' => new TrapImage('VFJBUA==', 'image/trap'), 'n' => 1];
                }
            },
        ]),
        '{"image":"[file]","list":["[file]","text"],"nested":{"upload":"[file]"},"json":{"image":"[file]","n":1}}',
    ],
]);

it('P11: a tool result object (Collection) is recorded as text', function () {
    $this->refreshApplicationWithConfig(contentConfig(['refract.capture.content' => true]));

    Http::fake();

    NotesAgent::fakeTwoSteps();
    NotesAgent::make()->prompt('What is new?');

    app(Recorder::class)->flush();

    $request = Http::recorded()[0][0];
    assert($request instanceof Request);

    $tool = array_values(array_filter(Otlp::spans(), fn (array $span) => str_starts_with($span['name'], 'execute_tool')))[0];
    $result = array_values(array_filter($tool['attributes'], fn (array $attribute) => $attribute['key'] === 'gen_ai.tool.call.result'))[0];

    expect($result['value'])->toBe(['stringValue' => '{"topic":"release","note":"Ship on Friday."}']);
});
