<?php

namespace Anantrp\Refract\Capture;

use Anantrp\Refract\Support\Diagnostics;
use Illuminate\Contracts\Container\Container;
use Laravel\Ai\Files\Audio;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\File;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\ProviderDocument;
use Laravel\Ai\Files\ProviderImage;
use Laravel\Ai\Files\RemoteAudio;
use Laravel\Ai\Files\RemoteDocument;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Files\RemoteVideo;
use Laravel\Ai\Files\S3Document;
use Laravel\Ai\Files\StoredAudio;
use Laravel\Ai\Files\StoredDocument;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Files\StoredVideo;
use Laravel\Ai\Files\Video;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\MessageRole;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Stringable;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Throwable;

/**
 * Builds the content bucket of a span: prompts, messages, outputs, tool arguments and results.
 *
 * Off by default. Every captured string goes through the mask, then is
 * cut at the byte cap and marked with its original size. Attachments are
 * references (media type, size, source), never their bytes: no file is
 * read, fetched or decoded. Only plain arrays and scalars are returned.
 *
 * Message: {role: user|assistant|tool, parts: list<Part>, finish_reason?}
 * Part: {type: text, content} | {type: tool_call, id, name, arguments}
 *     | {type: tool_call_response, id, name, response}
 *     | {type: media, modality?, mime_type?, size?, uri?, file_id?, source}
 *
 * @phpstan-type Part array<string, int|string>
 * @phpstan-type ContentMessage array{role: string, parts: list<Part>, finish_reason?: string}
 */
class Content
{
    /**
     * The value a captured string becomes when the mask fails.
     */
    public const MASK_FAILED = '<fully masked due to failed mask function>';

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
     * Get the input of a run: its prompt and attachments as one user message.
     *
     * @return array<string, mixed>
     */
    public function runInput(AgentPrompt $prompt): array
    {
        return $this->capture(fn () => ['input' => [
            $this->userMessage($prompt->prompt, $prompt->attachments->all()),
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
     * @return ContentMessage
     */
    protected function message(Message $message): array
    {
        return match (true) {
            $message instanceof UserMessage => $this->userMessage((string) $message->content, $message->attachments->all()),
            $message instanceof AssistantMessage => [
                'role' => 'assistant',
                'parts' => [...$this->textParts((string) $message->content), ...$this->toolCalls($message->toolCalls->all())],
            ],
            $message instanceof ToolResultMessage => ['role' => 'tool', 'parts' => $this->toolResults($message->toolResults->all())],
            default => [
                'role' => $message->role === MessageRole::ToolResult ? 'tool' : $message->role->value,
                'parts' => $this->textParts((string) $message->content),
            ],
        };
    }

    /**
     * @param  array<array-key, mixed>  $attachments
     * @return ContentMessage
     */
    protected function userMessage(string $text, array $attachments): array
    {
        $parts = $this->textParts($text);

        foreach ($attachments as $attachment) {
            if (is_object($attachment)) {
                $parts[] = $this->media($attachment);
            }
        }

        return ['role' => 'user', 'parts' => $parts];
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
     * @param  array<array-key, mixed>  $results
     * @return list<Part>
     */
    protected function toolResults(array $results): array
    {
        $parts = [];

        foreach ($results as $result) {
            if ($result instanceof ToolResult) {
                $parts[] = [
                    'type' => 'tool_call_response',
                    'id' => $result->id,
                    'name' => $result->name,
                    'response' => $this->value($this->text($result->result)),
                ];
            }
        }

        return $parts;
    }

    /**
     * Get a reference to an attachment, from its public properties only.
     *
     * Never mimeType(), name() or content(): those fetch a URL or read the disk or storage.
     *
     * @return Part
     */
    protected function media(object $file): array
    {
        if ($file instanceof UploadedFile) {
            $mime = $file->getClientMimeType();

            return $this->reference($this->modalityOf($mime), $mime, $this->uploadSize($file), source: 'upload');
        }

        $modality = $this->modality($file);
        $mime = $file instanceof File ? $file->mime : null;

        if (property_exists($file, 'base64') && is_string($file->base64)) {
            return str_starts_with($file->base64, 'data:')
                ? $this->reference($modality, $this->dataMime($file->base64), source: 'data')
                : $this->reference($modality, $mime, $this->base64Size($file->base64), source: 'base64');
        }

        if ($file instanceof RemoteImage || $file instanceof RemoteDocument || $file instanceof RemoteAudio || $file instanceof RemoteVideo || $file instanceof S3Document) {
            return str_starts_with($file->url, 'data:')
                ? $this->reference($modality, $this->dataMime($file->url), source: 'data')
                : $this->reference($modality, $mime, uri: $this->strip($file->url), source: 'url');
        }

        if ($file instanceof ProviderImage || $file instanceof ProviderDocument) {
            return $this->reference($modality, $mime, fileId: $this->value($file->id), source: 'provider');
        }

        if ($file instanceof StoredImage || $file instanceof StoredDocument || $file instanceof StoredAudio || $file instanceof StoredVideo) {
            return $this->reference($modality, $mime, source: 'storage');
        }

        return $this->reference($modality, $mime, source: property_exists($file, 'path') ? 'path' : 'unknown');
    }

    /**
     * @return Part
     */
    protected function reference(?string $modality, ?string $mime, ?int $size = null, ?string $uri = null, ?string $fileId = null, string $source = 'unknown'): array
    {
        return array_filter([
            'type' => 'media',
            'modality' => $modality,
            'mime_type' => $mime === '' ? null : $mime,
            'size' => $size,
            'uri' => $uri,
            'file_id' => $fileId,
            'source' => $source,
        ], fn (mixed $value) => $value !== null);
    }

    protected function modality(object $file): ?string
    {
        return match (true) {
            $file instanceof Image => 'image',
            $file instanceof Audio => 'audio',
            $file instanceof Video => 'video',
            $file instanceof Document => 'document',
            default => null,
        };
    }

    protected function modalityOf(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'audio/') => 'audio',
            str_starts_with($mime, 'video/') => 'video',
            default => 'document',
        };
    }

    /**
     * Get the size of an upload from the file system, without reading it.
     */
    protected function uploadSize(UploadedFile $file): ?int
    {
        try {
            $size = $file->getSize();
        } catch (Throwable) {
            return null;
        }

        return is_int($size) ? $size : null;
    }

    /**
     * Get the decoded size of base64 text, without decoding it: padding and whitespace do not count.
     */
    protected function base64Size(string $base64): int
    {
        $length = strlen($base64) - preg_match_all('/[\s=]/', $base64);

        return intdiv($length * 3, 4);
    }

    /**
     * Get the media type of a data: URL, and nothing else from it.
     */
    protected function dataMime(string $url): ?string
    {
        return preg_match('#^data:([\w.+-]+/[\w.+-]+)#', $url, $match) === 1 ? strtolower($match[1]) : null;
    }

    /**
     * Strip the credentials, query and fragment from a URL. Not a URL with a host: no URI at all.
     */
    protected function strip(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $port = isset($parts['port']) ? ":{$parts['port']}" : '';

        return $this->value("{$parts['scheme']}://{$parts['host']}{$port}".($parts['path'] ?? ''));
    }

    /**
     * Get a tool result as the text the SDK sends to the model.
     */
    protected function text(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_array($value) => $this->json($value),
            is_scalar($value) || $value instanceof Stringable => (string) $value,
            default => get_debug_type($value),
        };
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    protected function json(array $value): string
    {
        return (string) json_encode($value === [] ? (object) [] : $value, self::JSON_FLAGS);
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
