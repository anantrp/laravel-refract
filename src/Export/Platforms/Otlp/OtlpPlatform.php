<?php

namespace Anantrp\Refract\Export\Platforms\Otlp;

use Anantrp\Refract\Export\Platform;
use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Support\Settings;

/**
 * Any OTLP/HTTP backend: an endpoint and headers. Spans and the resource are sent as they are.
 *
 * @see https://opentelemetry.io/docs/specs/otel/protocol/exporter/
 */
class OtlpPlatform implements Platform
{
    /**
     * The path appended to OTEL_EXPORTER_OTLP_ENDPOINT, per the OTel exporter spec.
     */
    public const TRACES_PATH = '/v1/traces';

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

    /**
     * Create the platform from the config, or get null and one warning when no endpoint is set.
     *
     * REFRACT_OTLP_ENDPOINT is the full traces URL and gets only
     * REFRACT_OTLP_HEADERS, so OTEL headers meant for another backend never
     * reach it. OTEL_EXPORTER_OTLP_ENDPOINT is a base URL: "/v1/traces" is
     * appended and both header sets are sent, Refract's winning.
     */
    public static function fromConfig(string $key): ?self
    {
        $headers = Settings::headers("{$key}.headers");
        $endpoint = Settings::url("{$key}.endpoint");

        if ($endpoint !== '') {
            return new self($endpoint, $headers);
        }

        $base = Settings::url("{$key}.otel.endpoint");

        if ($base === '') {
            Diagnostics::warn('otlp.endpoint', 'No OTLP endpoint is set. Set REFRACT_OTLP_ENDPOINT or OTEL_EXPORTER_OTLP_ENDPOINT. Nothing is exported.');

            return null;
        }

        return new self(
            rtrim($base, '/').self::TRACES_PATH,
            [...Settings::headers("{$key}.otel.headers"), ...$headers],
        );
    }

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
