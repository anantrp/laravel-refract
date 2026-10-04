<?php

namespace Anantrp\Refract\Export;

use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Contracts\ExportResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Translates neutral spans, applies the platform's changes and posts OTLP JSON.
 */
class HttpExporter implements Exporter
{
    /**
     * The seconds to wait for the destination.
     */
    protected const TIMEOUT = 5;

    /**
     * Create a new exporter instance.
     */
    public function __construct(
        protected Platform $platform,
        protected GenAiTranslator $translator,
        protected OtlpJson $encoder,
        protected string $environment,
        protected string $serviceName,
    ) {}

    public function export(array $spans): ExportResult
    {
        $translated = $this->platform->prepare(array_map($this->translator->translate(...), $spans));

        $body = $this->encoder->encode($translated, $this->platform->resource([
            'service.name' => $this->serviceName,
            'deployment.environment.name' => $this->environment,
        ]));

        try {
            $response = Http::withHeaders($this->platform->headers())
                ->timeout(self::TIMEOUT)
                ->withBody($body, 'application/json')
                ->post($this->platform->endpoint());
        } catch (ConnectionException) {
            return ExportResult::Retryable;
        }

        if ($response->successful()) {
            return ExportResult::Ok;
        }

        $status = $response->status();

        return $status === 408 || $status === 429 || $status >= 500
            ? ExportResult::Retryable
            : ExportResult::Rejected;
    }
}
