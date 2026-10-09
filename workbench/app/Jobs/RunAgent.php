<?php

namespace Workbench\App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use Workbench\App\Ai\Agents\TimeAgent;

class RunAgent implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance, run inside the given trace when one is given.
     */
    public function __construct(public ?string $traceId = null, public ?string $spanId = null) {}

    public function handle(): void
    {
        $scope = $this->traceId !== null && $this->spanId !== null
            ? Span::wrap(SpanContext::create($this->traceId, $this->spanId, TraceFlags::SAMPLED))->activate()
            : null;

        try {
            TimeAgent::fakeTwoSteps();

            TimeAgent::make()->prompt('What time is it?');
        } finally {
            $scope?->detach();
        }
    }
}
