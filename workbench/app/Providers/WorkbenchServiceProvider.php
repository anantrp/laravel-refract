<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Workbench\App\Langfuse\TreeReader;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/workbench.php', 'workbench');

        config(['ai.conversations.generate_title' => false]);

        $this->app->singleton(TreeReader::class, fn () => new TreeReader(
            baseUrl: config()->string('workbench.langfuse.base_url'),
            publicKey: config()->string('workbench.langfuse.public_key'),
            secretKey: config()->string('workbench.langfuse.secret_key'),
        ));
    }
}
