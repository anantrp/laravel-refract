<?php

namespace Anantrp\Refract\Capture;

use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\CanActAsTool;
use Laravel\Ai\Events\AgentFailed;
use Laravel\Ai\Events\AgentFailedOver;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\AgentStreamed;
use Laravel\Ai\Events\InvokingTool;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\StartingStep;
use Laravel\Ai\Events\StepCompleted;
use Laravel\Ai\Events\StepFailed;
use Laravel\Ai\Events\StreamingAgent;
use Laravel\Ai\Events\ToolApprovalRequested;
use Laravel\Ai\Events\ToolApprovalResolved;
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Providers\Provider;
use Laravel\Ai\Tools\ToolNameResolver;

/**
 * Turns Laravel AI SDK agent, step and tool events into spans.
 */
class RecordAgentRuns
{
    /**
     * The invocation ids that failed over and are waiting for their next attempt.
     *
     * @var array<string, true>
     */
    protected array $failingOver = [];

    /**
     * Create a new listener instance.
     */
    public function __construct(protected Recorder $recorder) {}

    /**
     * Register the listeners with the given dispatcher.
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen([PromptingAgent::class, StreamingAgent::class], $this->promptingAgent(...));
        $events->listen([AgentPrompted::class, AgentStreamed::class], $this->agentPrompted(...));
        $events->listen(AgentFailed::class, $this->agentFailed(...));
        $events->listen(AgentFailedOver::class, $this->agentFailedOver(...));
        $events->listen(StartingStep::class, $this->startingStep(...));
        $events->listen(StepCompleted::class, $this->stepCompleted(...));
        $events->listen(StepFailed::class, $this->stepFailed(...));
        $events->listen(InvokingTool::class, $this->invokingTool(...));
        $events->listen(ToolInvoked::class, $this->toolInvoked(...));
        $events->listen(ToolFailed::class, $this->toolFailed(...));
        $events->listen(ToolApprovalRequested::class, $this->toolApprovalRequested(...));
        $events->listen(ToolApprovalResolved::class, $this->toolApprovalResolved(...));
    }

    /**
     * Start the run span. A sub-agent run nests under the tool span that called it.
     *
     * The attempt after a failover keeps the run span it failed over from.
     * A run resumed from approval decisions sent no prompt text, so it is
     * marked as resumed and no prompt is ever recorded for it.
     */
    public function promptingAgent(PromptingAgent $event): void
    {
        if (isset($this->failingOver[$event->invocationId])) {
            unset($this->failingOver[$event->invocationId]);

            if ($this->recorder->isOpen($this->runKey($event->invocationId))) {
                return;
            }
        }

        $agent = $event->prompt->agent;
        $parentTool = $event->prompt->parentToolInvocationId;

        $this->recorder->start($this->runKey($event->invocationId), 'invoke_agent', $parentTool === null ? null : $this->toolKey($parentTool), [
            'invocation_id' => $event->invocationId,
            'agent' => $this->agentName($agent),
            'agent_class' => $agent::class,
            'provider' => $this->providerName($event->prompt->provider),
            'model' => $event->prompt->model,
            ...($event->prompt->hasApprovalDecisions() ? ['resumed' => true] : []),
        ]);
    }

    public function agentPrompted(AgentPrompted $event): void
    {
        $this->recorder->end($this->runKey($event->invocationId));
    }

    public function agentFailed(AgentFailed $event): void
    {
        $this->recorder->end($this->runKey($event->invocationId), 'error', $event->exception::class);
    }

    /**
     * Record the failover as an event on the run span. The next attempt continues that span.
     */
    public function agentFailedOver(AgentFailedOver $event): void
    {
        $this->failingOver[$event->invocationId] = true;

        $this->recorder->event($this->runKey($event->invocationId), 'failover', [
            'provider' => $this->providerName($event->provider),
            'model' => $event->model,
            'error' => $event->exception::class,
        ]);
    }

    public function startingStep(StartingStep $event): void
    {
        $this->recorder->start($this->stepKey($event->invocationId, $event->stepNumber), 'chat', $this->runKey($event->invocationId), [
            'provider' => $this->providerName($event->provider),
            'model' => $event->model,
            'step' => $event->stepNumber,
        ]);
    }

    public function stepCompleted(StepCompleted $event): void
    {
        $response = $event->response;

        $this->recorder->end($this->stepKey($event->invocationId, $event->stepNumber), call: [
            'response_model' => $response->meta->model,
            'finish_reason' => $response->finishReason->value,
            'input_tokens' => $response->usage->inputTokens,
            'output_tokens' => $response->usage->outputTokens,
        ]);
    }

    public function stepFailed(StepFailed $event): void
    {
        $this->recorder->end($this->stepKey($event->invocationId, $event->stepNumber), 'error', $event->exception::class);
    }

    public function invokingTool(InvokingTool $event): void
    {
        $this->recorder->start($this->toolKey($event->toolInvocationId), 'execute_tool', $this->runKey($event->invocationId), [
            'tool' => ToolNameResolver::resolve($event->tool),
            'tool_invocation_id' => $event->toolInvocationId,
        ]);
    }

    public function toolInvoked(ToolInvoked $event): void
    {
        $this->recorder->end($this->toolKey($event->toolInvocationId));
    }

    public function toolFailed(ToolFailed $event): void
    {
        $this->recorder->end($this->toolKey($event->toolInvocationId), 'error', $event->exception::class);
    }

    /**
     * Record each tool call waiting for approval as an event on the run span.
     *
     * The SDK sends this after the run ended, so the event sits at the run's end.
     */
    public function toolApprovalRequested(ToolApprovalRequested $event): void
    {
        foreach ($event->pendingApprovals as $approval) {
            $this->recorder->event($this->runKey($event->invocationId), 'approval_requested', [
                'tool' => $approval->tool,
                'tool_call_id' => $approval->id,
            ]);
        }
    }

    /**
     * Record each approval decision of a resumed run as an event on its run span.
     *
     * The SDK sends this after the run ended, so the event sits at the run's end.
     */
    public function toolApprovalResolved(ToolApprovalResolved $event): void
    {
        foreach ($event->toolResults as $result) {
            $this->recorder->event($this->runKey($event->invocationId), 'approval_resolved', [
                'tool' => $result->name,
                'tool_call_id' => $result->id,
                'approved' => ! $result->denied,
            ]);
        }
    }

    protected function runKey(string $invocationId): string
    {
        return "run:{$invocationId}";
    }

    protected function stepKey(string $invocationId, int $step): string
    {
        return "step:{$invocationId}:{$step}";
    }

    protected function toolKey(string $toolInvocationId): string
    {
        return "tool:{$toolInvocationId}";
    }

    protected function agentName(Agent $agent): string
    {
        return $agent instanceof CanActAsTool ? $agent->name() : class_basename($agent);
    }

    protected function providerName(object $provider): ?string
    {
        return $provider instanceof Provider ? $provider->driver() : null;
    }
}
