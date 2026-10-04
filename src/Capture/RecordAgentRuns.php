<?php

namespace Anantrp\Refract\Capture;

use Anantrp\Refract\Support\Guard;
use Closure;
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
     * The invocation ids that failed over and are waiting for their next attempt. Cleared at each flush.
     *
     * @var array<string, true>
     */
    protected array $failingOver = [];

    /**
     * Create a new listener instance.
     */
    public function __construct(protected Recorder $recorder, protected RunContext $context, protected Content $content) {}

    /**
     * Register the listeners with the given dispatcher.
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen([PromptingAgent::class, StreamingAgent::class], $this->guarded($this->promptingAgent(...)));
        $events->listen([AgentPrompted::class, AgentStreamed::class], $this->guarded($this->agentPrompted(...)));
        $events->listen(AgentFailed::class, $this->guarded($this->agentFailed(...)));
        $events->listen(AgentFailedOver::class, $this->guarded($this->agentFailedOver(...)));
        $events->listen(StartingStep::class, $this->guarded($this->startingStep(...)));
        $events->listen(StepCompleted::class, $this->guarded($this->stepCompleted(...)));
        $events->listen(StepFailed::class, $this->guarded($this->stepFailed(...)));
        $events->listen(InvokingTool::class, $this->guarded($this->invokingTool(...)));
        $events->listen(ToolInvoked::class, $this->guarded($this->toolInvoked(...)));
        $events->listen(ToolFailed::class, $this->guarded($this->toolFailed(...)));
        $events->listen(ToolApprovalRequested::class, $this->guarded($this->toolApprovalRequested(...)));
        $events->listen(ToolApprovalResolved::class, $this->guarded($this->toolApprovalResolved(...)));

        $this->recorder->flushing(fn () => $this->failingOver = []);
    }

    /**
     * Wrap the given listener so that a failure in it never reaches the app.
     *
     * @return Closure(object): void
     */
    protected function guarded(Closure $listener): Closure
    {
        return function (object $event) use ($listener): void {
            Guard::run('capture.listener', 'to record an agent event', fn () => $listener($event));
        };
    }

    /**
     * Start the run span. A sub-agent run nests under the tool span that called it.
     *
     * A sub-agent run takes the context of the run that called it, at
     * flush, since a new conversation has its id only when that run ends.
     *
     * The attempt after a failover keeps the run span it failed over from,
     * and the span shows the provider and model of that attempt.
     * A run resumed from approval decisions sent no prompt text, so it is
     * marked as resumed and no prompt is ever recorded for it.
     */
    public function promptingAgent(PromptingAgent $event): void
    {
        if (isset($this->failingOver[$event->invocationId])) {
            unset($this->failingOver[$event->invocationId]);

            if ($this->recorder->isOpen($this->runKey($event->invocationId))) {
                $this->recorder->update($this->runKey($event->invocationId), [
                    'provider' => $this->providerName($event->prompt->provider),
                    'model' => $event->prompt->model,
                ]);

                return;
            }
        }

        $agent = $event->prompt->agent;
        $parentTool = $event->prompt->parentToolInvocationId;

        $parentKey = $parentTool === null ? null : $this->toolKey($parentTool);

        $this->recorder->start($this->runKey($event->invocationId), 'invoke_agent', $parentKey, [
            'invocation_id' => $event->invocationId,
            'agent' => $this->agentName($agent),
            'agent_class' => $agent::class,
            'provider' => $this->providerName($event->prompt->provider),
            'model' => $event->prompt->model,
            ...($event->prompt->hasApprovalDecisions() ? ['resumed' => true] : []),
        ],
            context: $parentKey === null ? $this->context->of($agent) : [],
            contextFrom: $parentKey === null ? null : $this->recorder->parentKey($parentKey),
            content: $event->prompt->hasApprovalDecisions() ? [] : $this->content->runInput($event->prompt),
        );
    }

    /**
     * End the run span with its output. A new conversation has its id only now.
     */
    public function agentPrompted(AgentPrompted $event): void
    {
        $conversationId = $event->response->conversationId;

        $this->recorder->end(
            $this->runKey($event->invocationId),
            context: $conversationId === null ? [] : ['session' => $conversationId],
            content: $this->content->runOutput($event->response),
        );
    }

    /**
     * End the run span as failed, after closing its open children as abandoned.
     */
    public function agentFailed(AgentFailed $event): void
    {
        $this->recorder->abandonChildren($this->runKey($event->invocationId));
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
            'final_step' => $event->isFinalStep,
        ], content: $this->content->stepInput($event->messages));
    }

    public function stepCompleted(StepCompleted $event): void
    {
        $response = $event->response;

        $this->recorder->end($this->stepKey($event->invocationId, $event->stepNumber), call: [
            'response_model' => $response->meta->model,
            'finish_reason' => $response->finishReason->value,
            'input_tokens' => $response->usage->inputTokens,
            'output_tokens' => $response->usage->outputTokens,
        ], content: $this->content->stepOutput($response));
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
        ], content: $this->content->toolArguments($event->arguments));
    }

    public function toolInvoked(ToolInvoked $event): void
    {
        $this->recorder->end($this->toolKey($event->toolInvocationId), content: $this->content->toolResult($event->result));
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
