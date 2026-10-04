<?php

namespace Anantrp\Refract\Capture;

use Anantrp\Refract\Support\Diagnostics;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Enumerable;
use JsonException;
use JsonSerializable;
use Laravel\Ai\Contracts\Files\HasContent;
use Laravel\Ai\Contracts\Files\HasProviderId;
use Laravel\Ai\Events\AddingFileToStore;
use Laravel\Ai\Events\CreatingStore;
use Laravel\Ai\Events\FileAddedToStore;
use Laravel\Ai\Events\FileDeleted;
use Laravel\Ai\Events\FileRemovedFromStore;
use Laravel\Ai\Events\RemovingFileFromStore;
use Laravel\Ai\Events\StoreCreated;
use Laravel\Ai\Files\File;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\FileResponse;
use Laravel\Ai\Responses\ImageResponse;
use SplFileInfo;
use Stringable;
use Throwable;
use UnitEnum;

/**
 * Builds the content bucket of a span: prompts, messages, outputs, tool arguments and results.
 *
 * Off by default. Every captured string goes through the mask, then is
 * cut at the byte cap and marked with its original size. Files are never
 * recorded: attachments are left out, and a file in a value becomes
 * "[file]" with no method of the file called. Only plain arrays and
 * scalars are returned.
 *
 * A tool result in a step's message history is a reference only (its
 * call id and tool name), never its text: the SDK has already turned it
 * into text there, so a file in it is its bytes. The result is recorded
 * on the tool span only.
 *
 * Message: {role: user|assistant|tool, parts: list<Part>, finish_reason?}
 * Part: {type: text, content} | {type: tool_call, id, name, arguments}
 *     | {type: tool_call_response, id, name}
 *
 * @phpstan-type Part array<string, string>
 * @phpstan-type ContentMessage array{role: string, parts: list<Part>, finish_reason?: string}
 */
class Content
{
    /**
     * The value a captured string becomes when the mask fails.
     */
    public const MASK_FAILED = '<fully masked due to failed mask function>';

    /**
     * The text a file becomes inside a captured value.
     */
    public const FILE = '[file]';

    /**
     * The value a captured value becomes when it cannot be encoded as JSON.
     */
    public const NOT_ENCODABLE = '[not encodable as JSON]';

    /**
     * The deepest a value is walked, the same as the json_encode() default.
     */
    protected const MAX_DEPTH = 512;

    /**
     * The default byte cap of one captured value: 128 KB.
     */
    public const MAX_BYTES = 131_072;

    /**
     * The JSON flags tool arguments and results are encoded with, the same the SDK uses.
     */
    protected const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * The mask, once made: a callable, or false when it could not be made.
     *
     * @var callable|false|null
     */
    protected $mask = null;

    /**
     * Create a new content capture instance.
     *
     * @param  mixed  $maskClass  The class name of the invokable mask, or null for none.
     */
    public function __construct(
        protected Container $container,
        protected bool $enabled = false,
        protected int $maxBytes = self::MAX_BYTES,
        protected mixed $maskClass = null,
    ) {}

    /**
     * Get the input of a run: its prompt as one user message. Attachments are never recorded.
     *
     * @return array<string, mixed>
     */
    public function runInput(AgentPrompt $prompt): array
    {
        return $this->capture(fn () => ['input' => [
            ['role' => 'user', 'parts' => $this->textParts($prompt->prompt)],
        ]]);
    }

    /**
     * Get the output of a run: its final text.
     *
     * @return array<string, mixed>
     */
    public function runOutput(AgentResponse $response): array
    {
        return $this->capture(fn () => ['output' => [
            ['role' => 'assistant', 'parts' => $this->textParts($response->text)],
        ]]);
    }

    /**
     * Get the input of a step: the messages sent to the model.
     *
     * @param  array<array-key, mixed>  $messages
     * @return array<string, mixed>
     */
    public function stepInput(array $messages): array
    {
        return $this->capture(function () use ($messages) {
            $input = [];

            foreach ($messages as $message) {
                if ($message instanceof Message) {
                    $input[] = $this->message($message);
                }
            }

            return ['input' => $input];
        });
    }

