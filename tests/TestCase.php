<?php

namespace Anantrp\Refract\Tests;

use Anantrp\Refract\Contracts\Transport;
use Anantrp\Refract\RefractServiceProvider;
use Anantrp\Refract\Tests\Support\MemoryTransport;
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
     * The transport that receives the neutral spans instead of the configured one.
     */
    protected ?MemoryTransport $memoryTransport = null;

    /**
     * The services to replace before the providers boot, as extenders keyed by service.
     *
     * @var array<string, Closure(mixed, Application): mixed>
     */
    protected array $extenders = [];

    /**
     * Recreate the application with a transport that keeps the neutral spans.
     */
    protected function captureNeutralSpans(): MemoryTransport
    {
        $this->memoryTransport = new MemoryTransport;

        $this->refreshApplication();

        return $this->memoryTransport;
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
            'refract.transport' => 'null',
            // Never touch the workbench database file that the live scenarios use.
            'database.default' => 'testing',
            'database.connections.testing' => ['driver' => 'sqlite', 'database' => self::sqliteFile(), 'prefix' => ''],
        ]);

        $app['config']->set($this->environmentConfig);

        // No test may reach a real destination: a request not faked throws.
        $app->resolving(HttpFactory::class, fn (HttpFactory $http) => $http->preventStrayRequests());

        // The app environment comes from APP_ENV, which config('app.env') stands in for here.
        $environment = $this->environmentConfig['app.env'] ?? null;

        if (is_string($environment)) {
            $app->detectEnvironment(fn () => $environment);
        }

        foreach ($this->extenders as $abstract => $extender) {
            $app->extend($abstract, $extender);
        }

        if ($this->memoryTransport !== null) {
            $transport = $this->memoryTransport;

            $app->extend(Transport::class, fn () => $transport);
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
