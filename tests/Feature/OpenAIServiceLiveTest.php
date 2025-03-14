<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;
use OpenAI;

class OpenAIServiceLiveTest extends TestCase
{
    public function testOpenAICompletionResponse(): void
    {
        // Retrieve the Completions API key
        $apiKey = env('OPENAI_API_KEY_COMPLETIONS');

        // If API key is not set, skip the test
        if (empty($apiKey)) {
            $this->markTestSkipped('No OpenAI API key found. Skipping test.');
        }

        // Call OpenAI API with a simple prompt
        $response = OpenAI::requestOpenAI(
            [['role' => 'user', 'content' => 'Tell me an interesting fact about AI.']],
            useAssistant: false,
            apiKey: $apiKey
        );

        // Ensure the response contains valid data
        $this->assertArrayHasKey('choices', $response, 'Response does not contain choices.');
        $this->assertNotEmpty($response['choices'][0]['message']['content'], 'Response content is empty.');

        // Log the output for debugging (optional)
        dump($response['choices'][0]['message']['content']);
    }

    public function testOpenAIAssistantResponse(): void
    {
        // Retrieve the Assistants API key
        $apiKey = env('OPENAI_API_KEY_ASSISTANTS');

        // If API key is not set, skip the test
        if (empty($apiKey)) {
            $this->markTestSkipped('No OpenAI Assistants API key found. Skipping test.');
        }

        // Call OpenAI Assistant
        $response = OpenAI::requestOpenAI(
            [['role' => 'user', 'content' => 'Analyze this artwork.']],
            useAssistant: true,
            assistantId: 'asst_12345', // Replace with actual Assistant ID
            apiKey: $apiKey
        );

        // Ensure the response contains valid data
        $this->assertArrayHasKey('choices', $response, 'Response does not contain choices.');
        $this->assertNotEmpty($response['choices'][0]['message']['content'], 'Response content is empty.');

        // Log the output for debugging (optional)
        dump($response['choices'][0]['message']['content']);
    }
}
