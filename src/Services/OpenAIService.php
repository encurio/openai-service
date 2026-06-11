<?php

declare(strict_types=1);

namespace Encurio\OpenAIService\Services;

use Encurio\OpenAIService\Exceptions\OpenAIRequestException;
use Encurio\OpenAIService\Exceptions\OpenAIRunFailedException;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Universal OpenAI Service.
 *
 * The Responses API is the primary API for new implementations.
 * Chat Completions and Assistants v2 Threads/Runs remain available for backward compatibility.
 */
class OpenAIService
{
    /**
     * Header for legacy Assistants v2 thread calls.
     */
    private const ASSISTANTS_V2_HEADER = [
        'OpenAI-Beta' => 'assistants=v2',
    ];

    private string $apiKey;
    private string $keyCompletions;
    private string $keyAssistants;
    private int $retries;
    private int $timeout;

    private string $baseUrlResponses;
    private string $baseUrlConversations;
    private string $baseUrlCompletions;
    private string $baseUrlEmbeddings;
    private string $baseUrlModerations;
    private string $baseUrlImages;
    private string $baseUrlThreads;
    private string $baseUrlFiles;

    private ?string $projectApiKey = null;

    /**
     * @var array<string,mixed>|string
     */
    private $pollResponse = '';

    /**
     * @throws Exception if no API key is configured
     */
    public function __construct()
    {
        $this->apiKey = (string) config('openai.api_key', '');
        $this->keyCompletions = (string) config('openai.keys.completions', $this->apiKey);
        $this->keyAssistants = (string) config('openai.keys.assistants', $this->apiKey);

        if ($this->apiKey === '' && $this->keyCompletions === '' && $this->keyAssistants === '') {
            throw new Exception('OpenAI API key is not set in config/openai.php. Use OPENAI_API_KEY.');
        }

        $this->retries = (int) config('openai.retries', 3);
        $this->timeout = (int) config('openai.timeout', 60);

        $this->baseUrlResponses = (string) config(
            'openai.endpoints.responses',
            'https://api.openai.com/v1/responses'
        );
        $this->baseUrlConversations = (string) config(
            'openai.endpoints.conversations',
            'https://api.openai.com/v1/conversations'
        );
        $this->baseUrlCompletions = (string) config(
            'openai.endpoints.completions',
            'https://api.openai.com/v1/chat/completions'
        );
        $this->baseUrlEmbeddings = (string) config(
            'openai.endpoints.embeddings',
            'https://api.openai.com/v1/embeddings'
        );
        $this->baseUrlModerations = (string) config(
            'openai.endpoints.moderations',
            'https://api.openai.com/v1/moderations'
        );
        $this->baseUrlImages = (string) config(
            'openai.endpoints.images',
            'https://api.openai.com/v1/images/generations'
        );
        $this->baseUrlThreads = (string) config(
            'openai.endpoints.threads',
            'https://api.openai.com/v1/threads'
        );
        $this->baseUrlFiles = (string) config(
            'openai.endpoints.files',
            'https://api.openai.com/v1/files'
        );
    }

    public function setProjectApiKey(string $apiKey): void
    {
        $this->projectApiKey = $apiKey;
    }

    /**
     * @return array<string,mixed>|string
     */
    public function getPollResponse()
    {
        return $this->pollResponse;
    }

