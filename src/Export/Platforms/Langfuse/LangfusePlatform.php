<?php

namespace Anantrp\Refract\Export\Platforms\Langfuse;

use Anantrp\Refract\Export\Platform;

/**
 * Langfuse: Basic auth, its ingestion header and its OTLP path.
 *
 * @see https://langfuse.com/integrations/native/opentelemetry
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
     * Move siblings that start in the same millisecond to distinct milliseconds.
     *
     * Langfuse stores times in milliseconds and orders tied siblings at
     * random. Parents are moved before their children, so a child never
     * starts before its parent, and a parent is stretched to cover its
     * children. A span outside the parent tree (a cycle)
     * is left as it is.
     */
    public function prepare(array $spans): array
    {
        $ids = [];

        foreach ($spans as $span) {
            $ids[$span['trace_id'].'/'.$span['span_id']] = true;
        }

        $children = [];

        foreach ($spans as $index => $span) {
            $parent = $span['trace_id'].'/'.($span['parent_span_id'] ?? '');
            $children[isset($ids[$parent]) ? $parent : $span['trace_id'].'/'][] = $index;
        }

        $roots = array_keys(array_diff_key($children, $ids));
        $queue = array_map(fn (string $key) => [$key, PHP_INT_MIN, null], $roots);
        $order = [];

        while ($queue !== []) {
            [$parent, $floor, $parentIndex] = array_shift($queue);

            $group = $children[$parent] ?? [];
            usort($group, fn (int $a, int $b) => $spans[$a]['start'] <=> $spans[$b]['start']);

            $previous = null;

            foreach ($group as $index) {
                $span = $spans[$index];
                $start = max($span['start'], $floor);
                $millisecond = intdiv($start, self::MILLISECOND);

                if ($previous !== null && $millisecond <= $previous) {
                    $millisecond = $previous + 1;
                    $start = $millisecond * self::MILLISECOND;
                }

                $previous = $millisecond;
                $span['start'] = $start;
                $span['end'] = max($span['end'], $start);
                $spans[$index] = $span;

                $order[] = [$index, $parentIndex];
                $queue[] = [$span['trace_id'].'/'.$span['span_id'], $start, $index];
            }
        }

        // Children first, so a parent still covers every child it moved.
        foreach (array_reverse($order) as [$index, $parentIndex]) {
            if ($parentIndex !== null && $spans[$parentIndex]['end'] < $spans[$index]['end']) {
                $parentSpan = $spans[$parentIndex];
                $parentSpan['end'] = $spans[$index]['end'];
                $spans[$parentIndex] = $parentSpan;
            }
        }

        return $spans;
    }
}
