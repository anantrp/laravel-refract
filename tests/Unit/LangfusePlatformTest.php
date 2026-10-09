<?php

use Anantrp\Refract\Export\Platforms\Langfuse\LangfusePlatform;

function translatedSpan(string $id, ?string $parent, int $start, int $end): array
{
    return [
        'trace_id' => str_repeat('a', 32),
        'span_id' => $id,
        'parent_span_id' => $parent,
        'name' => $id,
        'kind' => 1,
        'start' => $start,
        'end' => $end,
        'attributes' => [],
        'events' => [],
        'status' => ['code' => 1, 'message' => null],
    ];
}

it('Langfuse moves siblings that start in the same ms to distinct ms, children stay inside their parent', function () {
    $ms = 1_000_000;
    $base = 1_700_000_000_000 * $ms;

    $spans = (new LangfusePlatform('', 'pk', 'sk'))->prepare([
        translatedSpan('run', null, $base, $base + 10 * $ms),
        translatedSpan('chat0', 'run', $base + 100, $base + 2 * $ms),
        translatedSpan('tool', 'run', $base + 3 * $ms + 100, $base + 3 * $ms + 200),
        translatedSpan('chat1', 'run', $base + 3 * $ms + 300, $base + 3 * $ms + 400),
        translatedSpan('sub', 'chat1', $base + 3 * $ms + 350, $base + 3 * $ms + 360),
    ]);

    $byId = array_column($spans, null, 'span_id');
    $msOf = fn (string $id) => intdiv($byId[$id]['start'], $ms);

    expect($msOf('tool'))->toBe(intdiv($base, $ms) + 3)
        ->and($msOf('chat1'))->toBe(intdiv($base, $ms) + 4)
        ->and($byId['sub']['start'])->toBeGreaterThanOrEqual($byId['chat1']['start'])
        ->and($byId['chat1']['end'])->toBeGreaterThanOrEqual($byId['chat1']['start'])
        ->and($byId['sub']['end'])->toBeGreaterThanOrEqual($byId['sub']['start'])
        ->and($byId['run']['end'])->toBeGreaterThanOrEqual($byId['chat1']['end'])
        ->and($byId['chat1']['end'])->toBeGreaterThanOrEqual($byId['sub']['end'])
        ->and(array_column($spans, 'span_id'))->toBe(['run', 'chat0', 'tool', 'chat1', 'sub']);
});

it('Langfuse keeps sequential siblings in order after it moves a span', function () {
    $ms = 1_000_000;
    $base = 1_700_000_000_000 * $ms;

    // A tool runs a sub-agent whose steps tie in one ms; the next step starts after the tool ends.
    $spans = (new LangfusePlatform('', 'pk', 'sk'))->prepare([
        translatedSpan('run', null, $base, $base + 900),
        translatedSpan('tool', 'run', $base + 100, $base + 500),
        translatedSpan('sub', 'tool', $base + 200, $base + 400),
        translatedSpan('subChat0', 'sub', $base + 250, $base + 260),
        translatedSpan('subChat1', 'sub', $base + 300, $base + 310),
        translatedSpan('subChat2', 'sub', $base + 320, $base + 330),
        translatedSpan('chat1', 'run', $base + 600, $base + 700),
    ]);

    $byId = array_column($spans, null, 'span_id');

    expect($byId['chat1']['start'])->toBeGreaterThanOrEqual($byId['tool']['end'])
        ->and($byId['tool']['end'])->toBeGreaterThanOrEqual($byId['sub']['end'])
        ->and($byId['sub']['end'])->toBeGreaterThanOrEqual($byId['subChat2']['end'])
        ->and(intdiv($byId['subChat1']['start'], $ms))->toBeGreaterThan(intdiv($byId['subChat0']['start'], $ms))
        ->and(intdiv($byId['chat1']['start'], $ms))->toBeGreaterThan(intdiv($byId['tool']['start'], $ms))
        ->and($byId['run']['end'])->toBeGreaterThanOrEqual($byId['chat1']['end']);
});

it('Langfuse keeps events inside their span after it moves the span', function () {
    $ms = 1_000_000;
    $base = 1_700_000_000_000 * $ms;

    $run = translatedSpan('run', null, $base, $base + 900);
    $run['events'] = [['name' => 'late', 'time' => $base + 900, 'attributes' => []]];

    $chat1 = translatedSpan('chat1', 'run', $base + 300, $base + 400);
    $chat1['events'] = [['name' => 'inside', 'time' => $base + 350, 'attributes' => []]];

    $spans = (new LangfusePlatform('', 'pk', 'sk'))->prepare([
        $run,
        translatedSpan('chat0', 'run', $base + 100, $base + 200),
        $chat1,
    ]);

    $byId = array_column($spans, null, 'span_id');

    expect(intdiv($byId['chat1']['start'], $ms))->toBe(intdiv($base, $ms) + 1);

    foreach ($spans as $span) {
        foreach ($span['events'] as $event) {
            expect($event['time'])->toBeGreaterThanOrEqual($span['start'])
                ->and($event['time'])->toBeLessThanOrEqual($span['end']);
        }
    }
});
