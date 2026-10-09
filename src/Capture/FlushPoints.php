<?php

namespace Anantrp\Refract\Capture;

use Anantrp\Refract\Support\Guard;
use Closure;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Jobs\SyncJob;

/**
 * Flushes the buffer at the end of the execution that owns it.
 *
 * The owner is the web request (flushed when it terminates, after the
 * response is sent), the async job (flushed when it is attempted), or the
 * console command (flushed when it finishes). A job run by a sync-like
 * driver (sync, deferred, background) or a command called from inside a
 * request or another command never owns the buffer: its spans leave with
 * its caller. A flush closes every open span, so a flush from a process
 * that does not own the buffer would cut a live run short.
 *
 * A console process (command, queue worker, tinker) also sends its
 * finished runs while it keeps going, checked when a top-level run ends.
 * That partial flush leaves open spans alone, so any process may run it.
 */
class FlushPoints
{
    /**
     * The number of commands running in this process, nested calls included.
     */
    protected int $commands = 0;

    /**
     * Create a new flush points instance.
     */
    public function __construct(protected Application $app, protected Recorder $recorder) {}

    /**
     * Register the flush points with the application and the given dispatcher.
     */
    public function register(Dispatcher $events): void
    {
        // Web requests, and a last flush when any other process ends. The
        // boot-time callback adds the flush as the last terminating callback,
        // after those added during the request (afterResponse jobs, the app's
        // own callbacks). Application::terminate() re-reads the callback count.
        $this->app->terminating(fn () => $this->guard(fn () => $this->app->terminating($this->flush(...))));

        $events->listen(JobAttempted::class, fn (JobAttempted $event) => $this->guard(fn () => $this->jobAttempted($event)));
        $events->listen(CommandStarting::class, fn (CommandStarting $event) => $this->guard(fn () => $this->commandStarting($event)));
        $events->listen(CommandFinished::class, fn (CommandFinished $event) => $this->guard(fn () => $this->commandFinished($event)));

        $this->recorder->whenRunEnds(fn (string $key) => $this->guard(fn () => $this->runEnded($key)));
        $this->recorder->whenFull(fn () => $this->guard($this->bufferFull(...)));
    }

    /**
     * Run the given hook so that a failure in it never reaches the app.
     *
     * @param  Closure(): mixed  $hook
     */
    protected function guard(Closure $hook): void
    {
        Guard::run('capture.flush', 'to flush spans', $hook);
    }

    /**
     * Flush at the end of an async job. A job run by a sync-like driver runs inside its caller.
     */
    public function jobAttempted(JobAttempted $event): void
    {
        if (! $event->job instanceof SyncJob) {
            $this->flush();
        }
    }

    /**
     * Send the finished runs while a console process keeps going.
     *
     * A web request sends once, after the response.
     */
    public function runEnded(string $key): void
    {
        if ($this->app->runningInConsole()) {
            $this->recorder->flushFinished($key);
        }
    }

    /**
     * Send every finished run when the buffer is full in a console process, so only one run over the cap loses spans.
     *
     * A web request keeps its one send, after the response.
     */
    public function bufferFull(): void
    {
        if ($this->app->runningInConsole()) {
            $this->recorder->flushAllFinished();
        }
    }

    public function commandStarting(CommandStarting $event): void
    {
        $this->commands++;
    }

    /**
     * Flush at the end of the outermost console command.
     */
    public function commandFinished(CommandFinished $event): void
    {
        $this->commands = max(0, $this->commands - 1);

        if ($this->commands === 0 && $this->app->runningInConsole()) {
            $this->flush();
        }
    }

    /**
     * Hand the buffer to the transport. The buffer is cleared even when the flush fails.
     */
    public function flush(): void
    {
        $this->guard($this->recorder->flush(...));
    }
}