    /**
     * Get the output of a step: the text and tool calls the model answered with.
     *
     * @return array<string, mixed>
     */
    public function stepOutput(StepResponse $response): array
    {
        return $this->capture(fn () => ['output' => [[
            'role' => 'assistant',
            'parts' => [...$this->textParts($response->text), ...$this->toolCalls($response->toolCalls)],
            'finish_reason' => $response->finishReason->value,
        ]]]);
    }

    /**
     * Get the arguments of a tool call, as JSON text.
     *
     * @param  array<array-key, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function toolArguments(array $arguments): array
    {
        return $this->capture(fn () => ['arguments' => $this->value($this->json($arguments))]);
    }

    /**
     * Get the result of a tool call, as the text the model gets.
     *
     * @return array<string, mixed>
     */
    public function toolResult(mixed $result): array
    {
        return $this->capture(fn () => ['result' => $this->value($this->text($result))]);
    }

    /**
     * Mask the given value, then cut it at the byte cap and mark it with its original size.
     */
    public function value(string $value): string
    {
        return $this->cut($this->mask($value));
    }

    /**
     * Run the given capture when content capture is on. A failure records nothing and warns once.
     *
     * @param  callable(): array<string, mixed>  $capture
     * @return array<string, mixed>
     */
    protected function capture(callable $capture): array
    {
        if (! $this->enabled) {
            return [];
        }

        try {
            return $capture();
        } catch (Throwable $e) {
            Diagnostics::warn('content.failed', 'Content capture failed with ['.$e::class.']. The content of that span was not recorded.');

            return [];
        }
    }

    /**
     * Get a message of the history. A tool result message that is not a
     * ToolResultMessage has no call id or tool name, so it has no parts.
     *
     * @return ContentMessage
     */
    protected function message(Message $message): array
    {
        return match (true) {
            $message instanceof UserMessage => ['role' => 'user', 'parts' => $this->textParts((string) $message->content)],
            $message instanceof AssistantMessage => [
                'role' => 'assistant',
                'parts' => [...$this->textParts((string) $message->content), ...$this->toolCalls($message->toolCalls->all())],
            ],
            $message instanceof ToolResultMessage => ['role' => 'tool', 'parts' => $this->toolResultParts($message->toolResults->all())],
            $message->role === MessageRole::ToolResult => ['role' => 'tool', 'parts' => []],
            default => [
                'role' => $message->role->value,
                'parts' => $this->textParts((string) $message->content),
            ],
        };
    }

    /**
     * @return list<Part>
     */
    protected function textParts(string $text): array
    {
        return $text === '' ? [] : [['type' => 'text', 'content' => $this->value($text)]];
    }

    /**
     * @param  array<array-key, mixed>  $calls
     * @return list<Part>
     */
    protected function toolCalls(array $calls): array
    {
        $parts = [];

        foreach ($calls as $call) {
            if ($call instanceof ToolCall) {
                $parts[] = [
                    'type' => 'tool_call',
                    'id' => $call->id,
                    'name' => $call->name,
                    'arguments' => $this->value($this->json($call->arguments)),
                ];
            }
        }

        return $parts;
    }

    /**
     * Get the tool results of the history, each as a reference: its call id and tool name, never its result.
     *
     * @param  array<array-key, mixed>  $results
     * @return list<Part>
     */
    protected function toolResultParts(array $results): array
    {
        $parts = [];

        foreach ($results as $result) {
            if ($result instanceof ToolResult) {
                $parts[] = [
                    'type' => 'tool_call_response',
                    'id' => $result->id,
                    'name' => $result->name,
                ];
            }
        }

        return $parts;
    }

    /**
     * Get a tool result as the text the SDK sends to the model, with every file in it as "[file]".
     */
    protected function text(mixed $value): string
    {
        return match (true) {
            $this->isFile($value) => self::FILE,
            is_string($value) => $value,
            is_array($value), $value instanceof Enumerable => $this->json($value),
            is_scalar($value) || $value instanceof Stringable => (string) $value,
            default => get_debug_type($value),
        };
    }

