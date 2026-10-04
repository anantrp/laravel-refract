<?php

namespace Anantrp\Refract;

use Anantrp\Refract\Capture\RecordAgentRuns;
use Anantrp\Refract\Capture\Recorder;
use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Contracts\Transport;
use Anantrp\Refract\Export\GenAiTranslator;
use Anantrp\Refract\Export\HttpExporter;
use Anantrp\Refract\Export\OtlpJson;
use Anantrp\Refract\Export\Platform;
use Anantrp\Refract\Export\Platforms\Langfuse\LangfusePlatform;
use Anantrp\Refract\Support\Settings;
use Anantrp\Refract\Transport\NullTransport;
use Anantrp\Refract\Transport\SyncTransport;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class RefractServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/refract.php', 'refract');

        $this->app->singleton(Recorder::class);
        $this->app->singleton(RecordAgentRuns::class);

        $this->app->singleton(Platform::class, fn () => new LangfusePlatform(
            url: Settings::string('destinations.langfuse.url'),
            publicKey: Settings::string('destinations.langfuse.public_key'),
            secretKey: Settings::string('destinations.langfuse.secret_key'),
        ));

        $this->app->singleton(Exporter::class, fn (Application $app) => new HttpExporter(
            $app->make(Platform::class),
            new GenAiTranslator,
            new OtlpJson,
            Settings::string('environment', $app->environment()),
        ));

        $this->app->singleton(Transport::class, function (Application $app) {
            $transport = Settings::choice('transport', ['sync', 'queue', 'null'], 'sync');
            $destination = Settings::choice('destination', ['otlp', 'langfuse'], 'otlp');

            return $transport === 'sync' && $destination === 'langfuse'
                ? new SyncTransport($app->make(Exporter::class))
                : new NullTransport;
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/refract.php' => config_path('refract.php')], 'refract-config');
        }

        if (! Settings::bool('enabled', true)) {
            return;
        }

        $events = $this->app->make(Dispatcher::class);

        $this->app->make(RecordAgentRuns::class)->subscribe($events);

        $events->listen(CommandFinished::class, fn () => $this->app->make(Recorder::class)->flush());
    }
}
