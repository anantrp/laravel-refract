<?php

namespace Anantrp\Refract;

use Anantrp\Refract\Capture\Content;
use Anantrp\Refract\Capture\FlushPoints;
use Anantrp\Refract\Capture\RecordAgentRuns;
use Anantrp\Refract\Capture\Recorder;
use Anantrp\Refract\Capture\RunContext;
use Anantrp\Refract\Console\StatusCommand;
use Anantrp\Refract\Contracts\Collector;
use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Export\GenAiTranslator;
use Anantrp\Refract\Export\HttpExporter;
use Anantrp\Refract\Export\OtlpJson;
use Anantrp\Refract\Export\Platform;
use Anantrp\Refract\Export\Platforms\Langfuse\LangfusePlatform;
use Anantrp\Refract\Export\Platforms\Otlp\OtlpPlatform;
use Anantrp\Refract\Support\Diagnostics;
use Anantrp\Refract\Support\Settings;
use Anantrp\Refract\Transport\Collectors\DatabaseCollector;
use Anantrp\Refract\Transport\Collectors\RedisCollector;
use Anantrp\Refract\Transport\Drain;
use Anantrp\Refract\Transport\DrainTraces;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Database\DatabaseManager as Database;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class RefractServiceProvider extends ServiceProvider
{
    /**
     * The destinations Refract can send to.
     *
     * @var list<string>
     */
    public const DESTINATIONS = ['otlp', 'langfuse'];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/refract.php', 'refract');

        $this->app->singleton(Recorder::class);
        $this->app->singleton(RecordAgentRuns::class);
        $this->app->singleton(FlushPoints::class);

        $this->app->singleton(RunContext::class, fn () => new RunContext(
            keys: array_keys(Settings::map('context.attributes')),
        ));

        $this->app->singleton(Content::class, fn (Application $app) => new Content(
            $app,
            enabled: Settings::bool('capture.content', false),
            maskClass: config('refract.capture.mask'),
        ));

        $this->app->singleton(Exporter::class, fn (Application $app) => new HttpExporter(
            $this->platform() ?? throw new RuntimeException('Refract has no destination to export to: check the warning logged at boot.'),
            new GenAiTranslator(Settings::map('context.attributes')),
            new OtlpJson,
            $app->environment(),
            $this->appName(),
            // With the queue drain, only the drain job exports.
            $this->drain() === 'queue' ? DrainTraces::TIMEOUT : Drain::TIMEOUT,
            $this->drain() === 'queue' ? DrainTraces::CONNECT_TIMEOUT : Drain::CONNECT_TIMEOUT,
            $app->make(HttpExporter::CLIENT),
        ));

        // No event dispatcher: Telescope, Pulse and Nightwatch never record an export.
        $this->app->singleton(HttpExporter::CLIENT, fn () => new HttpFactory);

        $this->app->singleton(Collector::class, fn (Application $app) => match (Settings::choice('collector', ['redis', 'database'], 'redis')) {
            'database' => new DatabaseCollector($app->make(Database::class)->connection(Settings::string('collectors.database.connection') ?: null)),
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
     * Get the platform of the configured destination, or null and one warning when it cannot send.
     *
     * An invalid URL never falls back to another host (Langfuse Cloud, or
     * nothing for OTLP), so keys and headers never go to a host the user
     * did not name.
     */
    protected function platform(): ?Platform
    {
        return match (Settings::choice('destination', self::DESTINATIONS, 'otlp')) {
            'langfuse' => $this->langfuse(),
            default => $this->otlp(),
        };
    }

    /**
     * Build the OTLP platform: REFRACT_OTLP_ENDPOINT is the full traces URL, sent REFRACT_OTLP_HEADERS.
     */
    protected function otlp(): ?OtlpPlatform
    {
        $endpoint = Settings::destinationUrl('destinations.otlp.endpoint');

        if ($endpoint === '') {
            Diagnostics::warn('otlp.endpoint', 'No OTLP endpoint is set. Set REFRACT_OTLP_ENDPOINT. Refract records nothing.');
        }

        return $endpoint === null || $endpoint === '' ? null : new OtlpPlatform($endpoint, Settings::headers('destinations.otlp.headers'));
    }

    /**
     * Build the Langfuse platform: Langfuse Cloud unless LANGFUSE_BASE_URL is set, and both keys.
     */
    protected function langfuse(): ?LangfusePlatform
    {
        $url = Settings::destinationUrl('destinations.langfuse.url');
        $publicKey = Settings::string('destinations.langfuse.public_key');
        $secretKey = Settings::string('destinations.langfuse.secret_key');

        if ($url !== null && ($publicKey === '' || $secretKey === '')) {
            Diagnostics::warn('langfuse.keys', 'Langfuse keys missing. Set LANGFUSE_PUBLIC_KEY and LANGFUSE_SECRET_KEY. Refract records nothing.');
        }

        return $url === null || $publicKey === '' || $secretKey === '' ? null : new LangfusePlatform($url, $publicKey, $secretKey);
    }

    /**
     * Get the application's name, the service name of every trace.
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

            $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'refract-migrations');

            $this->commands([StatusCommand::class]);
        }

        // A destination that cannot send has warned once: Refract stays off, as if disabled.
        if (! Settings::bool('enabled', true) || $this->platform() === null) {
            return;
        }

        $events = $this->app->make(Dispatcher::class);

        $this->app->make(RecordAgentRuns::class)->subscribe($events);

        $this->app->make(FlushPoints::class)->register($events);
    }
}
