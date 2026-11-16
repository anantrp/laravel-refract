<?php

namespace Anantrp\Refract\Export;

use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Contracts\ExportResult;
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
 * Whole run trees are packed into requests of at most PART_LIMIT bytes.
 * A network error, 408, 429 or 5xx is retryable: the export stops there
 * and the caller decides what to do with the trees. Every other status is
 * rejected and warned about here. Redirects are never followed, so the
 * headers never reach another host.
 *
 * @phpstan-import-type TranslatedSpan from GenAiTranslator
 */
class HttpExporter implements Exporter
{
    /**
     * The most bytes of OTLP JSON in one request, before gzip.
     */
    public const PART_LIMIT = 4_000_000;

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
     * Create a new exporter instance that waits the given seconds for the destination.
     */
    public function __construct(
        protected Platform $platform,
        protected GenAiTranslator $translator,
        protected OtlpJson $encoder,
        protected string $environment,
        protected string $serviceName,
        protected int $timeout,
        protected bool $compress = true,
    ) {}

    /**
     * Export the given trees, a request at a time, and stop at the first request the destination cannot take now.
     */
    public function export(array $trees): ExportResult
    {
        try {
            $requests = $this->requests($trees);
        } catch (Throwable $e) {
            Diagnostics::warn('export.error', 'Spans could not be exported ('.$e::class.'). The batch was dropped.');

            return ExportResult::Rejected;
        }

        $result = ExportResult::Ok;

        foreach ($requests as $spans) {
            $sent = $this->post($spans);

            if ($sent === ExportResult::Retryable) {
                return $sent;
            }

            if ($sent === ExportResult::Rejected) {
                $result = $sent;
            }
        }

        return $result;
    }

    /**
     * Pack the given trees into the translated spans of each request.
     *
     * Whole trees are added to a request until the next one would take it
     * over PART_LIMIT bytes of OTLP JSON. A tree too big for one request is
     * the exception: its spans are packed one by one, with one warning,
     * and a span too big for one request is sent alone. A tree is sized as
     * the platform prepares it, since the platform may add attributes.
     *
     * @param  list<list<array<string, mixed>>>  $trees
     * @return list<list<TranslatedSpan>>
     */
    protected function requests(array $trees): array
    {
        $budget = self::PART_LIMIT - strlen($this->encoder->encode([], $this->resource()));

        $requests = [];
        $request = [];
        $used = 0;

        foreach ($this->units($trees, $budget) as [$spans, $size]) {
            if ($request !== [] && $used + 1 + $size > $budget) {
                $requests[] = $request;
                $request = [];
            }

            if ($size > $budget) {
                $bytes = $size + self::PART_LIMIT - $budget;

                Diagnostics::warn('export.too_big', "A span makes a request of {$bytes} bytes of OTLP JSON, over the ".self::PART_LIMIT.' byte limit. It was sent alone; the destination may refuse it.');
            }

            $used = $request === [] ? $size : $used + 1 + $size;
            $request = [...$request, ...$spans];
        }

        if ($request !== []) {
            $requests[] = $request;
        }

        return $requests;
    }

    /**
     * Get the units the given trees are packed by, with the bytes each takes in an encoded body: each tree that fits a request, and each span of a tree that does not.
     *
     * @param  list<list<array<string, mixed>>>  $trees
     * @return list<array{list<TranslatedSpan>, int}>
     */
    protected function units(array $trees, int $budget): array
    {
        $units = [];

        foreach ($trees as $tree) {
            $spans = array_map($this->translator->translate(...), $tree);
            $sizes = array_map($this->encoder->size(...), $this->platform->prepare($spans));
            $size = array_sum($sizes) + count($sizes) - 1;

            if ($size <= $budget) {
                $units[] = [$spans, $size];

                continue;
            }

            Diagnostics::warn('export.split', 'A run tree is over the '.self::PART_LIMIT.' byte limit of one request. Its spans were sent in more than one request.');

            foreach ($spans as $index => $span) {
                $units[] = [[$span], $sizes[$index]];
            }
        }

        return $units;
    }

    /**
     * Prepare, encode and post the given translated spans in one request.
     *
     * @param  list<TranslatedSpan>  $spans
     */
    protected function post(array $spans): ExportResult
    {
        try {
            $body = $this->encoder->encode($this->platform->prepare($spans), $this->resource());

            $headers = $this->platform->headers();
            $compressed = $this->compress ? $this->gzip($body) : false;

            if ($compressed !== false) {
                $body = $compressed;
                $headers['Content-Encoding'] = 'gzip';
            }

            // A redirect is never followed: it would send the headers to another host or turn the POST into a GET.
            $response = Http::withHeaders($headers)
                ->withoutRedirecting()
                ->timeout($this->timeout)
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
     * Get the resource attributes sent with every request.
     *
     * @return array<string, mixed>
     */
    protected function resource(): array
    {
        return $this->platform->resource([
            'service.name' => $this->serviceName,
            'deployment.environment.name' => $this->environment,
        ]);
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

    /**
     * Get the seconds of a Retry-After header, capped, or null when it is
     * not a whole number of seconds (an HTTP date, a negative number or text).
     *
     * Not read yet: an export in this process is never retried. The queue
     * drain will carry it in the export result, to wait before its next try.
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
