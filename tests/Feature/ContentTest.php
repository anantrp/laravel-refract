<?php

use Anantrp\Refract\Capture\Recorder;
use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Tests\Support\Ai\NotesAgent;
use Anantrp\Refract\Tests\Support\Ai\NotesTool;
use Anantrp\Refract\Tests\Support\Ai\RecordingMask;
use Anantrp\Refract\Tests\Support\Ai\ThrowingMask;
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
use Laravel\Ai\Files\Base64Image;
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
        ->and(RecordingMask::$seen)->toContain('https://cdn.test/secret.png')
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

it('P6: image, audio, document and stored file attachments are references with media type and size, no bytes', function () {
    $this->refreshApplicationWithConfig(contentConfig(['refract.capture.content' => true]));

    Http::fake();

    $image = base64_encode(str_repeat('i', 300));
    $audio = base64_encode(str_repeat('w', 200));
    $document = base64_encode(str_repeat('d', 100));

    TimeAgent::fake(['Seen.']);
    TimeAgent::make()->prompt('Look.', attachments: [
        Image::fromBase64($image, 'image/png'),
        Audio::fromBase64($audio, 'audio/wav'),
        Document::fromBase64($document, 'application/pdf'),
        Document::fromStorage('reports/missing.pdf', 'local'),
        Image::fromPath('/nowhere/missing.jpg', 'image/jpeg'),
        Document::fromId('file_123'),
    ]);

    app(Recorder::class)->flush();

    [$run] = exportedAttributes('invoke_agent');

    expect(json_decode($run['gen_ai.input.messages'], true))->toBe([[
        'role' => 'user',
        'parts' => [
            ['type' => 'text', 'content' => 'Look.'],
            ['type' => 'media', 'modality' => 'image', 'mime_type' => 'image/png', 'size' => 300, 'source' => 'base64'],
            ['type' => 'media', 'modality' => 'audio', 'mime_type' => 'audio/wav', 'size' => 200, 'source' => 'base64'],
            ['type' => 'media', 'modality' => 'document', 'mime_type' => 'application/pdf', 'size' => 100, 'source' => 'base64'],
            ['type' => 'media', 'modality' => 'document', 'source' => 'storage'],
            ['type' => 'media', 'modality' => 'image', 'mime_type' => 'image/jpeg', 'source' => 'path'],
            ['type' => 'file', 'modality' => 'document', 'file_id' => 'file_123', 'source' => 'provider'],
        ],
    ]])->and(sentBodies())->not->toContain($image)
        ->and(sentBodies())->not->toContain($audio)
        ->and(sentBodies())->not->toContain($document)
        ->and(sentBodies())->not->toContain('missing.pdf')
        ->and(sentBodies())->not->toContain('missing.jpg')
        ->and(Http::recorded())->toHaveCount(1);
});

it('P7: an uploaded file attachment is a reference with source upload', function () {
    $this->environmentConfig = contentConfig(['refract.capture.content' => true]);
    $memory = $this->captureNeutralSpans();

    TimeAgent::fake(['Seen.']);
    TimeAgent::make()->prompt('Read this.', attachments: [
        UploadedFile::fake()->create('secret-report.pdf', 3, 'application/pdf'),
        UploadedFile::fake()->create('photo.png', 2, 'image/png'),
    ]);

    app(Recorder::class)->flush();

    [$run] = ofKind($memory->spans, 'invoke_agent');

    expect($run['content']['input'][0]['parts'])->toBe([
        ['type' => 'text', 'content' => 'Read this.'],
        ['type' => 'media', 'modality' => 'document', 'mime_type' => 'application/pdf', 'size' => 3072, 'source' => 'upload'],
        ['type' => 'media', 'modality' => 'image', 'mime_type' => 'image/png', 'size' => 2048, 'source' => 'upload'],
    ])->and(json_encode($memory->spans))->not->toContain('secret-report');
});

