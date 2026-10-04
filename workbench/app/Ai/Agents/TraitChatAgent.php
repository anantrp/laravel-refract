<?php

namespace Workbench\App\Ai\Agents;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * Saves its conversations through the SDK trait only, without the contract.
 */
#[Model('fake')]
class TraitChatAgent implements Agent
{
    use Promptable;
    use RemembersConversations;

    public function instructions(): Stringable|string
    {
        return 'Chat with the user.';
    }
}