    /**
     * Create a response with the current OpenAI Responses API.
     *
     * @param array<string,mixed> $opts
     * @return array<string,mixed>
     * @throws OpenAIRequestException
     */
    public function response(array $opts): array
    {
        $payload = $opts;
        $payload['model'] = $payload['model'] ?? config('openai.defaults.model', 'gpt-4.1-mini');

        if (!isset($payload['input'])) {
            if (isset($payload['messages'])) {
                $payload['input'] = $payload['messages'];
                unset($payload['messages']);
            } else {
                throw new Exception('Missing "input" for response().');
            }
        }

        if (isset($payload['max_tokens']) && !isset($payload['max_output_tokens'])) {
            $payload['max_output_tokens'] = $payload['max_tokens'];
            unset($payload['max_tokens']);
        }

        $apiKey = $payload['api_key'] ?? null;
        $retries = isset($payload['retries']) && is_int($payload['retries']) ? $payload['retries'] : $this->retries;
        unset($payload['api_key'], $payload['retries']);

        return $this->createResponse($payload, is_string($apiKey) ? $apiKey : null, $retries);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     * @throws OpenAIRequestException
     */
    public function createResponse(array $payload, ?string $apiKey = null, ?int $retries = null): array
    {
        return $this->sendRequestStrict(
            $apiKey ?: $this->getDefaultKey(),
            $this->baseUrlResponses,
            $payload,
            $retries ?? $this->retries
        );
    }

    /**
     * @return array<string,mixed>
     * @throws OpenAIRequestException
     */
    public function getResponse(string $responseId, ?string $apiKey = null): array
    {
        return $this->sendGetRequestStrict(
            $apiKey ?: $this->getDefaultKey(),
            "{$this->baseUrlResponses}/{$responseId}"
        );
    }

    /**
     * @return array<string,mixed>
     * @throws OpenAIRequestException
     */
    public function retrieveResponse(string $responseId, ?string $apiKey = null): array
    {
        return $this->getResponse($responseId, $apiKey);
    }

    /**
     * @return array<string,mixed>
     * @throws OpenAIRequestException
     */
    public function cancelResponse(string $responseId, ?string $apiKey = null): array
    {
        return $this->sendRequestStrict(
            $apiKey ?: $this->getDefaultKey(),
            "{$this->baseUrlResponses}/{$responseId}/cancel",
            [],
            $this->retries
        );
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @throws OpenAIRequestException
     */
    public function createConversation(array $items = [], ?string $apiKey = null): string
    {
        $payload = [];

        if ($items !== []) {
            $payload['items'] = $items;
        }

        $resp = $this->sendRequestStrict(
            $apiKey ?: $this->getDefaultKey(),
            $this->baseUrlConversations,
            $payload,
            $this->retries
        );

        return $resp['id'] ?? throw new Exception('createConversation: no id returned');
    }

    /**
     * @param array<string,mixed> $item
     * @return array<string,mixed>
     * @throws OpenAIRequestException
     */
    public function addConversationItem(string $conversationId, array $item, ?string $apiKey = null): array
    {
        return $this->sendRequestStrict(
            $apiKey ?: $this->getDefaultKey(),
            "{$this->baseUrlConversations}/{$conversationId}/items",
            $item,
            $this->retries
        );
    }

    /**
     * @return array<string,mixed>
     * @throws OpenAIRequestException
     */
    public function listConversationItems(string $conversationId, int $limit = 100, ?string $apiKey = null): array
    {
        return $this->sendGetRequestStrict(
            $apiKey ?: $this->getDefaultKey(),
            "{$this->baseUrlConversations}/{$conversationId}/items?limit={$limit}"
        );
    }

    /**
     * Legacy Chat Completions shortcut.
     *
     * @param array<string,mixed> $opts
     * @return array<string,mixed>|null
     * @throws Exception on missing params/key
     */
    public function completion(array $opts): ?array
    {
        $defaults = [
            'model' => config('openai.defaults.model', 'gpt-4.1-mini'),
            'temperature' => config('openai.defaults.temperature', 0.7),
            'max_tokens' => config('openai.defaults.max_tokens', 1000),
            'top_p' => config('openai.defaults.top_p', 1.0),
            'response_format' => null,
            'api_key' => $this->getCompletionKey(),
            'retries' => $this->retries,
        ];
        $cfg = array_merge($defaults, $opts);

        if (empty($cfg['messages']) || !is_array($cfg['messages'])) {
            throw new Exception('Missing or invalid "messages" for completion().');
        }

        $params = [
            'type' => 'completion',
            'messages' => $cfg['messages'],
            'model' => $cfg['model'],
            'temperature' => $cfg['temperature'],
            'max_tokens' => $cfg['max_tokens'],
            'top_p' => $cfg['top_p'],
            'api_key' => $cfg['api_key'],
            'retries' => $cfg['retries'],
        ];

        if (!empty($cfg['response_format'])) {
            $params['response_format'] = $cfg['response_format'];
        }

        return $this->requestOpenAI($params);
    }

    /**
     * Generate images via OpenAI Images API.
     *
     * GPT image models do not default to response_format=url. The response format is only sent when explicitly provided.
     *
     * @param array<string,mixed> $opts
     * @return array<string,mixed>|null
     * @throws Exception
     */
    public function image(array $opts): ?array
    {
        $cfg = array_merge([
            'model' => config('openai.defaults.image_model', 'gpt-image-1'),
            'prompt' => '',
            'n' => 1,
            'size' => '1024x1024',
            'response_format' => null,
            'output_format' => null,
            'quality' => null,
            'background' => null,
            'api_key' => null,
            'retries' => null,
        ], $opts);

        if (!is_string($cfg['prompt']) || $cfg['prompt'] === '') {
            throw new Exception('Missing or invalid "prompt" for image().');
        }

        $payload = [
            'type' => 'images',
            'model' => $cfg['model'],
            'prompt' => $cfg['prompt'],
            'n' => $cfg['n'],
            'size' => $cfg['size'],
            'api_key' => $cfg['api_key'],
            'retries' => $cfg['retries'],
        ];

        foreach (['response_format', 'output_format', 'quality', 'background'] as $optionalField) {
            if ($cfg[$optionalField] !== null) {
                $payload[$optionalField] = $cfg[$optionalField];
            }
        }

        return $this->requestOpenAI($payload);
    }

    /**
     * Core generic requester for legacy non-thread types.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>|null
     * @throws Exception
     */
    public function requestOpenAI(array $params): ?array
    {
        $type = $params['type'] ?? 'completion';
        $apiKey = $params['api_key']
            ?? $this->projectApiKey
            ?? match ($type) {
                'response' => $this->getDefaultKey(),
                'completion', 'images' => $this->getCompletionKey(),
                'embedding', 'moderation' => $this->getAssistantKey(),
                default => throw new Exception("Unknown request type \"$type\"."),
            };

        if (empty($apiKey)) {
            throw new Exception("Missing API key for type \"$type\".");
        }

        $retries = is_int($params['retries'] ?? null) ? $params['retries'] : $this->retries;

        unset($params['type'], $params['api_key'], $params['retries']);

        $url = match ($type) {
            'response' => $this->baseUrlResponses,
            'completion' => $this->baseUrlCompletions,
            'embedding' => $this->baseUrlEmbeddings,
            'moderation' => $this->baseUrlModerations,
            'images' => $this->baseUrlImages,
            default => throw new Exception("Unknown type \"$type\"."),
        };

        return $this->sendRequest((string) $apiKey, $url, $params, $retries);
    }

    /**
     * @return \Illuminate\Config\Repository|\Illuminate\Foundation\Application|mixed|object|string|null
     */
    private function _getAssistantKey()
    {
        return $this->getAssistantKey();
    }

    /**
     * @deprecated Use response() or createConversation() instead. Assistants v2 Threads/Runs are legacy compatibility methods.
     */
    public function createThread(): string
    {
        $resp = Http::withToken($this->_getAssistantKey())
            ->withHeaders(self::ASSISTANTS_V2_HEADER)
            ->post($this->baseUrlThreads, (object) [])
            ->throw()
            ->json();

        return $resp['id'] ?? throw new Exception('createThread: no id returned');
    }

    /**
     * @param array<int,array{role:string,content:string|array}> $messages
     * @deprecated Use createConversation() and addConversationItem() instead.
     */
    public function appendMessageToThread(string $threadId, array $messages): void
    {
        foreach ($messages as $msg) {
            $payload = [
                'role' => $msg['role'],
                'content' => $this->formatContent($msg['content']),
            ];

            Http::withToken($this->_getAssistantKey())
                ->withHeaders(self::ASSISTANTS_V2_HEADER)
                ->post("{$this->baseUrlThreads}/{$threadId}/messages", $payload)
                ->throw();
        }
    }

    /**
     * @param string|array<int,array<string,mixed>> $content
     * @return string|array<int,array<string,mixed>>
     */
    private function formatContent(string|array $content): string|array
    {
        if (is_array($content) && isset($content[0]['type'])) {
            return $content;
        }

        return [
            [
                'type' => 'text',
                'text' => (string) $content,
            ],
        ];
    }

    public function uploadFile(string $filePath, string $purpose = 'vision'): string
    {
        if (!file_exists($filePath)) {
            throw new Exception("File not found: {$filePath}");
        }

        $resp = Http::withToken($this->_getAssistantKey())
            ->attach('file', file_get_contents($filePath), basename($filePath))
            ->post($this->baseUrlFiles, [
                'purpose' => $purpose,
            ])
            ->throw()
            ->json();

        return $resp['id'] ?? throw new Exception('uploadFile: no id returned');
    }

    /**
     * @deprecated Use response() instead.
     */
    public function startRun(
        string $threadId,
        string $assistantId,
        string $model,
        array $tools = [],
        ?array $responseFormat = null
    ): string {
        $payload = [
            'assistant_id' => $assistantId,
            'model' => $model,
            'tools' => $tools,
            'tool_choice' => 'auto',
        ];

        if ($responseFormat) {
            $payload['response_format'] = $responseFormat;
        }

        $resp = Http::withToken($this->_getAssistantKey())
            ->withHeaders(self::ASSISTANTS_V2_HEADER)
            ->post("{$this->baseUrlThreads}/{$threadId}/runs", $payload)
            ->throw()
            ->json();

        return $resp['id'] ?? throw new Exception('startRun: no run id');
    }

    /**
     * @param array<string,callable> $toolHandlers
     * @return array<string,mixed>
     * @deprecated Use response() tool calling instead.
     */
    public function pollAndSubmitToolCalls(string $threadId, string $runId, array $toolHandlers = []): array
    {
        do {
            sleep(2);

            $resp = Http::withToken($this->_getAssistantKey())
                ->withHeaders(self::ASSISTANTS_V2_HEADER)
                ->get("{$this->baseUrlThreads}/{$threadId}/runs/{$runId}")
                ->throw()
                ->json();

            $status = $resp['status'] ?? 'unknown';

            if ($status === 'requires_action') {
                $toolCalls = $resp['required_action']['submit_tool_outputs']['tool_calls'] ?? [];
                $toolOutputs = [];

                foreach ($toolCalls as $call) {
                    $name = $call['function']['name'];
                    $args = json_decode($call['function']['arguments'], true);

                    Cache::put('art_recognition_status', [
                        'status' => "Executing tool: $name",
                        'tool' => $name,
                        'args' => $args,
                        'tool_call_id' => $call['id'],
                    ], now()->addMinutes(10));

                    if (isset($toolHandlers[$name]) && is_callable($toolHandlers[$name])) {
                        $output = call_user_func($toolHandlers[$name], $args);
                        $toolOutputs[] = [
                            'tool_call_id' => $call['id'],
                            'output' => json_encode($output),
                        ];
                    }
                }

                Http::withToken($this->_getAssistantKey())
                    ->withHeaders(self::ASSISTANTS_V2_HEADER)
                    ->post("{$this->baseUrlThreads}/{$threadId}/runs/{$runId}/submit_tool_outputs", [
                        'tool_outputs' => $toolOutputs,
                    ])
                    ->throw();
            }

            if (in_array($status, ['cancelled', 'expired', 'failed'], true)) {
                throw new \RuntimeException("Run failed or was cancelled: $status");
            }
        } while (($status ?? '') !== 'completed');

        return $resp;
    }

    /**
     * @param array<string,callable> $toolHandlers
     * @return array<string,mixed>
     * @throws OpenAIRunFailedException|\RuntimeException
     * @deprecated Use response() instead.
     */
    public function pollUntilRunComplete(
        string $threadId,
        string $runId,
        array $toolHandlers = [],
        ?string $assistantId = null,
        ?string $model = null,
        array $tools = [],
        ?array $responseFormat = null
    ): array {
        $this->pollResponse = [];
        $attempts = 0;
        $maxAttempts = 100;
        $retryAttempts = 0;
        $maxRetryAttempts = 5;

        do {
            sleep(3);

            $resp = Http::withToken($this->_getAssistantKey())
                ->withHeaders(self::ASSISTANTS_V2_HEADER)
                ->get("{$this->baseUrlThreads}/{$threadId}/runs/{$runId}")
                ->throw()
                ->json();

            $status = $resp['status'] ?? 'unknown';

            if ($status === 'requires_action' && !empty($toolHandlers)) {
                return $this->pollAndSubmitToolCalls($threadId, $runId, $toolHandlers);
            }

            Cache::put('art_recognition_status', [
                'status' => "AI Assistant Status: $status - ($attempts)",
                'run_id' => $runId,
            ], now()->addMinutes(10));

            if (in_array($status, ['failed', 'cancelled', 'expired'], true)) {
                $lastError = $resp['last_error'] ?? null;
                $errorCode = $lastError['code'] ?? null;

                $context = [
                    'run_id' => $runId,
                    'thread_id' => $threadId,
                    'assistant_id' => $resp['assistant_id'] ?? $assistantId,
                    'model' => $resp['model'] ?? $model,
                    'last_error' => $lastError,
                    'incomplete_details' => $resp['incomplete_details'] ?? null,
                    'response_payload' => $resp,
                ];

                if (in_array($errorCode, ['server_error', 'rate_limit_exceeded'], true) && $retryAttempts < $maxRetryAttempts) {
                    $retryAttempts++;
                    $backoff = match ($retryAttempts) {
                        1 => 2,
                        2 => 5,
                        3 => 10,
                        4 => 20,
                        5 => 30,
                        default => 0,
                    };

                    Log::warning("OpenAI run transient error ($errorCode), restarting run in {$backoff}s... (Attempt $retryAttempts/$maxRetryAttempts)", $context);
                    sleep($backoff);

                    $aId = $resp['assistant_id'] ?? $assistantId;
                    $m = $resp['model'] ?? $model;

                    if ($aId && $m) {
                        try {
                            $runId = $this->startRun($threadId, $aId, $m, $tools, $responseFormat);
                            $attempts = 0;
                            continue;
                        } catch (Exception $e) {
                            Log::error('Failed to restart OpenAI run: ' . $e->getMessage(), $context);
                        }
                    }
                }

                Log::error('OpenAI run failed', $context);
                throw new OpenAIRunFailedException("OpenAI run failed with status: $status", $context);
            }

            $this->pollResponse = $resp;
            $attempts++;
        } while ($status !== 'completed' && $attempts < $maxAttempts);

        if ($status !== 'completed') {
            throw new \RuntimeException('Polling timeout: Run did not complete in time.');
        }

        return $this->pollResponse;
    }

    /**
     * @return array<int,array<string,mixed>>
     * @deprecated Use listConversationItems() instead.
     */
    public function getThreadMessages(string $threadId, int $limit = 100): array
    {
        $resp = Http::withToken($this->_getAssistantKey())
            ->withHeaders(self::ASSISTANTS_V2_HEADER)
            ->get("{$this->baseUrlThreads}/{$threadId}/messages", [
                'limit' => $limit,
                'order' => 'asc',
            ])
            ->throw()
            ->json();

        return $resp['data'] ?? [];
    }

    /**
     * @param array<int,array{role:string,content:string}> $messages
     * @param array<int,mixed> $tools
     * @param array<string,callable> $toolHandlers
     * @return array<int,array<string,mixed>>
     * @deprecated Use response() instead.
     */
    public function runThread(
        string $assistantId,
        array $messages,
        string $model,
        array $tools = [],
        array $toolHandlers = []
    ): array {
        $tId = $this->createThread();
        $this->appendMessageToThread($tId, $messages);
        $rId = $this->startRun($tId, $assistantId, $model, $tools);
        $this->pollUntilRunComplete($tId, $rId, $toolHandlers, $assistantId, $model, $tools);

        return $this->getThreadMessages($tId);
    }

    private function getDefaultKey(): string
    {
        return $this->projectApiKey ?: $this->apiKey ?: $this->keyCompletions ?: $this->keyAssistants;
    }

    private function getCompletionKey(): string
    {
        return $this->projectApiKey ?: $this->keyCompletions ?: $this->apiKey;
    }

    private function getAssistantKey(): string
    {
        return $this->projectApiKey ?: $this->keyAssistants ?: $this->apiKey;
    }

    /**
     * Low-level HTTP POST with retry and legacy nullable failure behavior.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    private function sendRequest(string $apiKey, string $url, array $payload, int $retries): ?array
    {
        try {
            return $this->sendRequestStrict($apiKey, $url, $payload, $retries);
        } catch (OpenAIRequestException $e) {
            Log::error('OpenAI API Error', $e->context());
            return null;
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     * @throws OpenAIRequestException
     */
    private function sendRequestStrict(string $apiKey, string $url, array $payload, int $retries): array
    {
        $lastException = null;

        for ($i = 0; $i < $retries; $i++) {
            try {
                $resp = Http::timeout($this->timeout)
                    ->withHeaders([
                        'Authorization' => "Bearer {$apiKey}",
                        'Content-Type' => 'application/json',
                    ])
                    ->post($url, $payload);

                if ($resp->successful()) {
                    return $resp->json() ?? [];
                }

                $context = [
                    'status' => $resp->status(),
                    'body' => $resp->body(),
                    'url' => $url,
                    'request_id' => $resp->header('x-request-id'),
                    'attempt' => $i + 1,
                ];

                Log::warning('OpenAI API request failed', $context);
                $lastException = new OpenAIRequestException('OpenAI API request failed.', $context, $resp->status());
            } catch (Exception $e) {
                $context = [
                    'url' => $url,
                    'message' => $e->getMessage(),
                    'attempt' => $i + 1,
                ];

                Log::warning('OpenAI HTTP exception', $context);
                $lastException = new OpenAIRequestException('OpenAI HTTP exception.', $context, 0, $e);
            }

            sleep(1);
        }

        throw $lastException ?? new OpenAIRequestException('OpenAI request failed without response.', ['url' => $url]);
    }

    /**
     * @return array<string,mixed>
     * @throws OpenAIRequestException
     */
    private function sendGetRequestStrict(string $apiKey, string $url): array
    {
        try {
            $resp = Http::timeout($this->timeout)
                ->withHeaders([
                    'Authorization' => "Bearer {$apiKey}",
                    'Content-Type' => 'application/json',
                ])
                ->get($url);

            if ($resp->successful()) {
                return $resp->json() ?? [];
            }

            throw new OpenAIRequestException('OpenAI API GET request failed.', [
                'status' => $resp->status(),
                'body' => $resp->body(),
                'url' => $url,
                'request_id' => $resp->header('x-request-id'),
            ], $resp->status());
        } catch (OpenAIRequestException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new OpenAIRequestException('OpenAI HTTP GET exception.', [
                'url' => $url,
                'message' => $e->getMessage(),
            ], 0, $e);
        }
    }
}
