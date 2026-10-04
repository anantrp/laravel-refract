<?php

namespace Anantrp\Refract\Tests;

use Anantrp\Refract\RefractServiceProvider;
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
        ]);

        $app['config']->set($this->environmentConfig);
    }

    protected function getPackageProviders($app): array
    {
        return [
            AiServiceProvider::class,
            RefractServiceProvider::class,
        ];
    }
}
