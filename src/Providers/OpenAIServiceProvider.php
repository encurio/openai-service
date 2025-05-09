<?php

namespace Encurio\OpenAIService\Providers;

use Illuminate\Support\ServiceProvider;
use Encurio\OpenAIService\Services\OpenAIService;

class OpenAIServiceProvider extends ServiceProvider
{
    /**
     * Register the service in the container and merge package config.
     */
    public function register(): void
    {
        // Damit config('openai.*') immer funktioniert, auch ohne Publish
        $this->mergeConfigFrom(
            __DIR__ . '/../../config/openai.php',
            'openai'
        );

        // Fassade bindet den Service unter dem Key „openai“
        $this->app->singleton('openai', fn($app) => new OpenAIService());
    }

    /**
     * Bootstrapping: hier wird das Publishing-Tag registriert.
     */
    public function boot(): void
    {
        // Ermöglicht: php artisan vendor:publish --tag=openai-config
        $this->publishes([
            __DIR__ . '/../../config/openai.php' => config_path('openai.php'),
        ], 'openai-config');
    }
}
