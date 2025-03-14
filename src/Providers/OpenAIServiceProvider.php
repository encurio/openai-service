<?php

namespace Encurio\OpenAIService\Providers;

use Illuminate\Support\ServiceProvider;
use Encurio\OpenAIService\Services\OpenAIService;

class OpenAIServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton('openai', function ($app) {
            return new OpenAIService();
        });
    }

    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/openai.php' => config_path('openai.php'),
        ], 'openai-config');
    }
}
