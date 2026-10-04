<?php

namespace Anantrp\Refract;

use Anantrp\Refract\Capture\Content;
use Anantrp\Refract\Capture\FlushPoints;
use Anantrp\Refract\Capture\RecordAgentRuns;
use Anantrp\Refract\Capture\Recorder;
use Anantrp\Refract\Capture\RunContext;
use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Contracts\Transport;
use Anantrp\Refract\Export\GenAiTranslator;
use Anantrp\Refract\Export\HttpExporter;
use Anantrp\Refract\Export\NullExporter;
use Anantrp\Refract\Export\OtlpJson;
use Anantrp\Refract\Export\PlatformFactory;
use Anantrp\Refract\Support\Settings;
use Anantrp\Refract\Transport\NullTransport;
use Anantrp\Refract\Transport\QueueTransport;
use Anantrp\Refract\Transport\SyncTransport;
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
        $this->app->singleton(FlushPoints::class);

        $this->app->singleton(RunContext::class, fn () => new RunContext(
            typeKey: Settings::string('context.participant.type', 'refract.participant_type'),
            idKey: Settings::string('context.participant.id', 'refract.participant_id'),
            keys: array_keys(Settings::map('context.attributes')),
        ));

        $this->app->singleton(Content::class, fn (Application $app) => new Content(
            $app,
            enabled: Settings::bool('capture.content', false),
            maxBytes: Settings::positiveInt('capture.max_bytes', Content::MAX_BYTES, Content::MIN_BYTES),
            maskClass: config('refract.capture.mask'),
        ));

        $this->app->singleton(Exporter::class, function (Application $app) {
            $platform = PlatformFactory::fromConfig();

            return $platform === null ? new NullExporter : new HttpExporter(
                $platform,
                new GenAiTranslator(Settings::map('context.attributes')),
                new OtlpJson,
                Settings::string('environment', $app->environment()),
                Settings::string('service_name', $this->appName()),
            );
        });

        $this->app->singleton(Transport::class, fn (Application $app) => match (Settings::choice('transport', ['sync', 'queue', 'null'], 'sync')) {
            'sync' => new SyncTransport($app->make(Exporter::class)),
            'queue' => new QueueTransport($app, $app->make(Exporter::class)),
            default => new NullTransport,
        });
    }

    /**
     * Get the application's name, the service name when OTEL_SERVICE_NAME is not set.
     */
    protected function appName(): string
    {
        $name = config('app.name');

        return is_string($name) && $name !== '' ? $name : 'Laravel';
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

        $this->app->make(FlushPoints::class)->register($events);
    }
}