it('P8: a file URL loses its credentials, query and fragment', function (string $url, ?string $expected) {
    $this->environmentConfig = contentConfig(['refract.capture.content' => true]);
    $memory = $this->captureNeutralSpans();

    TimeAgent::fake(['Seen.']);
    TimeAgent::make()->prompt('Look.', attachments: [Image::fromUrl($url)]);

    app(Recorder::class)->flush();

    $part = ofKind($memory->spans, 'invoke_agent')[0]['content']['input'][0]['parts'][1];

    expect($part)->toBe(array_filter(['type' => 'media', 'modality' => 'image', 'uri' => $expected, 'source' => 'url']))
        ->and(json_encode($memory->spans))->not->toContain('hunter2')
        ->and(json_encode($memory->spans))->not->toContain('tok3n')
        ->and(json_encode($memory->spans))->not->toContain('frag');
})->with([
    'credentials, query, fragment' => ['https://bob:hunter2@cdn.test:8443/img/a.png?token=tok3n#frag', 'https://cdn.test:8443/img/a.png'],
    'query only' => ['https://cdn.test/a.png?sig=tok3n', 'https://cdn.test/a.png'],
    'plain' => ['https://cdn.test/a.png', 'https://cdn.test/a.png'],
    'not a URL' => ['hunter2 tok3n frag', null],
]);

it('P8: a URL is exported as an OTel uri part', function () {
    $this->refreshApplicationWithConfig(contentConfig(['refract.capture.content' => true]));

    Http::fake();

    TimeAgent::fake(['Seen.']);
    TimeAgent::make()->prompt('Look.', attachments: [Document::fromUrl('https://bob:pw@cdn.test/a.pdf?x=1')->withMimeType('application/pdf')]);

    app(Recorder::class)->flush();

    [$run] = exportedAttributes('invoke_agent');

    expect(json_decode($run['gen_ai.input.messages'], true)[0]['parts'][1])->toBe([
        'type' => 'uri', 'modality' => 'document', 'mime_type' => 'application/pdf', 'uri' => 'https://cdn.test/a.pdf', 'source' => 'url',
    ]);
});

it('P9: a data: URL keeps only its media type', function (object $file) {
    $this->environmentConfig = contentConfig(['refract.capture.content' => true]);
    $memory = $this->captureNeutralSpans();

    TimeAgent::fake(['Seen.']);
    TimeAgent::make()->prompt('Look.', attachments: [$file]);

    app(Recorder::class)->flush();

    $part = ofKind($memory->spans, 'invoke_agent')[0]['content']['input'][0]['parts'][1];

    expect($part)->toBe(['type' => 'media', 'modality' => 'image', 'mime_type' => 'image/gif', 'source' => 'data'])
        ->and(json_encode($memory->spans))->not->toContain('R0lGOD');
})->with([
    'remote' => fn () => Image::fromUrl('data:image/gif;base64,R0lGODlhAQABAAAAACw='),
    'base64' => fn () => new Base64Image('data:image/gif;base64,R0lGODlhAQABAAAAACw=', 'image/png'),
]);

it('P10: the base64 size does not count padding', function (string $bytes) {
    $this->environmentConfig = contentConfig(['refract.capture.content' => true]);
    $memory = $this->captureNeutralSpans();

    TimeAgent::fake(['Seen.']);
    TimeAgent::make()->prompt('Look.', attachments: [
        Image::fromBase64(base64_encode($bytes), 'image/png'),
        Image::fromBase64(chunk_split(base64_encode($bytes), 4, "\n"), 'image/png'),
    ]);

    app(Recorder::class)->flush();

    $parts = ofKind($memory->spans, 'invoke_agent')[0]['content']['input'][0]['parts'];

    expect($parts[1]['size'])->toBe(strlen($bytes))
        ->and($parts[2]['size'])->toBe(strlen($bytes));
})->with([
    'no padding' => ['abc'],
    'one =' => ['hello'],
    'two =' => ['hello!!'],
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
