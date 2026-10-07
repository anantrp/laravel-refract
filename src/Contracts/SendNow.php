<?php

namespace Anantrp\Refract\Contracts;

/**
 * A transport that can send while the process keeps going, and give back the spans to try again later.
 *
 * Capture uses it for the sends made while a console process runs. A
 * transport without it gets those batches through send().
 */
interface SendNow extends RetryAfter
{
    /**
     * Send the given batch of neutral spans while the process keeps going.
     *
     * A part exported in this process that the destination could not take
     * now (Retryable) is given back, not dropped. A part that got through,
     * or was rejected, is never given back.
     *
     * @param  list<array<string, mixed>>  $spans
     * @return list<array<string, mixed>> The neutral spans to try again later, as given.
     */
    public function sendNow(array $spans): array;
}
