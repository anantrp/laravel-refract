<?php

namespace Workbench\App\Ai\Agents;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Stringable;

#[Model('fake')]
class ChatAgent implements Agent, HasTools, RemembersConversationsContract
{
    use Promptable;
    use RemembersConversations;

    /**
     * Fake two steps: one TimeAgent call, then the answer. TimeAgent runs its own two steps.
     */
    public static function fakeWithSubAgent(): void
    {
        static::fake([
            new ToolCall('call_1', 'TimeAgent', ['task' => 'What time is it?']),
            'TimeAgent says it is 12:00.',
        ]);

        TimeAgent::fakeTwoSteps();
    }

    public function instructions(): Stringable|string
    {
        return 'Chat with the user.';
    }

    public function tools(): iterable
    {
        return [new TimeAgent];
    }
}
