<?php

namespace Workbench\App;

use InvalidArgumentException;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use Workbench\App\Ai\Agents\ChatAgent;
use Workbench\App\Ai\Agents\SupervisorAgent;
use Workbench\App\Ai\Agents\TimeAgent;
use Workbench\App\Jobs\RunAgent;
use Workbench\App\Models\User;

class Scenarios
{
    /**
     * Get the environment variables the row's scenario process runs with.
     *
     * @return array<string, string>
     */
    public function environment(string $row): array
    {
        return match ($row) {
            'X9' => ['APP_ENV' => 'Staging EU 1'],
            'E10' => ['OTEL_INSTRUMENTATION_GENAI_CAPTURE_MESSAGE_CONTENT' => 'true', 'REFRACT_CAPTURE_MAX_BYTES' => '2000000'],
            default => [],
        };
    }

    /**
     * Get the Refract config the row's scenario runs with in a web request, set before Refract boots.
     *
     * @return array<string, mixed>
     */
    public function config(string $row): array
    {
        return match ($row) {
            'L2' => ['refract.transport' => 'queue'],
            default => [],
        };
    }

    /**
     * Run a matrix row inside a fresh active OTel span and return its trace id.
     */
    public function run(string $row): string
    {
        $runner = match ($row) {
            'R1', 'R11', 'L1', 'L2', 'L10' => $this->r1(...),
            'R2' => $this->r2(...),
            'R4' => $this->r4(...),
            'R5' => $this->r5(...),
            'X1' => $this->x1(...),
            'X9' => $this->r1(...),
            'L6' => $this->l6(...),
            'E10' => $this->e10(...),
            'L12' => $this->l12(...),
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
     * 5 runs of 2 spans, each answer 1 MB of random text. With content
     * capture on, the answer is on the run and the step span: about 10 MB
     * of OTLP JSON in all, 10 spans.
     */
    protected function e10(): void
    {
        foreach (range(1, 5) as $run) {
            TimeAgent::fake([bin2hex(random_bytes(500_000))]);

            TimeAgent::make()->prompt("Run {$run}: answer at length.");
        }
    }

    /**
     * 400 R1-shaped runs (1,600 spans), then a 30 s sleep before the
     * command ends. Finished runs are sent while it runs. The trace id goes
     * to stderr before the sleep, for `refract:tree` in a second terminal.
     */
    protected function l12(): void
    {
        foreach (range(1, 400) as $run) {
            $this->r1();
        }

        fwrite(STDERR, 'sleeping 30 s, trace '.Span::getCurrent()->getContext()->getTraceId().PHP_EOL);

        sleep(30);
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

    /**
     * A saved run with a User participant (id 42).
     */
    protected function x1(): void
    {
        $user = User::query()->updateOrCreate(['id' => 42], [
            'name' => 'Refract X1',
            'email' => 'x1@refract.test',
            'password' => 'not-used',
        ]);

        ChatAgent::fake(['Hello.']);

        ChatAgent::make()->forUser($user)->prompt('Hello');
    }

    /**
     * An agent run in a queued job, inside this scenario's trace. A worker runs it.
     */
    protected function l6(): void
    {
        $context = Span::getCurrent()->getContext();

        RunAgent::dispatch($context->getTraceId(), $context->getSpanId());
    }
}
