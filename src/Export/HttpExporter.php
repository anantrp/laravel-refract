<?php

namespace Anantrp\Refract\Export;

use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Contracts\ExportResult;
use Anantrp\Refract\Contracts\RetryAfter;
use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Support\Guard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Translates neutral spans, applies the platform's changes and posts OTLP JSON.
 *
 * A 2xx is ok, with one warning when its OTLP partialSuccess refuses
 * spans (never retried). A network error, 408, 429 or 5xx is retryable: the
 * transport decides whether to try again, and when. A 429 or 503 may say
 * when with Retry-After, read in whole seconds only and capped at 300.
 * Every other status, a redirect too (it is never followed), is rejected
 * and warned about here, with the status and the start of the body. Any
 * other error, from the HTTP client or from translating and encoding the
 * spans, is rejected with one warning.
 *
 * With gzip on, the body is gzipped and sent with Content-Encoding: gzip.
 * If gzip fails, the body is sent plain, with no such header.
 */
class HttpExporter implements Exporter, RetryAfter
{
    /**
     * The seconds to wait for the destination.
     */
    protected const TIMEOUT = 5;

    /**
     * The characters of a rejected response's body, or of a partial success message, put in the warning.
     */
    protected const BODY_EXCERPT = 200;

    /**
     * The characters of a rejected response's body, or of a partial success message, cleaned of credentials before the excerpt is cut.
     */
    protected const BODY_SCAN = 4_096;

    /**
     * The most seconds a Retry-After may ask to wait.
     */
    protected const MAX_RETRY_AFTER = 300;

    /**
     * The seconds the destination asked to wait after the last export, or null.
     */
    protected ?int $retryAfter = null;

    /**
     * Create a new exporter instance.
     */
    public function __construct(
        protected Platform $platform,
        protected GenAiTranslator $translator,
        protected OtlpJson $encoder,
        protected string $environment,
        protected string $serviceName,
        protected bool $compress = true,
    ) {}

    public function export(array $spans): ExportResult
    {
        $this->retryAfter = null;

        try {
            $translated = $this->platform->prepare(array_map($this->translator->translate(...), $spans));

            $body = $this->encoder->encode($translated, $this->platform->resource([
                'service.name' => $this->serviceName,
                'deployment.environment.name' => $this->environment,
            ]));

            $headers = $this->platform->headers();
            $compressed = $this->compress ? $this->gzip($body) : false;

            if ($compressed !== false) {
                $body = $compressed;
                $headers['Content-Encoding'] = 'gzip';
            }

            // A redirect is never followed: it would send the headers to another host or turn the POST into a GET.
            $response = Http::withHeaders($headers)
                ->withoutRedirecting()
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
            // The batch was delivered, so a failure here must not read as a dropped batch.
            Guard::run('export.partial', 'to read a partial success answer', fn () => $this->warnOfRefusedSpans($response));

            return ExportResult::Ok;
        }

        $status = $response->status();

        if ($status === 408 || $status === 429 || $status >= 500) {
            $this->retryAfter = $status === 429 || $status === 503 ? $this->retryAfterSeconds($response->header('Retry-After')) : null;

            return ExportResult::Retryable;
        }

        $excerpt = $this->excerpt($response->body());

        Diagnostics::warn('export.rejected', "Spans were rejected by the destination with HTTP {$status}: {$excerpt}. The batch was dropped.");

        return ExportResult::Rejected;
    }

    /**
     * Gzip the given body, or get false when it cannot be gzipped.
     */
    protected function gzip(string $body): string|false
    {
        try {
            return gzencode($body);
        } catch (Throwable) {
            return false;
        }
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /**
     * Get the seconds of a Retry-After header, capped, or null when it is
     * not a whole number of seconds (an HTTP date, a negative number or text).
     */
    protected function retryAfterSeconds(string $header): ?int
    {
        $header = trim($header);

        if (! ctype_digit($header)) {
            return null;
        }

        // A number too long for an int is over the cap too, so compare digit counts first.
        $digits = ltrim($header, '0');

        return strlen($digits) > strlen((string) self::MAX_RETRY_AFTER) ? self::MAX_RETRY_AFTER : min((int) $digits, self::MAX_RETRY_AFTER);
    }

    /**
     * Warn once when a 2xx body's OTLP partialSuccess refuses spans or
     * carries a message. The answer does not say which spans, so only the
     * count and the message are logged. Any other body is ignored.
     */
    protected function warnOfRefusedSpans(Response $response): void
    {
        $body = json_decode($response->body(), true);
        $partial = is_array($body) ? $body['partialSuccess'] ?? null : null;

        if (! is_array($partial)) {
            return;
        }

        $rejected = $this->refusedCount($partial['rejectedSpans'] ?? 0);
        $message = is_string($partial['errorMessage'] ?? null) ? $this->excerpt($partial['errorMessage']) : '';

        if ($rejected === 0 && $message === '') {
            return;
        }

        $shown = $message === '' ? 'no message' : $message;

        Diagnostics::warn('export.partial', "The destination refused {$rejected} span(s) of a batch it accepted: {$shown}. They are not retried.");
    }

    /**
     * Get the refused span count of a partialSuccess, or 0 when it is not a positive number.
     *
     * An int64 is a JSON string in OTLP JSON, and a count too big for an int is decoded as a float.
     */
    protected function refusedCount(mixed $count): int
    {
        return match (true) {
            is_int($count) => max(0, $count),
            is_string($count) && ctype_digit($count) => (int) $count,
            is_float($count) && $count >= PHP_INT_MAX => PHP_INT_MAX,
            is_float($count) && $count >= 1 => (int) $count,
            default => 0,
        };
    }

    /**
     * Get the start of the given text, cleaned of credentials.
     */
    protected function excerpt(string $text): string
    {
        return mb_substr($this->withoutCredentials(mb_substr($text, 0, self::BODY_SCAN)), 0, self::BODY_EXCERPT);
    }

    /**
     * Mask the values of credential-like keys (names holding key, token,
     * secret, auth, password, credential or cookie) and Bearer and Basic
     * tokens that a destination may echo in its body. Other text, such as
     * a plain error line, is kept.
     */
    protected function withoutCredentials(string $body): string
    {
        $key = '[\w.-]*(?:key|token|secret|auth|password|passwd|credential|cookie)[\w.-]*';

        return preg_replace([
            // JSON: "api_key": "value"
            '/("'.$key.'"\s*:\s*)(?:"(?:[^"\\\\]|\\\\.)*"|[^,}\]\s]+)/i',
            // Header line: X-Api-Key: value
            '/^([ \t]*'.$key.'[ \t]*:)[^\r\n]*$/im',
            // Query or form: api_key=value
            '/(\b'.$key.'\s*=)[^&\s"\',;]*/i',
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i',
        ], [
            '$1"[removed]"',
            '$1 [removed]',
            '$1[removed]',
            '$1 [removed]',
        ], $body) ?? '';
    }
}
