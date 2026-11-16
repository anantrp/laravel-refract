<?php

namespace Anantrp\Refract\Export\Platforms\Otlp;

use Anantrp\Refract\Export\Platform;

/**
 * Any OTLP/HTTP backend: an endpoint and headers. Spans and the resource are sent as they are.
 *
 * @see https://opentelemetry.io/docs/specs/otel/protocol/exporter/
 */
class OtlpPlatform implements Platform
{
    /**
     * Create a new OTLP platform instance.
     *
     * @param  string  $endpoint  The full URL the traces are posted to.
     * @param  array<string, string>  $headers
     */
    public function __construct(
        protected string $endpoint,
        protected array $headers = [],
    ) {}

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function headers(): array
    {
        return $this->headers;
    }

    public function resource(array $attributes): array
    {
        return $attributes;
    }

    public function prepare(array $spans): array
    {
        return $spans;
    }
}
