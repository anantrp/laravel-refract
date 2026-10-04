<?php

namespace Anantrp\Refract\Capture;

use Anantrp\Refract\Support\Guard;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Context;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Middleware\RememberConversation;
use Laravel\Ai\Models\Conversation;
use Throwable;

/**
 * Reads the context bucket of a run: its session, its participant and the mapped Laravel Context values.
 *
 * A saved run takes its participant from the conversation and ignores
 * Context. A run that is not saved takes it from the two Context keys,
 * only when both are set. The type is always the full class name.
 */
class RunContext
{
    /**
     * Create a new run context reader.
     *
     * @param  string  $typeKey  The Context key holding the participant type.
     * @param  string  $idKey  The Context key holding the participant id.
     * @param  list<string>  $keys  The Context keys copied onto the run span.
     */
    public function __construct(
        protected string $typeKey,
        protected string $idKey,
        protected array $keys,
    ) {}

    /**
     * Get the context bucket for a run of the given agent.
     *
     * When it cannot be read, the run is recorded without it, with one warning.
     *
     * @return array<string, mixed>
     */
    public function of(Agent $agent): array
    {
        return Guard::run('capture.context', 'to read the context of a run', fn () => array_filter([
            'session' => $this->session($agent),
            'participant' => $this->participant($agent),
            'values' => $this->values(),
        ], fn (mixed $value) => $value !== null && $value !== []), []);
    }

    /**
     * Check whether the SDK saves the given agent's runs: by the contract or by the trait alone.
     */
    protected function remembers(Agent $agent): bool
    {
        return RememberConversation::appliesTo($agent);
    }

    /**
     * Get the conversation id the agent continues, if any.
     */
    protected function session(Agent $agent): ?string
    {
        if (! $this->remembers($agent) || ! method_exists($agent, 'currentConversation')) {
            return null;
        }

        $id = $agent->currentConversation();

        return is_string($id) ? $id : null;
    }

    /**
     * @return array{type: string, id: string}|null
     */
    protected function participant(Agent $agent): ?array
    {
        if ($this->remembers($agent) && method_exists($agent, 'conversationParticipant')) {
            $participant = $agent->conversationParticipant();

            if (is_object($participant)) {
                return $this->fromConversation($participant);
            }
        }

        return $this->fromContext();
    }

    /**
     * @return array{type: string, id: string}|null
     */
    protected function fromConversation(object $participant): ?array
    {
        try {
            $id = $this->id(Conversation::participantKey($participant));
        } catch (Throwable) {
            return null;
        }

        return $id === null ? null : ['type' => $participant::class, 'id' => $id];
    }

    /**
     * @return array{type: string, id: string}|null
     */
    protected function fromContext(): ?array
    {
        $type = Context::get($this->typeKey);
        $id = $this->id(Context::get($this->idKey));

        if (! is_string($type) || $type === '' || $id === null) {
            return null;
        }

        return ['type' => Relation::getMorphedModel($type) ?? $type, 'id' => $id];
    }

    /**
     * Get the participant id as a string, or null when it is not a usable id.
     */
    protected function id(mixed $id): ?string
    {
        if (is_int($id)) {
            return (string) $id;
        }

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Get the mapped Context values that are set, keyed by Context key.
     *
     * @return array<string, scalar>
     */
    protected function values(): array
    {
        $values = [];

        foreach ($this->keys as $key) {
            $value = Context::get($key);

            if (is_scalar($value)) {
                $values[$key] = $value;
            }
        }

        return $values;
    }
}
