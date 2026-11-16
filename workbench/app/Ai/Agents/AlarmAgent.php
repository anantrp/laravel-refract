<?php

namespace Workbench\App\Ai\Agents;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Stringable;
use Workbench\App\Ai\Tools\SetAlarm;

/**
 * Calls SetAlarm, which asks for approval, so the run pauses after its first step.
 */
#[Model('fake')]
class AlarmAgent implements Agent, HasTools
{
    use Promptable;

    /**
     * Fake one SetAlarm call, then the answer once the call is decided.
     */
    public static function fakeWithApproval(): void
    {
        static::fake([
            new ToolCall('call_1', 'SetAlarm', ['time' => '07:00']),
            'The alarm is set for 07:00.',
        ]);
    }

    public function instructions(): Stringable|string
    {
        return 'Set alarms.';
    }

    public function tools(): iterable
    {
        return [new SetAlarm];
    }
}
