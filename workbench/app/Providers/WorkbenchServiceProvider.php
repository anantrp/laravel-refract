<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Workbench\App\Langfuse\TreeReader;
use Workbench\App\Scenarios;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/workbench.php', 'workbench');

        config(['ai.conversations.generate_title' => false]);

        $this->configureScenarioRequest();

        $this->app->singleton(TreeReader::class, fn () => new TreeReader(
            baseUrl: config()->string('workbench.langfuse.base_url'),
            publicKey: config()->string('workbench.langfuse.public_key'),
            secretKey: config()->string('workbench.langfuse.secret_key'),
        ));
    }

    /**
     * Apply the config of the scenario a web request runs, before Refract builds its transport at boot.
     *
     * Read from the server variables: the request is not bound yet when the providers register.
     */
    protected function configureScenarioRequest(): void
    {
        $uri = $_SERVER['REQUEST_URI'] ?? null;

        if ($this->app->runningInConsole() || ! is_string($uri)) {
            return;
        }

        if (preg_match('#^/scenario/([A-Za-z0-9]+)/?(\?.*)?$#', $uri, $matches) === 1) {
            config((new Scenarios)->config(strtoupper($matches[1])));
        }
    }
}
