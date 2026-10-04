<?php

namespace Workbench\App;

use InvalidArgumentException;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use Workbench\App\Ai\Agents\SupervisorAgent;
use Workbench\App\Ai\Agents\TimeAgent;

class Scenarios
{
    /**
     * Run a matrix row inside a fresh active OTel span and return its trace id.
     */
    public function run(string $row): string
    {
        $runner = match ($row) {
            'R1', 'R11' => $this->r1(...),
            'R2' => $this->r2(...),
            'R4' => $this->r4(...),
            'R5' => $this->r5(...),
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
     * prompt() with 2 steps and 1 tool. R11 reads the same run: the tool and
     * the next step start in the same millisecond.
     */
    protected function r1(): void
    {
        TimeAgent::fakeTwoSteps();

        TimeAgent::make()->prompt('What time is it?');
    }

    /**
     * A sub-agent called from a tool.
     */
    protected function r2(): void
    {
        SupervisorAgent::fakeTwoSteps();

        SupervisorAgent::make()->prompt('Ask for the time.');
    }

    /**
     * A stream read to the end.
     */
    protected function r4(): void
    {
        TimeAgent::fakeTwoSteps();

        foreach (TimeAgent::make()->stream('What time is it?') as $event) {
            //
        }
    }

    /**
     * A stream stopped after its first event.
     */
    protected function r5(): void
    {
        TimeAgent::fakeTwoSteps();

        foreach (TimeAgent::make()->stream('What time is it?') as $event) {
            break;
        }
    }
}
