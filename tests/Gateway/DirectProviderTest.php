<?php

declare(strict_types=1);

namespace AiWorkflow\Tests\Gateway;

use AiWorkflow\Enums\FinishReason;
use AiWorkflow\Gateway\LlmClient;
use AiWorkflow\Gateway\StructuredCall;
use AiWorkflow\Gateway\TextCall;
use AiWorkflow\Messages\UserMessage;
use AiWorkflow\Responses\ResponseMeta;
use AiWorkflow\Responses\Usage;
use AiWorkflow\Schema\ResponseSchema;
use AiWorkflow\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Override;

/**
 * Calls to providers that laravel-integrations does not manage, which go
 * through laravel/ai's own gateways.
 */
class DirectProviderTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.providers.anthropic.key', 'anthropic-key');
    }

    public function test_an_anthropic_text_call_uses_laravel_ai_token_counts(): void
    {
        $contents = file_get_contents(__DIR__.'/../Fixtures/Http/anthropic/message.json');
        $this->assertIsString($contents);
        Http::fake(['api.anthropic.com/*' => Http::response($contents, 200, ['Content-Type' => 'application/json'])]);

        $response = $this->client()->text(
            new TextCall('anthropic', 'claude-sonnet-5', 'Be brief.', [new UserMessage('Say good morning in Portuguese.')], maxTokens: 100),
            null,
        );

        $this->assertSame('Bom dia!', $response->text);
        $this->assertSame(FinishReason::Stop, $response->finishReason);
        // laravel/ai counts cached tokens as part of the input tokens.
        $this->assertEquals(new Usage(138, 6, cacheReadTokens: 120, cacheWriteTokens: 0), $response->usage);
        $this->assertEquals(new ResponseMeta('msg_01Rk7mWq2Tz9sLpVb3nYc4dE', 'claude-sonnet-5'), $response->meta);

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('x-api-key', 'anthropic-key')
            && $request['system'] === 'Be brief.'
            && $request['max_tokens'] === 100);
    }

    public function test_an_anthropic_structured_call_converts_the_schema_and_decodes_the_reply(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'id' => 'msg_02',
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-5',
            'content' => [['type' => 'text', 'text' => '{"city":"Lisbon","celsius":21}']],
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 40, 'output_tokens' => 12],
        ])]);

        $schema = new ResponseSchema('forecast', [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string'], 'celsius' => ['type' => 'number']],
            'required' => ['city', 'celsius'],
        ]);

        $response = $this->client()->structured(
            new StructuredCall('anthropic', 'claude-sonnet-5', 'Extract the forecast.', [new UserMessage('21 degrees in Lisbon.')], $schema, 500),
            null,
        );

        $this->assertSame(['city' => 'Lisbon', 'celsius' => 21], $response->structured);

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();
            $format = $body['output_config']['format'] ?? $body['tools'][0]['input_schema'] ?? null;

            return is_array($format) && str_contains((string) json_encode($format), '"celsius"');
        });
    }

    private function client(): LlmClient
    {
        return $this->app->make(LlmClient::class);
    }
}
