<?php

declare(strict_types=1);

namespace Encurio\OpenAIService\Exceptions;

use Exception;

class OpenAIRequestException extends Exception
{
    /**
     * @param array<string,mixed> $context
     */
    public function __construct(
        string $message,
        protected array $context = [],
        int $code = 0,
        ?Exception $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @return array<string,mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function status(): ?int
    {
        return isset($this->context['status']) && is_int($this->context['status'])
            ? $this->context['status']
            : null;
    }

    public function responseBody(): ?string
    {
        return isset($this->context['body']) && is_string($this->context['body'])
            ? $this->context['body']
            : null;
    }

    public function requestId(): ?string
    {
        return isset($this->context['request_id']) && is_string($this->context['request_id'])
            ? $this->context['request_id']
            : null;
    }
}
