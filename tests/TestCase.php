<?php

namespace Anantrp\Refract\Tests;

use Anantrp\Refract\Contracts\Exporter;
use Anantrp\Refract\Export\HttpExporter;
use Anantrp\Refract\RefractServiceProvider;
use Anantrp\Refract\Tests\Support\MemoryExporter;
use Anantrp\Refract\Transport\ExportBreaker;
use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Laravel\Ai\AiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * The configuration to apply before the providers boot.
     *
     * @var array<string, mixed>
     */
    protected array $environmentConfig = [];

    /**
     * The exporter that receives the run trees instead of the configured one.
     */
    protected ?MemoryExporter $memoryExporter = null;

    /**
     * Whether Refract sends through the app's HTTP client, so Http::fake() sees its requests. In the app it has its own.
     */
    protected bool $shareAppHttpClient = true;

    /**
     * The services to replace before the providers boot, as extenders keyed by service.
     *
     * @var array<string, Closure(mixed, Application): mixed>
     */
    protected array $extenders = [];

    /**
     * Recreate the application with an exporter that keeps the run trees.
     */
    protected function captureNeutralSpans(): MemoryExporter
    {
        $this->memoryExporter = new MemoryExporter;

        $this->refreshApplication();

        return $this->memoryExporter;
    }

    /**
     * Recreate the application with the given configuration applied before boot.
     *
     * @param  array<string, mixed>  $config
     */
    protected function refreshApplicationWithConfig(array $config): void
    {
        $this->environmentConfig = $config;

        $this->refreshApplication();
    }

    /**
     * Replace the given service before the providers boot, from the next app refresh on.
     *
     * @param  Closure(mixed, Application): mixed  $extender
     */
    protected function extendBeforeBoot(string $abstract, Closure $extender): void
    {
        $this->extenders[$abstract] = $extender;
    }

    /**
     * Get the SQLite file the tests use. It outlives an app refresh inside a test, unlike :memory:.
     *
     * Each process has its own file, so parallel and concurrent runs never
     * share one. The file is deleted when the process ends.
     */
    protected static function sqliteFile(): string
    {
        $path = sys_get_temp_dir().'/refract-tests-'.getmypid().'.sqlite';

        if (! is_file($path)) {
            touch($path);

            register_shutdown_function(fn () => is_file($path) && unlink($path));
        }

        return $path;
    }

    /**
     * Define the environment setup.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'ai.default' => 'openai',
            'ai.providers.openai' => ['driver' => 'openai', 'key' => 'test'],
            'ai.conversations.generate_title' => false,
            // Never touch the workbench database file that the live scenarios use.
            'database.default' => 'testing',
            'database.connections.testing' => ['driver' => 'sqlite', 'database' => self::sqliteFile(), 'prefix' => ''],
            // The drain job's unique lock lives in the cache: a file cache would keep it from one test to the next.
            'cache.default' => 'array',
            // A destination that can send, so Refract registers, whatever the local .env says. No request reaches it unless faked.
            'refract.destination' => 'otlp',
            'refract.destinations.otlp.endpoint' => 'https://otlp.test/v1/traces',
        ]);

        $app['config']->set($this->environmentConfig);

        // No test may reach a real destination: a request not faked throws. Refract's own client is an HttpFactory too.
        $app->resolving(HttpFactory::class, fn (HttpFactory $http) => $http->preventStrayRequests());

        if ($this->shareAppHttpClient) {
            // An extender outlives a register() run again on the booted app, as ConfigTest does.
            $app->extend(HttpExporter::CLIENT, fn ($own, $app) => $app->make(HttpFactory::class));
        }

        // A pause after a failed export is kept in the process: never carry one from a test to the next.
        ExportBreaker::reset();

        // The app environment comes from APP_ENV, which config('app.env') stands in for here.
        $environment = $this->environmentConfig['app.env'] ?? null;

        if (is_string($environment)) {
            $app->detectEnvironment(fn () => $environment);
        }

        foreach ($this->extenders as $abstract => $extender) {
            $app->extend($abstract, $extender);
        }

        if ($this->memoryExporter !== null) {
            $exporter = $this->memoryExporter;

            // After the providers register, so the configured exporter is never built.
            $app->booted(fn () => $app->instance(Exporter::class, $exporter));
        }
    }

    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            RefractServiceProvider::class,
        ];
    }
}
