<?php

namespace Anantrp\Refract\Export;

/**
 * A backend that receives OTLP JSON, with its own address, auth and quirks.
 *
 * @phpstan-import-type TranslatedSpan from GenAiTranslator
 */
interface Platform
{
    /**
     * Create the platform from the destination config under the given key, or get null and one warning when it cannot send.
     */
    public static function fromConfig(string $key): ?self;

    /**
     * Get the full URL the OTLP traces are posted to.
     */
    public function endpoint(): string;

    /**
     * Get the headers sent with every request.
     *
     * @return array<string, string>
     */
    public function headers(): array;

    /**
     * Apply the platform's changes to the resource attributes.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function resource(array $attributes): array;

    /**
     * Apply the platform's changes to the translated spans.
     *
     * @param  list<TranslatedSpan>  $spans
     * @return list<TranslatedSpan>
     */
    public function prepare(array $spans): array;
}
