<?php

namespace Anantrp\Refract\Export\Platforms\Langfuse;

use Anantrp\Refract\Export\GenAiTranslator;
use Anantrp\Refract\Export\Platform;

/**
 * Langfuse: Basic auth, its ingestion header and its OTLP path.
 *
 * @see https://langfuse.com/integrations/native/opentelemetry
 *
 * @phpstan-import-type TranslatedSpan from GenAiTranslator
 */
class LangfusePlatform implements Platform
{
    /**
     * The Langfuse Cloud URL used when no base URL is set.
     */
    public const CLOUD_URL = 'https://cloud.langfuse.com';

    /**
     * The path Langfuse receives OTLP traces on.
     */
    public const OTEL_PATH = '/api/public/otel/v1/traces';

    /**
     * The Langfuse ingestion version the spans are written for.
     */
    public const INGESTION_VERSION = '4';

    /**
     * The nanoseconds in the millisecond Langfuse stores times with.
     */
    protected const MILLISECOND = 1_000_000;

    /**
     * Create a new Langfuse platform instance.
     */
    public function __construct(
        protected string $url,
        protected string $publicKey,
        protected string $secretKey,
    ) {}

    public function endpoint(): string
    {
        return rtrim($this->url === '' ? self::CLOUD_URL : $this->url, '/').self::OTEL_PATH;
    }

    public function headers(): array
    {
        return [
            'Authorization' => 'Basic '.base64_encode($this->publicKey.':'.$this->secretKey),
            'x-langfuse-ingestion-version' => self::INGESTION_VERSION,
        ];
    }

    public function resource(array $attributes): array
    {
        return $attributes;
    }

    /**
     * Move siblings that start in the same millisecond to distinct milliseconds,
     * and mark abandoned spans.
     *
     * Langfuse stores times in milliseconds and orders tied siblings at
     * random. Each trace is walked in start order, and every move shifts all
     * later times of that trace by the same amount, so a parent still covers
     * its children, a span that started after another ended still does, and
     * events stay inside their span.
     */
    public function prepare(array $spans): array
    {
        $traces = [];

        foreach ($spans as $index => $span) {
            $traces[$span['trace_id']][] = $index;
        }

        foreach ($traces as $indexes) {
            $spans = $this->spread($spans, $indexes);
        }

        return array_map($this->markAbandoned(...), $spans);
    }

    /**
     * Show an abandoned span as a warning, since Langfuse reads only the error status.
     *
     * @param  TranslatedSpan  $span
     * @return TranslatedSpan
     */
    protected function markAbandoned(array $span): array
    {
        if (($span['attributes']['laravel.ai.abandoned'] ?? false) === true) {
            $span['attributes']['langfuse.observation.level'] = 'WARNING';
            $span['attributes']['langfuse.observation.status_message'] = 'abandoned';
        }

        return $span;
    }

    /**
     * Spread the tied siblings of one trace.
     *
     * @param  list<TranslatedSpan>  $spans
     * @param  list<int>  $indexes
     * @return list<TranslatedSpan>
     */
    protected function spread(array $spans, array $indexes): array
    {
        $depths = $this->depths($spans, $indexes);

        usort($indexes, fn (int $a, int $b) => [$spans[$a]['start'], $depths[$a]] <=> [$spans[$b]['start'], $depths[$b]]);

        $shift = 0;
        $shifts = [];
        $steps = [];
        $lastMillisecond = [];

        foreach ($indexes as $index) {
            $original = $spans[$index]['start'];
            $start = $original + $shift;
            $millisecond = intdiv($start, self::MILLISECOND);
            $parent = $spans[$index]['parent_span_id'] ?? '';

            if (isset($lastMillisecond[$parent]) && $millisecond <= $lastMillisecond[$parent]) {
                $millisecond = $lastMillisecond[$parent] + 1;
                $start = $millisecond * self::MILLISECOND;
            }

            $lastMillisecond[$parent] = $millisecond;
            $shift = $start - $original;
            $shifts[$index] = $shift;
            $steps[] = [$original, $shift];
        }

        foreach ($indexes as $index) {
            $span = $spans[$index];
            $start = $span['start'] + $shifts[$index];
            $end = max($this->move($span['end'], $steps), $start);

            $span['start'] = $start;
            $span['end'] = $end;
            $span['events'] = array_map(fn (array $event) => [
                ...$event,
                'time' => min(max($this->move($event['time'], $steps), $start), $end),
            ], $span['events']);

            $spans[$index] = $span;
        }

        return $spans;
    }

    /**
     * Get the depth of each span in its trace, so a parent sorts before a child that starts with it.
     *
     * @param  list<TranslatedSpan>  $spans
     * @param  list<int>  $indexes
     * @return array<int, int>
     */
    protected function depths(array $spans, array $indexes): array
    {
        $parents = [];

        foreach ($indexes as $index) {
            $parents[$spans[$index]['span_id']] = $spans[$index]['parent_span_id'];
        }

        $depths = [];

        foreach ($indexes as $index) {
            $depth = 0;
            $parent = $spans[$index]['parent_span_id'];

            // Bounded by the span count, so a cycle cannot loop forever.
            while ($parent !== null && isset($parents[$parent]) && $depth < count($indexes)) {
                $parent = $parents[$parent];
                $depth++;
            }

            $depths[$index] = $depth;
        }

        return $depths;
    }

    /**
     * Shift a time by the move of the last span that started at or before it.
     *
     * @param  list<array{int, int}>  $steps  The original start and the shift of each span, in start order.
     */
    protected function move(int $time, array $steps): int
    {
        $low = 0;
        $high = count($steps) - 1;
        $shift = 0;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);

            if ($steps[$middle][0] <= $time) {
                $shift = $steps[$middle][1];
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $time + $shift;
    }
}
