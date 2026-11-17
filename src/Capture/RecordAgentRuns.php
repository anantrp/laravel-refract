<?php

namespace Anantrp\Refract\Capture;

use Anantrp\Refract\Support\Guard;
use Anantrp\Refract\Support\Stats;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Laravel\Ai\Contracts\Agent;
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
use Laravel\Ai\Events\ToolFailed;
use Laravel\Ai\Events\ToolInvoked;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Prompts\AgentPrompt;
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
     * The step input each run recorded so far: its bytes, and the messages of its last step. Keyed by invocation id.
     *
     * @var array<string, array{bytes: int, messages: int}>
     */
    protected array $budgets = [];

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

        $this->recorder->flushing(fn () => $this->failingOver = []);
        $this->recorder->flushing(fn () => $this->content->forget());
        $this->recorder->flushing(fn () => $this->budgets = []);
        $this->recorder->flushing(fn () => Stats::flush());
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
     * Start the run span. A sub-agent run nests under the tool span that
     * called it. A run that another open run prompted outside any tool,
     * like the one that titles a new conversation, nests under that run.
     *
     * A nested run takes the context of the run above it, at flush,
     * since a new conversation has its id only when that run ends.
     *
     * The attempt after a failover keeps the run span it failed over from,
     * and the span shows the provider and model of that attempt. A run
     * dropped at the cap stays dropped for that attempt, with its children.
     * A run resumed from approval decisions sent no prompt text, so it is
     * marked as resumed and no prompt is ever recorded for it. Each of its
     * decisions is an event on the new run span.
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

            if ($this->recorder->isDropped($this->runKey($event->invocationId))) {
                return;
            }
        }

        $agent = $event->prompt->agent;
        $parentKey = $this->parentKeyOf($event->prompt);

        $this->recorder->start($this->runKey($event->invocationId), 'invoke_agent', $parentKey, [
            'invocation_id' => $event->invocationId,
            'agent' => $this->agentName($agent, $parentKey),
            'agent_class' => $agent::class,
            'provider' => $this->providerName($event->prompt->provider),
            'model' => $event->prompt->model,
            ...($event->prompt->hasApprovalDecisions() ? ['resumed' => true] : []),
        ],
            context: $parentKey === null ? $this->context->of($agent) : [],
            contextFrom: $parentKey === null ? null : $this->contextSourceOf($event->prompt, $parentKey),
            content: $event->prompt->hasApprovalDecisions() ? [] : $this->content->runInput($event->prompt),
        );

        $this->recordDecisions($this->runKey($event->invocationId), $event->prompt);
    }

    /**
     * Record each approval decision of a resumed run as an event on its run span.
     *
     * A decision names only the tool call id ("*" for every call without
     * its own decision). The tool name comes from the matching tool call in
     * the prompt's own messages; a conversation the SDK loads from its store
     * is not read, so a decision for a call not in them has no tool name.
     */
    protected function recordDecisions(string $key, AgentPrompt $prompt): void
    {
        $decisions = $prompt->approvalDecisions;

        if ($decisions === null) {
            return;
        }

        $tools = $this->toolNames($prompt);

        foreach ($decisions->all() as $id => $decision) {
            $this->recorder->event($key, 'approval_resolved', [
                ...(isset($tools[$id]) ? ['tool' => $tools[$id]] : []),
                // An id of digits only is an integer array key.
                'tool_call_id' => (string) $id,
                'action' => $decision->action,
                'approved' => ! $decision->isRejected(),
            ]);
        }
    }

    /**
     * Get the name of each tool call in the prompt's messages, keyed by the tool call id.
     *
     * @return array<string, string>
     */
    protected function toolNames(AgentPrompt $prompt): array
    {
        $names = [];

        foreach ($prompt->messages ?? [] as $message) {
            if ($message instanceof AssistantMessage) {
                foreach ($message->toolCalls as $toolCall) {
                    $names[$toolCall->id] = $toolCall->name;
                }
            }
        }

        return $names;
    }

    /**
     * End the run span with its output. A new conversation has its id only now.
     *
     * Each tool call the run paused on for approval is an event on the run
     * span, recorded before it ends.
     */
    public function agentPrompted(AgentPrompted $event): void
    {
        unset($this->budgets[$event->invocationId]);

        $key = $this->runKey($event->invocationId);
        $conversationId = $event->response->conversationId;

        foreach ($event->response->pendingApprovals as $approval) {
            $this->recorder->event($key, 'approval_requested', [
                'tool' => $approval->tool,
                'tool_call_id' => $approval->id,
            ]);
        }

        $this->recorder->end(
            $key,
            context: $conversationId === null ? [] : ['session' => $conversationId],
            content: $this->recorder->isOpen($key) ? $this->content->runOutput($event->response) : [],
        );
    }

    /**
     * End the run span as failed, after closing its open children as abandoned.
     */
    public function agentFailed(AgentFailed $event): void
    {
        unset($this->budgets[$event->invocationId]);

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
        ], content: $this->stepInput($event));
    }

    /**
     * Get the input of a step, within its run's budget.
     *
     * A run sends its whole history again at each step. Once the run has
     * recorded RUN_BUDGET bytes of step input, a step records only the
     * messages new since the step before, after a marker, and the run is
     * marked content_truncated. Step 1 is always recorded whole.
     *
     * @return array<string, mixed>
     */
    protected function stepInput(StartingStep $event): array
    {
        $messages = array_values($event->messages);
        $budget = $this->budgets[$event->invocationId] ?? ['bytes' => 0, 'messages' => 0];
        $this->budgets[$event->invocationId] = ['bytes' => $budget['bytes'], 'messages' => count($messages)];

        if ($budget['bytes'] < Content::RUN_BUDGET || $budget['messages'] === 0) {
            $content = $this->content->stepInput($messages);

            $this->budgets[$event->invocationId]['bytes'] += strlen((string) json_encode($content['input'] ?? []));

            return $content;
        }

        $content = $this->content->stepInput(array_slice($messages, $budget['messages']));

        if (! isset($content['input']) || ! is_array($content['input'])) {
            return $content;
        }

        $this->recorder->update($this->runKey($event->invocationId), ['content_truncated' => true]);

        return ['input' => [
            ['role' => 'system', 'parts' => [['type' => 'text', 'content' => "[{$budget['messages']} earlier messages omitted, see step 1]"]]],
            ...$content['input'],
        ]];
    }

    public function stepCompleted(StepCompleted $event): void
    {
        $key = $this->stepKey($event->invocationId, $event->stepNumber);
        $response = $event->response;

        $this->recorder->end($key, call: [
            'response_model' => $response->meta->model,
            'finish_reason' => $response->finishReason->value,
            'input_tokens' => $response->usage->inputTokens,
            'output_tokens' => $response->usage->outputTokens,
        ], content: $this->recorder->isOpen($key) ? $this->content->stepOutput($response) : []);
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
        $key = $this->toolKey($event->toolInvocationId);

        $this->recorder->end($key, content: $this->recorder->isOpen($key) ? $this->content->toolResult($event->result) : []);
    }

    public function toolFailed(ToolFailed $event): void
    {
        $this->recorder->end($this->toolKey($event->toolInvocationId), 'error', $event->exception::class);
    }

    /**
     * Get the key of the span a run nests under, or null for a top-level run.
     *
     * The SDK names the calling run without a tool when that run prompts
     * an agent from its own middleware. It is nested only while open, so a
     * run that outlives it still becomes its own trace, as it did before.
     */
    protected function parentKeyOf(AgentPrompt $prompt): ?string
    {
        if ($prompt->parentToolInvocationId !== null) {
            return $this->toolKey($prompt->parentToolInvocationId);
        }

        if ($prompt->parentInvocationId !== null && $this->recorder->isOpen($this->runKey($prompt->parentInvocationId))) {
            return $this->runKey($prompt->parentInvocationId);
        }

        return null;
    }

    /**
     * Get the key of the run whose context a nested run takes: the run
     * above the calling tool span, or the parent run itself.
     */
    protected function contextSourceOf(AgentPrompt $prompt, string $parentKey): ?string
    {
        return $prompt->parentToolInvocationId === null ? $parentKey : $this->recorder->parentKey($parentKey);
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

    /**
     * Get the agent's name without running its code: a sub-agent takes the
     * name of the tool span that called it, which the SDK resolved for the
     * tool; any other run takes its class name. The SDK calls name() only
     * when it wraps the agent as a tool.
     */
    protected function agentName(Agent $agent, ?string $parentKey): string
    {
        $tool = $parentKey === null ? null : ($this->recorder->call($parentKey)['tool'] ?? null);

        return is_string($tool) && $tool !== '' ? $tool : class_basename($agent);
    }

    protected function providerName(object $provider): ?string
    {
        return $provider instanceof Provider ? $provider->driver() : null;
    }
}
