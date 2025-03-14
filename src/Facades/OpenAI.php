<?php

namespace Encurio\OpenAIService\Facades;

use Illuminate\Support\Facades\Facade;

class OpenAI extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'openai';
    }
}
