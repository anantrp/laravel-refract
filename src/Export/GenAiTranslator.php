<?php

namespace Anantrp\Refract\Export;

/**
 * Turns neutral spans into spans with OTel GenAI attributes.
 *
 * A span closed at flush without its end event (status "abandoned") has
 * no OTel status and the laravel.ai.abandoned attribute.
 *
 * @see https://opentelemetry.io/docs/specs/semconv/gen-ai/gen-ai-agent-spans/
 *
 * @phpstan-type TranslatedSpan array{trace_id: string, span_id: string, parent_span_id: ?string, name: string, kind: int, start: int, end: int, attributes: array<string, mixed>, events: list<array{name: string, time: int, attributes: array<string, mixed>}>, status: array{code: int, message: ?string}}
 */
class GenAiTranslator
{
    public const KIND_INTERNAL = 1;

    public const KIND_CLIENT = 3;

    public const STATUS_UNSET = 0;

    public const STATUS_OK = 1;

    public const STATUS_ERROR = 2;

    /**
     * Translate the given neutral span.
     *
     * @param  array<string, mixed>  $span
     * @return TranslatedSpan
     */
    public function translate(array $span): array
    {
        $kind = $this->string($span, 'kind');
        $call = is_array($span['call'] ?? null) ? $span['call'] : [];
        $context = is_array($span['context'] ?? null) ? $span['context'] : [];

        [$name, $spanKind, $attributes] = match ($kind) {
            'invoke_agent' => $this->agent($call),
            'chat' => $this->chat($call),
            'execute_tool' => $this->tool($call),
            default => [$kind, self::KIND_INTERNAL, []],
        };

        $status = $span['status'] ?? null;
        $error = $status === 'error';
        $abandoned = $status === 'abandoned';
        $parent = $span['parent_span_id'] ?? null;
        $message = $span['status_message'] ?? null;

        return [
            'trace_id' => $this->string($span, 'trace_id'),
            'span_id' => $this->string($span, 'span_id'),
            'parent_span_id' => is_string($parent) ? $parent : null,
            'name' => $name,
            'kind' => $spanKind,
            'start' => $this->int($span, 'start'),
            'end' => $this->int($span, 'end'),
            'attributes' => array_filter(
                [
                    'gen_ai.operation.name' => $kind,
                    ...$attributes,
                    ...$this->context($context),
                    'laravel.ai.abandoned' => $abandoned ?: null,
                ],
                fn (mixed $value) => $value !== null,
            ),
            'events' => $this->events($span['events'] ?? null),
            'status' => [
                'code' => match (true) {
                    $error => self::STATUS_ERROR,
                    $abandoned => self::STATUS_UNSET,
                    default => self::STATUS_OK,
                },
                'message' => $error && is_string($message) ? $message : null,
            ],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $call
     * @return array{string, int, array<string, mixed>}
     */
    protected function agent(array $call): array
    {
        $agent = $this->string($call, 'agent');

        return [trim("invoke_agent {$agent}"), self::KIND_INTERNAL, [
            'gen_ai.agent.name' => $agent,
            'gen_ai.provider.name' => $call['provider'] ?? null,
            'gen_ai.request.model' => $call['model'] ?? null,
            'laravel.ai.invocation_id' => $call['invocation_id'] ?? null,
            'laravel.ai.agent.class' => $call['agent_class'] ?? null,
        ]];
    }

    /**
     * @param  array<array-key, mixed>  $call
     * @return array{string, int, array<string, mixed>}
     */
    protected function chat(array $call): array
    {
        $model = $this->string($call, 'model');
        $finishReason = $call['finish_reason'] ?? null;

        return [trim("chat {$model}"), self::KIND_CLIENT, [
            'gen_ai.provider.name' => $call['provider'] ?? null,
            'gen_ai.request.model' => $call['model'] ?? null,
            'gen_ai.response.model' => $call['response_model'] ?? null,
            'gen_ai.response.finish_reasons' => is_string($finishReason) ? [$finishReason] : null,
            'gen_ai.usage.input_tokens' => $call['input_tokens'] ?? null,
            'gen_ai.usage.output_tokens' => $call['output_tokens'] ?? null,
            'laravel.ai.step' => $call['step'] ?? null,
            'laravel.ai.final_step' => $call['final_step'] ?? null,
        ]];
    }

    /**
     * @param  array<array-key, mixed>  $call
     * @return array{string, int, array<string, mixed>}
     */
    protected function tool(array $call): array
    {
        $tool = $this->string($call, 'tool');

        return [trim("execute_tool {$tool}"), self::KIND_INTERNAL, [
            'gen_ai.tool.name' => $tool,
            'gen_ai.tool.type' => 'function',
            'laravel.ai.tool_invocation_id' => $call['tool_invocation_id'] ?? null,
        ]];
    }

    /**
     * Translate the neutral span's context bucket.
     *
     * @param  array<array-key, mixed>  $context
     * @return array<string, mixed>
     */
    protected function context(array $context): array
    {
        $session = $context['session'] ?? null;
        $participant = is_array($context['participant'] ?? null) ? $context['participant'] : [];

        return [
            'session.id' => $session,
            'gen_ai.conversation.id' => $session,
            'laravel.ai.participant.type' => $participant['type'] ?? null,
            'laravel.ai.participant.id' => $participant['id'] ?? null,
        ];
    }

    /**
     * Translate the neutral span's events.
     *
     * @return list<array{name: string, time: int, attributes: array<string, mixed>}>
     */
    protected function events(mixed $events): array
    {
        if (! is_array($events)) {
            return [];
        }

        $translated = [];

        foreach ($events as $event) {
            if (! is_array($event)) {
                continue;
            }

            $call = is_array($event['call'] ?? null) ? $event['call'] : [];

            [$name, $attributes] = match ($this->string($event, 'kind')) {
                'failover' => ['laravel.ai.failover', [
                    'gen_ai.provider.name' => $call['provider'] ?? null,
                    'gen_ai.request.model' => $call['model'] ?? null,
                    'error.type' => $call['error'] ?? null,
                ]],
                'approval_requested' => ['laravel.ai.tool_approval.requested', [
                    'gen_ai.tool.name' => $call['tool'] ?? null,
                    'gen_ai.tool.call.id' => $call['tool_call_id'] ?? null,
                ]],
                'approval_resolved' => ['laravel.ai.tool_approval.resolved', [
                    'gen_ai.tool.name' => $call['tool'] ?? null,
                    'gen_ai.tool.call.id' => $call['tool_call_id'] ?? null,
                    'laravel.ai.tool_approval.approved' => $call['approved'] ?? null,
                ]],
                default => [$this->string($event, 'kind'), []],
            };

            $translated[] = [
                'name' => $name,
                'time' => $this->int($event, 'time'),
                'attributes' => array_filter($attributes, fn (mixed $value) => $value !== null),
            ];
        }

        return $translated;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    protected function int(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
