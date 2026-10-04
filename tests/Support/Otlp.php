<?php

namespace Anantrp\Refract\Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Reads the OTLP JSON requests sent through Http::fake().
 */
class Otlp
{
    /**
     * Get every span sent so far, in start order.
     *
     * @return list<array<string, mixed>>
     */
    public static function spans(): array
    {
        $spans = [];

        foreach (Http::recorded() as [$request]) {
            /** @var Request $request */
            foreach ($request->data()['resourceSpans'] ?? [] as $resourceSpans) {
                foreach ($resourceSpans['scopeSpans'] as $scopeSpans) {
                    array_push($spans, ...$scopeSpans['spans']);
                }
            }
        }

        usort($spans, fn (array $a, array $b) => strcmp(
            str_pad($a['startTimeUnixNano'], 20, '0', STR_PAD_LEFT),
            str_pad($b['startTimeUnixNano'], 20, '0', STR_PAD_LEFT),
        ));

        return $spans;
    }

    /**
     * Get the resource attributes of the first request, keyed by name.
     *
     * @return array<string, mixed>
     */
    public static function resource(): array
    {
        $request = Http::recorded()[0][0];

        return self::values($request->data()['resourceSpans'][0]['resource']['attributes']);
    }

    /**
     * Get the attributes of the given span, keyed by name.
     *
     * @param  array<string, mixed>  $span
     * @return array<string, mixed>
     */
    public static function attributes(array $span): array
    {
        return self::values($span['attributes'] ?? []);
    }

    /**
     * @param  list<array{key: string, value: array<string, mixed>}>  $attributes
     * @return array<string, mixed>
     */
    protected static function values(array $attributes): array
    {
        $values = [];

        foreach ($attributes as $attribute) {
            $values[$attribute['key']] = self::value($attribute['value']);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    protected static function value(array $value): mixed
    {
        return match (array_key_first($value)) {
            'intValue' => (int) $value['intValue'],
            'arrayValue' => array_map(self::value(...), $value['arrayValue']['values'] ?? []),
            default => reset($value),
        };
    }
}
