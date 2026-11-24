<?php

namespace Anantrp\Refract\Export;

use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Contracts\ExportResult;
use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Support\Guard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
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
     * The container key of the HTTP client exports are sent with: a client of Refract's own, so the app's global middleware, fakes and request events never see an export.
     */
    public const CLIENT = 'refract.http';

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
     * The fewest seconds left of an export's time limit for a request to be started.
     */
    protected const MIN_REQUEST_SECONDS = 1;

    /**
     * Create a new exporter instance that waits the given seconds for the destination, and the given seconds at most to connect to it.
     */
    public function __construct(
        protected Platform $platform,
        protected GenAiTranslator $translator,
        protected OtlpJson $encoder,
        protected string $environment,
        protected string $serviceName,
        protected int $timeout,
        protected ?int $connectTimeout = null,
        protected Factory $http = new Factory,
    ) {}

    /**
     * Whether the last request could not reach the destination, or got no answer in time.
     */
    protected bool $unreached = false;

    /**
     * Export the given trees, a request at a time, and stop at the first request the destination cannot take now.
     *
     * Given a time limit, each request waits at most what is left of it,
     * and a request is not started with less than MIN_REQUEST_SECONDS
     * left: the export is unfinished, with the trees whose every request
     * was answered, and the unsent spans of a split tree that was cut
     * part way, so a later export sends only those.
     */
    public function export(array $trees, ?float $within = null): ExportResult
    {
        $deadline = $within === null ? null : $this->seconds() + $within;

        try {
            [$requests, $trees] = $this->requests($trees);
        } catch (Throwable $e) {
            Diagnostics::warn('export.error', 'Spans could not be exported ('.$e::class.'). The batch was dropped.');

            return ExportResult::rejected();
        }

        $done = 0;
        $answered = [];
        // The positions of the trees a request was refused for good with.
        $refused = [];
        // Whether the destination took any request.
        $took = false;

        foreach ($requests as [$spans, $treesDone, $parts]) {
            $timeout = $this->timeout;

            if ($deadline !== null) {
                $left = $deadline - $this->seconds();

                if ($left < self::MIN_REQUEST_SECONDS) {
                    return ExportResult::unfinished($done, $this->unsent($trees[$done] ?? [], $answered[$done] ?? []), $this->refusedBefore($refused, $done), $took);
                }

                $timeout = min($timeout, $left);
            }

            $posted = $this->seconds();
            $sent = $this->post($spans, $timeout);

            if ($sent->isRetryable() && $this->unreached && $this->timeout - $timeout >= self::MIN_REQUEST_SECONDS && $this->seconds() - $posted >= $timeout - self::MIN_REQUEST_SECONDS) {
                // Given well under its own timeout, what was left of the time limit, the request waited it out: the export is cut short, not failed.
                return ExportResult::unfinished($done, $this->unsent($trees[$done] ?? [], $answered[$done] ?? []), $this->refusedBefore($refused, $done), $took);
            }

            if ($sent->isRetryable()) {
                return ExportResult::retryable($sent->retryAfter, $done, $this->unsent($trees[$done] ?? [], $answered[$done] ?? []), $sent->busy, $this->refusedBefore($refused, $done), $took);
            }

            $took = $took || ! $sent->isRejected();

            foreach ($parts as [$position, $indexes]) {
                $answered[$position] = [...$answered[$position] ?? [], ...$indexes];

                if ($sent->isRejected()) {
                    $refused[$position] = true;
                }
            }

            $done = $treesDone;
        }

        if ($refused === []) {
            return ExportResult::ok();
        }

        return count($refused) === count($trees) ? ExportResult::rejected() : ExportResult::ok(count($refused));
    }

    /**
     * Get the number of the given refused trees among the first done ones.
     *
     * A tree cut part way is not counted: its unsent spans are exported
     * later, as a tree of their own, and counted then if refused again.
     *
     * @param  array<int, true>  $refused  The positions of the refused trees.
     */
    protected function refusedBefore(array $refused, int $done): int
    {
        return count(array_filter(array_keys($refused), fn (int $position) => $position < $done));
    }

    /**
     * Get the spans of a tree that no answered request carried, or none when no request carried any of them.
     *
     * @param  list<array<string, mixed>>  $tree
     * @param  list<int>  $answered  The indexes in the tree of the spans an answered request carried.
     * @return list<array<string, mixed>>
     */
    protected function unsent(array $tree, array $answered): array
    {
        return $answered === [] ? [] : array_values(array_diff_key($tree, array_flip($answered)));
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
     * Each request comes with the number of leading trees whose spans are
     * all in it or in the requests before it, and with the position of each
     * tree it carries spans of and the indexes of those spans in the tree.
     * The trees come back too, a split tree with the times the platform
     * prepared for it whole.
     *
     * @param  list<list<array<string, mixed>>>  $trees
     * @return array{list<array{list<TranslatedSpan>, int, list<array{int, list<int>}>}>, list<list<array<string, mixed>>>}
     */
    protected function requests(array $trees): array
    {
        $budget = self::PART_LIMIT - strlen($this->encoder->encode([], $this->resource()));

        [$units, $trees] = $this->units($trees, $budget);

        $requests = [];
        $request = [];
        $parts = [];
        $used = 0;
        $done = 0;

        foreach ($units as [$spans, $size, $treesDone, $position, $indexes]) {
            if ($request !== [] && $used + 1 + $size > $budget) {
                $requests[] = [$request, $done, $parts];
                $request = [];
                $parts = [];
            }

            if ($size > $budget) {
                $bytes = $size + self::PART_LIMIT - $budget;

                Diagnostics::warn('export.too_big', "A span makes a request of {$bytes} bytes of OTLP JSON, over the ".self::PART_LIMIT.' byte limit. It was sent alone; the destination may refuse it.');
            }

            $used = $request === [] ? $size : $used + 1 + $size;
            $request = [...$request, ...$spans];
            $parts[] = [$position, $indexes];
            $done = $treesDone;
        }

        if ($request !== []) {
            $requests[] = [$request, $done, $parts];
        }

        return [$requests, $trees];
    }

    /**
     * Get the units the given trees are packed by, with the bytes each takes in an encoded body: each tree that fits a request, and each span of a tree that does not.
     *
     * Each unit comes with the number of leading trees done once it is sent,
     * the position of its tree, and the indexes of its spans in that tree.
     *
     * A split tree takes the times the platform prepared for it whole (a
     * platform may move tied siblings apart), since each request is
     * prepared on its own and would move its part alone. The
     * neutral tree takes them too, so its unsent spans keep them in a
     * later export.
     *
     * @param  list<list<array<string, mixed>>>  $trees
     * @return array{list<array{list<TranslatedSpan>, int, int, int, list<int>}>, list<list<array<string, mixed>>>}
     */
    protected function units(array $trees, int $budget): array
    {
        $units = [];

        foreach ($trees as $position => $tree) {
            $spans = array_map($this->translator->translate(...), $tree);
            $prepared = $this->platform->prepare($spans);
            $sizes = array_map($this->encoder->size(...), $prepared);
            $size = array_sum($sizes) + count($sizes) - 1;

            if ($size <= $budget) {
                $units[] = [$spans, $size, $position + 1, $position, array_keys($spans)];

                continue;
            }

            Diagnostics::warn('export.split', 'A run tree is over the '.self::PART_LIMIT.' byte limit of one request. Its spans were sent in more than one request.');

            $trees[$position] = $tree = array_map($this->withPreparedTimes(...), $tree, $prepared);
            $spans = array_map($this->translator->translate(...), $tree);
            $last = array_key_last($spans);

            foreach ($spans as $index => $span) {
                $units[] = [[$span], $sizes[$index], $index === $last ? $position + 1 : $position, $position, [$index]];
            }
        }

        return [$units, $trees];
    }

    /**
     * Copy the start, end and event times the platform prepared onto the neutral span.
     *
     * @param  array<string, mixed>  $span
     * @param  TranslatedSpan  $prepared
     * @return array<string, mixed>
     */
    protected function withPreparedTimes(array $span, array $prepared): array
    {
        $span['start'] = $prepared['start'];
        $span['end'] = $prepared['end'];

        $events = is_array($span['events'] ?? null) ? array_values($span['events']) : [];

        if (count($events) === count($prepared['events'])) {
            foreach ($prepared['events'] as $index => $event) {
                if (is_array($events[$index])) {
                    $events[$index]['time'] = $event['time'];
                }
            }

            $span['events'] = $events;
        }

        return $span;
    }

    /**
     * Prepare, encode and post the given translated spans in one request, waiting the given seconds for the answer.
     *
     * @param  list<TranslatedSpan>  $spans
     */
    protected function post(array $spans, int|float $timeout): ExportResult
    {
        $this->unreached = false;

        try {
            $body = $this->encoder->encode($this->platform->prepare($spans), $this->resource());

            $headers = $this->platform->headers();
            // Always gzipped; on the rare failure the body goes plain.
            $compressed = gzencode($body);

            if ($compressed !== false) {
                $body = $compressed;
                $headers['Content-Encoding'] = 'gzip';
            }

            // A redirect is never followed: it would send the headers to another host or turn the POST into a GET.
            $request = $this->http->withHeaders($headers)
                ->withoutRedirecting()
                ->timeout($timeout);

            if ($this->connectTimeout !== null) {
                $request->connectTimeout(min($this->connectTimeout, max(1, (int) ceil($timeout))));
            }

            $response = $request
                ->withBody($body, 'application/json')
                ->post($this->platform->endpoint());
        } catch (ConnectionException) {
            $this->unreached = true;

            return ExportResult::retryable();
        } catch (RequestException $e) {
            $response = $e->response;
        } catch (Throwable $e) {
            Diagnostics::warn('export.error', 'Spans could not be exported ('.$e::class.'). The batch was dropped.');

            return ExportResult::rejected();
        }

        return $this->result($response);
    }

    /**
     * Get the monotonic clock in seconds.
     */
    protected function seconds(): float
    {
        return hrtime(true) / 1_000_000_000;
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
     *
     * A 429 or 503 carries its Retry-After seconds, the two statuses that send one.
     */
    protected function result(Response $response): ExportResult
    {
        if ($response->successful()) {
            // The batch was delivered, so a failure here must not read as a dropped batch.
            Guard::run('export.partial', 'to read a partial success answer', fn () => $this->warnOfRefusedSpans($response));

            return ExportResult::ok();
        }

        $status = $response->status();

        if ($status === 429 || $status === 503) {
            return ExportResult::retryable($this->retryAfterSeconds($response->header('Retry-After')), busy: $status === 429);
        }

        if ($status === 408 || $status >= 500) {
            return ExportResult::retryable();
        }

        $excerpt = $this->excerpt($response->body());

        Diagnostics::warn('export.rejected', "Spans were rejected by the destination with HTTP {$status}: {$excerpt}. The batch was dropped.");

        return ExportResult::rejected();
    }

    /**
     * Get the seconds of a Retry-After header, capped, or null when it is
     * missing or not a whole number of seconds (an HTTP date, a negative
     * number or text). Only the queue drain waits for it.
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
