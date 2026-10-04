<?php

namespace Anantrp\Refract\Export;

use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Contracts\ExportResult;
use Anantrp\Refract\Support\Diagnostics;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Translates neutral spans, applies the platform's changes and posts OTLP JSON.
 *
 * A 2xx is ok. A network error, 408, 429 or 5xx is retryable: the
 * transport decides whether to try again. Every other status is rejected
 * and warned about here, with the status and the start of the body. Any
 * other error from the HTTP client is rejected with one warning.
 */
class HttpExporter implements Exporter
{
    /**
     * The seconds to wait for the destination.
     */
    protected const TIMEOUT = 5;

    /**
     * The characters of a rejected response's body put in the warning.
     */
    protected const BODY_EXCERPT = 200;

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
        } catch (RequestException $e) {
            $response = $e->response;
        } catch (Throwable $e) {
            Diagnostics::warn('export.error', 'Spans could not be exported ('.$e::class.'). The batch was dropped.');

            return ExportResult::Rejected;
        }

        return $this->result($response);
    }

    /**
     * Get the export result of the given response, and warn when it is rejected.
     */
    protected function result(Response $response): ExportResult
    {
        if ($response->successful()) {
            return ExportResult::Ok;
        }

        $status = $response->status();

        if ($status === 408 || $status === 429 || $status >= 500) {
            return ExportResult::Retryable;
        }

        $excerpt = mb_substr($response->body(), 0, self::BODY_EXCERPT);

        Diagnostics::warn('export.rejected', "Spans were rejected by the destination with HTTP {$status}: {$excerpt}. The batch was dropped.");

        return ExportResult::Rejected;
    }
}
