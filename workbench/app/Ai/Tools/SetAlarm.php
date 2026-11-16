<?php

namespace Workbench\App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * A tool that asks for approval before every call.
 */
class SetAlarm implements Approvable, Tool
{
    use InteractsWithApprovals;

    public function description(): Stringable|string
    {
        return 'Set an alarm.';
    }

    public function handle(Request $request): Stringable|string
    {
        return 'Alarm set.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'time' => $schema->string()->required(),
        ];
    }
}
