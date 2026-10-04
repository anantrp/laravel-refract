<?php

namespace Workbench\App;

use InvalidArgumentException;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use Workbench\App\Ai\Agents\TimeAgent;

class Scenarios
{
    /**
     * Run a matrix row inside a fresh active OTel span and return its trace id.
     */
    public function run(string $row): string
    {
        $runner = match ($row) {
            'R1' => $this->r1(...),
            default => throw new InvalidArgumentException("No scenario for row [{$row}] yet."),
        };

        $traceId = bin2hex(random_bytes(16));
        $spanId = bin2hex(random_bytes(8));

        $scope = Span::wrap(SpanContext::create($traceId, $spanId, TraceFlags::SAMPLED))->activate();

        try {
            $runner();
        } finally {
            $scope->detach();
        }

        return $traceId;
    }

    /**
     * prompt() with 2 steps and 1 tool.
     */
    protected function r1(): void
    {
        TimeAgent::fakeTwoSteps();

        TimeAgent::make()->prompt('What time is it?');
    }
}