    /**
     * Encode the given value as JSON, with every file in it as "[file]". An empty value is an object.
     *
     * A value that cannot be encoded (NAN, INF, nested too deep) becomes a marker, with one warning.
     */
    protected function json(mixed $value): string
    {
        try {
            $plain = $this->plain($value);

            return json_encode($plain === [] ? (object) [] : $plain, self::JSON_FLAGS | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Diagnostics::warn('content.json', 'A captured value could not be encoded as JSON ('.$e->getMessage().'). It was recorded as '.self::NOT_ENCODABLE.'.');

            return self::NOT_ENCODABLE;
        }
    }

    /**
     * Get the given value as json_encode() would see it, with every file in it as "[file]".
     *
     * A file is caught before any of its methods can run: __toString(),
     * jsonSerialize() and toArray() of an SDK file read, fetch or return its bytes.
     *
     * @throws JsonException When the value is nested deeper than json_encode() allows.
     */
    protected function plain(mixed $value, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new JsonException('Maximum stack depth exceeded');
        }

        if ($this->isFile($value)) {
            return self::FILE;
        }

        if (is_array($value)) {
            return array_map(fn (mixed $item) => $this->plain($item, $depth + 1), $value);
        }

        if (! is_object($value) || $value instanceof UnitEnum) {
            return $value;
        }

        return match (true) {
            $value instanceof Enumerable => $this->plain($value->all(), $depth),
            $value instanceof JsonSerializable => $this->plain($value->jsonSerialize(), $depth),
            $value instanceof Arrayable => $this->plain($value->toArray(), $depth),
            default => (object) $this->plain(get_object_vars($value), $depth),
        };
    }

    /**
     * Determine if the given value is a file: an SDK file, generated media or
     * file response, anything with a provider file id (stored files, added
     * documents), an SDK file or store event holding a provider file id, or
     * a file on disk or uploaded.
     */
    protected function isFile(mixed $value): bool
    {
        return $value instanceof File
            || $value instanceof HasContent
            || $value instanceof HasProviderId
            || $value instanceof SplFileInfo
            || $value instanceof GeneratedImage
            || $value instanceof ImageResponse
            || $value instanceof AudioResponse
            || $value instanceof FileResponse
            || $value instanceof AddingFileToStore
            || $value instanceof FileAddedToStore
            || $value instanceof FileDeleted
            || $value instanceof RemovingFileFromStore
            || $value instanceof FileRemovedFromStore
            || $value instanceof CreatingStore
            || $value instanceof StoreCreated;
    }

    /**
     * Run the mask on the value. A mask that fails, or cannot be made, masks the whole value.
     */
    protected function mask(string $value): string
    {
        if ($this->maskClass === null || $this->maskClass === '') {
            return $value;
        }

        $mask = $this->mask ??= $this->makeMask();

        if ($mask === false) {
            return self::MASK_FAILED;
        }

        try {
            $masked = $mask($value);
        } catch (Throwable $e) {
            Diagnostics::warn('content.mask', 'The content mask ['.$this->maskName().'] threw ['.$e::class.']. The value was fully masked.');

            return self::MASK_FAILED;
        }

        if (! is_string($masked)) {
            Diagnostics::warn('content.mask', 'The content mask ['.$this->maskName().'] did not return a string. The value was fully masked.');

            return self::MASK_FAILED;
        }

        return $masked;
    }

    /**
     * Make the mask from the container, or get false and one warning when it cannot be made.
     */
    protected function makeMask(): callable|false
    {
        try {
            $mask = is_string($this->maskClass) ? $this->container->make($this->maskClass) : null;
        } catch (Throwable) {
            $mask = null;
        }

        if (! is_callable($mask)) {
            Diagnostics::warn('content.mask', 'The content mask ['.$this->maskName().'] is not an invokable class. Every value is fully masked.');

            return false;
        }

        return $mask;
    }

    protected function maskName(): string
    {
        return is_string($this->maskClass) ? $this->maskClass : get_debug_type($this->maskClass);
    }

    /**
     * Cut a value over the byte cap, at a character border, and mark it with its original size.
     */
    protected function cut(string $value): string
    {
        $size = strlen($value);

        if ($size <= $this->maxBytes) {
            return $value;
        }

        $marker = "…[cut, original size {$size} bytes]";

        return mb_strcut($value, 0, max(0, $this->maxBytes - strlen($marker)), 'UTF-8').$marker;
    }
}
