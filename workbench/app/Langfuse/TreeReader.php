<?php

namespace Workbench\App\Langfuse;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Reads a trace's observations back from Langfuse and prints them as a tree.
 *
 * @phpstan-type Observation array{id: string, parent: ?string, name: string, start: float, status: string, environment: string}
 */
class TreeReader
{
    public const CLOUD_URL = 'https://cloud.langfuse.com';

    public function __construct(
        protected string $baseUrl,
        protected string $publicKey,
        protected string $secretKey,
    ) {}

    /**
     * @throws MissingKeys
     */
    public function ensureKeys(): void
    {
        if ($this->publicKey === '' || $this->secretKey === '') {
            throw new MissingKeys;
        }
    }

    /**
     * Wait up to the timeout for the trace's observations, then render them.
     *
     * @throws MissingKeys
     */
    public function tree(string $traceId, bool $withTimes = true, int $timeoutSeconds = 30): string
    {
        $this->ensureKeys();

        $deadline = microtime(true) + $timeoutSeconds;
        $previous = -1;
        $observations = [];

        while (true) {
            $observations = $this->fetch($traceId);
            $count = count($observations);

            if ($count > 0 && $count === $previous) {
                break;
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            $previous = $count;
            usleep(2_000_000);
        }

        return $this->render($observations, $withTimes);
    }

    /**
     * @return list<Observation>
     */
    protected function fetch(string $traceId): array
    {
        $response = Http::withBasicAuth($this->publicKey, $this->secretKey)
            ->acceptJson()
            ->timeout(10)
            ->get($this->url().'/api/public/v2/observations', [
                'traceId' => $traceId,
                'fields' => 'core,basic',
                'limit' => 1000,
            ]);

        $response->throw();

        $data = $response->json('data');

        if (! is_array($data)) {
            return [];
        }

        $observations = [];

        foreach ($data as $row) {
            if (is_array($row)) {
                $observations[] = $this->observation($row);
            }
        }

        return $observations;
    }

    /**
     * @param  array<mixed>  $row
     * @return Observation
     */
    protected function observation(array $row): array
    {
        $level = $this->string($row, 'level');
        $message = $this->string($row, 'statusMessage');
        $status = $level === 'ERROR' ? 'error' : ($level === 'WARNING' ? 'warning' : 'ok');

        if ($message !== '') {
            $status .= " ({$message})";
        }

        $parent = $this->string($row, 'parentObservationId');
        $start = $this->string($row, 'startTime');

        return [
            'id' => $this->string($row, 'id'),
            'parent' => $parent === '' ? null : $parent,
            'name' => $this->string($row, 'name'),
            'start' => $start === '' ? 0.0 : CarbonImmutable::parse($start)->getPreciseTimestamp(3),
            'status' => $status,
            'environment' => $this->string($row, 'environment'),
        ];
    }

    /**
     * @param  list<Observation>  $observations
     */
    protected function render(array $observations, bool $withTimes): string
    {
        if ($observations === []) {
            return '(no spans found)';
        }

        usort($observations, fn (array $a, array $b) => $a['start'] <=> $b['start']);

        $ids = array_column($observations, 'id');
        $origin = $observations[0]['start'];

        $children = [];
        foreach ($observations as $observation) {
            $parent = $observation['parent'];
            $children[$parent !== null && in_array($parent, $ids, true) ? $parent : ''][] = $observation;
        }

        /** @var list<array{string, Observation}> $lines */
        $lines = [];
        $walk = function (string $parent, int $depth) use (&$walk, &$lines, $children): void {
            foreach ($children[$parent] ?? [] as $observation) {
                $lines[] = [str_repeat('  ', $depth).$observation['name'], $observation];
                $walk($observation['id'], $depth + 1);
            }
        };
        $walk('', 0);

        $width = max([0, ...array_map(fn (array $line) => strlen($line[0]), $lines)]);
        $environments = array_unique(array_column($observations, 'environment'));

        $output = ['environment: '.implode(', ', $environments)];

        foreach ($lines as [$label, $observation]) {
            $output[] = $withTimes
                ? str_pad($label, $width).sprintf('  %9.1fms  ', $observation['start'] - $origin).$observation['status']
                : $label.'  '.$observation['status'];
        }

        return implode("\n", $output);
    }

    protected function url(): string
    {
        return rtrim($this->baseUrl === '' ? self::CLOUD_URL : $this->baseUrl, '/');
    }

    /**
     * @param  array<mixed>  $row
     */
    protected function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        return is_string($value) ? $value : '';
    }
}
