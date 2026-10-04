<?php

namespace Anantrp\Refract\Export;

/**
 * Encodes translated spans as an OTLP/HTTP JSON request body.
 *
 * @phpstan-import-type TranslatedSpan from GenAiTranslator
 */
class OtlpJson
{
    /**
     * The name the instrumentation scope is reported under.
     */
    public const SCOPE = 'anantrp/laravel-refract';

    /**
     * The flags the request body is encoded with.
     */
    protected const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Encode the given spans and resource attributes.
     *
     * @param  list<TranslatedSpan>  $spans
     * @param  array<string, mixed>  $resource
     */
    public function encode(array $spans, array $resource): string
    {
        return (string) json_encode([
            'resourceSpans' => [[
                'resource' => ['attributes' => $this->attributes($resource)],
                'scopeSpans' => [[
                    'scope' => ['name' => self::SCOPE],
                    'spans' => array_map($this->span(...), $spans),
                ]],
            ]],
        ], self::JSON_FLAGS);
    }

    /**
     * @param  TranslatedSpan  $span
     * @return array<string, mixed>
     */
    protected function span(array $span): array
    {
        $encoded = ['traceId' => $span['trace_id'], 'spanId' => $span['span_id']];

        if ($span['parent_span_id'] !== null) {
            $encoded['parentSpanId'] = $span['parent_span_id'];
        }

        $status = ['code' => $span['status']['code']];

        if ($span['status']['message'] !== null) {
            $status['message'] = $span['status']['message'];
        }

        return $encoded + [
            'name' => $span['name'],
            'kind' => $span['kind'],
            'startTimeUnixNano' => (string) $span['start'],
            'endTimeUnixNano' => (string) $span['end'],
            'attributes' => $this->attributes($span['attributes']),
            'events' => array_map(fn (array $event) => [
                'timeUnixNano' => (string) $event['time'],
                'name' => $event['name'],
                'attributes' => $this->attributes($event['attributes']),
            ], $span['events']),
            'status' => $status,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<array{key: string, value: array<string, mixed>}>
     */
    protected function attributes(array $attributes): array
    {
        $encoded = [];

        foreach ($attributes as $key => $value) {
            $encoded[] = ['key' => $key, 'value' => $this->value($value)];
        }

        return $encoded;
    }

    /**
     * @return array<string, mixed>
     */
    protected function value(mixed $value): array
    {
        return match (true) {
            is_bool($value) => ['boolValue' => $value],
            is_int($value) => ['intValue' => (string) $value],
            is_float($value) => ['doubleValue' => $value],
            is_array($value) => ['arrayValue' => ['values' => array_map($this->value(...), array_values($value))]],
            is_scalar($value) => ['stringValue' => (string) $value],
            default => ['stringValue' => ''],
        };
    }
}
