<?php

namespace Anantrp\Refract;

use Anantrp\Refract\Capture\Content;
use Anantrp\Refract\Capture\FlushPoints;
use Anantrp\Refract\Capture\RecordAgentRuns;
use Anantrp\Refract\Capture\Recorder;
use Anantrp\Refract\Capture\RunContext;
use Anantrp\Refract\Contracts\Collector;
use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Export\GenAiTranslator;
use Anantrp\Refract\Export\HttpExporter;
use Anantrp\Refract\Export\NullExporter;
use Anantrp\Refract\Export\OtlpJson;
use Anantrp\Refract\Export\PlatformFactory;
use Anantrp\Refract\Support\Settings;
use Anantrp\Refract\Transport\Collectors\RedisCollector;
use Anantrp\Refract\Transport\Drain;
use Anantrp\Refract\Transport\DrainTraces;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as Redis;
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
                // With the queue drain, only the drain job exports.
                $this->drain() === 'queue' ? DrainTraces::TIMEOUT : Drain::TIMEOUT,
                Settings::choice('compression', ['gzip', 'none'], 'gzip') === 'gzip',
            );
        });

        $this->app->singleton(Collector::class, fn (Application $app) => match (Settings::choice('collector', ['redis'], 'redis')) {
            default => new RedisCollector($app->make(Redis::class)->connection(Settings::string('collectors.redis.connection') ?: null)),
        });

        $this->app->singleton(Drain::class, fn (Application $app) => new Drain($app, $this->drain()));
    }

    /**
     * Get the drain finished run trees take: "sync" or "queue".
     */
    protected function drain(): string
    {
        return Settings::choice('drain', ['sync', 'queue'], 'sync');
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
