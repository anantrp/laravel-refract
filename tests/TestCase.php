<?php

namespace Anantrp\Refract\Tests;

use Anantrp\Refract\Contracts\Transport;
use Anantrp\Refract\RefractServiceProvider;
use Anantrp\Refract\Tests\Support\MemoryTransport;
use Illuminate\Foundation\Application;
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
     * Get the SQLite file the tests use. It outlives an app refresh inside a test, unlike :memory:.
     */
    protected static function sqliteFile(): string
    {
        $path = sys_get_temp_dir().'/refract-tests.sqlite';

        if (! is_file($path)) {
            touch($path);
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
            // Never touch the workbench database file that level 2 uses.
            'database.default' => 'testing',
            'database.connections.testing' => ['driver' => 'sqlite', 'database' => self::sqliteFile(), 'prefix' => ''],
        ]);

        $app['config']->set($this->environmentConfig);

        // The app environment comes from APP_ENV, which config('app.env') stands in for here.
        $environment = $this->environmentConfig['app.env'] ?? null;

        if (is_string($environment)) {
            $app->detectEnvironment(fn () => $environment);
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
