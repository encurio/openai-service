<?php

declare(strict_types=1);

namespace Encurio\OpenAIService\Exceptions;

use Exception;

class OpenAIRunFailedException extends Exception
{
    /**
     * @var array
     */
    protected array $context;

    /**
     * @param string $message
     * @param array $context
     */
    public function __construct(string $message, array $context = [])
    {
        parent::__construct($message);
        $this->context = $context;
    }

    /**
     * @return array
     */
    public function context(): array
    {
        return $this->context;
    }
}
