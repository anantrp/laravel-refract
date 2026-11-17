<?php

namespace Anantrp\Refract\Console;

use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\RefractServiceProvider;
use Anantrp\Refract\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Sends one test trace to the destination and says what came back.
 *
 * It exports straight through the exporter, never through the drain or
 * the pause after failures, so it checks the destination as it is now.
 */
class TestCommand extends Command
{
    protected $signature = 'refract:test';

    protected $description = 'Send one test trace to the Refract destination';

    /**
     * The seconds the test export may take.
     */
    protected const TIMEOUT = 10;

    public function handle(): int
    {
        $destination = Settings::choice('destination', RefractServiceProvider::DESTINATIONS, 'otlp');
        $traceId = bin2hex(random_bytes(16));

        try {
            $exporter = $this->laravel->make(Exporter::class);
        } catch (Throwable) {
            $this->error("The {$destination} destination cannot send: check the warning logged at boot.");

            return self::FAILURE;
        }

        try {
            $result = $exporter->export([$this->tree($traceId)], self::TIMEOUT);
        } catch (Throwable $e) {
            $this->error('The export failed ('.$e::class.').');

            return self::FAILURE;
        }

        if ($result->isOk()) {
            $this->info("Sent. Look for trace {$traceId} in {$destination}.");

            return self::SUCCESS;
        }

        $this->error($result->isRejected()
            ? 'Rejected: check the keys and endpoint.'
            : 'Could not reach the destination, or it answered 408, 429 or 5xx.');

        return self::FAILURE;
    }

    /**
     * Get a run tree of one neutral invoke_agent span, named refract:test, that ended now.
     *
     * @return list<array<string, mixed>>
     */
    protected function tree(string $traceId): array
    {
        $end = (int) Carbon::now()->getPreciseTimestamp(6) * 1000;

        return [[
            'v' => 1, 'trace_id' => $traceId, 'span_id' => bin2hex(random_bytes(8)), 'parent_span_id' => null,
            'kind' => 'invoke_agent', 'start' => $end - 1_000_000, 'end' => $end, 'status' => 'ok', 'status_message' => null,
            'call' => ['agent' => 'refract:test'], 'content' => [], 'context' => [], 'events' => [],
        ]];
    }
}
