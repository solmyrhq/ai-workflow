<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\AiService;
use AiWorkflow\Enums\FinishReason;
use AiWorkflow\Enums\GuardrailDirection;
use AiWorkflow\Exceptions\GuardrailViolationException;
use AiWorkflow\Exceptions\ProviderRequestException;
use AiWorkflow\Exceptions\RateLimitedException;
use AiWorkflow\Exceptions\StructuredDataRequestException;
use AiWorkflow\Exceptions\StructuredValidationException;
use AiWorkflow\Exceptions\UnexpectedFinishReasonException;
use AiWorkflow\Messages\UserMessage;
use AiWorkflow\Middleware\AiWorkflowContext;
use AiWorkflow\Middleware\AiWorkflowMiddleware;
use AiWorkflow\Middleware\InputGuardrail;
use AiWorkflow\Middleware\OutputGuardrail;
use AiWorkflow\PromptData;
use AiWorkflow\Responses\Usage;
use AiWorkflow\SchemaBuilder;
use AiWorkflow\StructuredDataResult;
use AiWorkflow\Testing\OpenRouterFake;
use AiWorkflow\Tests\Fixtures\Data\AddressData;
use AiWorkflow\Tests\Fixtures\Data\DefaultedData;
use AiWorkflow\Tests\Fixtures\Data\NestedDefaultsData;
use AiWorkflow\Tests\Fixtures\Data\NullableNoDefaultData;
use AiWorkflow\Tests\Fixtures\Data\PersonData;
use AiWorkflow\Tests\Fixtures\Data\SentimentData;
use AiWorkflow\Tests\Fixtures\Data\TeamData;
use AiWorkflow\Tests\Fixtures\Data\TypedSentimentData;
use AiWorkflow\Tests\Fixtures\Data\ValidatedConfidenceData;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use RuntimeException;
use Spatie\LaravelData\Data;

class SchemaBuilderTest extends TestCase
{
    // --- SchemaBuilder ---

    public function test_generates_schema_from_simple_data_class(): void
    {
        $schema = SchemaBuilder::fromDataClass(SentimentData::class);
        $array = $schema->toArray();

        $this->assertSame('SentimentData', $schema->name());
        $this->assertSame('SentimentData', $array['description']);
        $this->assertSame(['sentiment', 'confidence'], array_keys($array['properties']));
        $this->assertSame(['sentiment', 'confidence'], $array['required']);
        $this->assertFalse($array['additionalProperties']);
    }

    public function test_maps_string_to_string_schema(): void
    {
        $this->assertSame('string', $this->property(SentimentData::class, 'sentiment')['type']);
    }

    public function test_maps_float_to_number_schema(): void
    {
        $this->assertSame('number', $this->property(SentimentData::class, 'confidence')['type']);
    }

    public function test_maps_int_to_number_schema(): void
    {
        $this->assertSame('number', $this->property(PersonData::class, 'age')['type']);
    }

    public function test_description_attribute_used_for_descriptions(): void
    {
        $this->assertSame('The detected sentiment: positive, negative, or neutral', $this->property(SentimentData::class, 'sentiment')['description']);
        $this->assertSame('Confidence score from 0.0 to 1.0', $this->property(SentimentData::class, 'confidence')['description']);
    }

    public function test_falls_back_to_property_name_without_description(): void
    {
        $this->assertSame('street', $this->property(AddressData::class, 'street')['description']);
        $this->assertSame('city', $this->property(AddressData::class, 'city')['description']);
    }

    public function test_maps_backed_enum_to_enum_schema(): void
    {
        $type = $this->property(TypedSentimentData::class, 'type');

        $this->assertSame(['positive', 'negative', 'neutral'], $type['enum']);
        $this->assertSame('string', $type['type']);
    }

    public function test_nullable_property_is_still_required(): void
    {
        $this->assertSame(['type', 'reason'], SchemaBuilder::fromDataClass(TypedSentimentData::class)->toArray()['required']);
    }

    public function test_required_lists_every_property(): void
    {
        foreach ([SentimentData::class, PersonData::class, TeamData::class, TypedSentimentData::class, DefaultedData::class] as $dataClass) {
            $array = SchemaBuilder::fromDataClass($dataClass)->toArray();

            $this->assertSame(array_keys($array['properties']), $array['required'], "required for {$dataClass} must list every key in properties (OpenAI strict mode)");
        }
    }

    public function test_defaulted_property_is_nullable_so_the_model_can_decline(): void
    {
        $array = SchemaBuilder::fromDataClass(DefaultedData::class)->toArray();

        // 'language' is a non-nullable PHP string, widened so its default has a null to fall back on.
        $this->assertSame(['string', 'null'], $array['properties']['language']['type']);
        $this->assertSame(['string', 'null'], $array['properties']['tone']['type']);
        $this->assertSame('string', $array['properties']['sentiment']['type']);
    }

    public function test_strip_nulls_restores_defaults(): void
    {
        $stripped = SchemaBuilder::stripNullsForDefaultedProperties(DefaultedData::class, [
            'sentiment' => 'positive',
            'reason' => null,
            'language' => null,
            'tone' => null,
        ]);

        $this->assertSame(['sentiment' => 'positive'], $stripped);

        $data = DefaultedData::from($stripped);
        $this->assertSame('en', $data->language);
        $this->assertSame('neutral', $data->tone);
        $this->assertNull($data->reason);
    }

    public function test_strip_nulls_keeps_values_the_model_supplied(): void
    {
        $stripped = SchemaBuilder::stripNullsForDefaultedProperties(DefaultedData::class, [
            'sentiment' => 'negative',
            'reason' => 'late delivery',
            'language' => 'fr',
            'tone' => 'sharp',
        ]);

        $data = DefaultedData::from($stripped);
        $this->assertSame('fr', $data->language);
        $this->assertSame('sharp', $data->tone);
        $this->assertSame('late delivery', $data->reason);
    }

    public function test_strip_nulls_recurses_into_nested_data(): void
    {
        $stripped = SchemaBuilder::stripNullsForDefaultedProperties(NestedDefaultsData::class, [
            'name' => 'Jane',
            'address' => ['street' => 'Main St', 'country' => null],
            'previous' => [['street' => 'Old Rd', 'country' => null]],
        ]);

        $data = NestedDefaultsData::from($stripped);

        $this->assertSame('UK', $data->address->country);
        $this->assertSame('UK', $data->previous[0]->country);
    }

    public function test_strip_nulls_keeps_nested_values_the_model_supplied(): void
    {
        $stripped = SchemaBuilder::stripNullsForDefaultedProperties(NestedDefaultsData::class, [
            'name' => 'Jane',
            'address' => ['street' => 'Main St', 'country' => 'FR'],
            'previous' => [['street' => 'Old Rd', 'country' => 'DE']],
        ]);

        $data = NestedDefaultsData::from($stripped);

        $this->assertSame('FR', $data->address->country);
        $this->assertSame('DE', $data->previous[0]->country);
    }

    public function test_send_structured_data_applies_nested_defaults(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::structured([
            'name' => 'Jane',
            'address' => ['street' => 'Main St', 'country' => null],
            'previous' => [['street' => 'Old Rd', 'country' => null]],
        ]));

        $result = app(AiService::class)->sendStructuredData(
            collect([new UserMessage('Where does Jane live?')]),
            new PromptData(id: 'test', model: 'openrouter:test-model', prompt: 'Extract the address.'),
            NestedDefaultsData::class,
        );

        $this->assertSame('UK', $result->data->address->country);
        $this->assertSame('UK', $result->data->previous[0]->country);
    }

    public function test_strip_nulls_leaves_a_nullable_property_without_a_default(): void
    {
        // No default, so the null is the model's answer rather than a decline.
        $stripped = SchemaBuilder::stripNullsForDefaultedProperties(NullableNoDefaultData::class, [
            'category_id' => null,
            'confidence' => 0,
        ]);

        $this->assertArrayHasKey('category_id', $stripped);
        $this->assertNull(NullableNoDefaultData::from($stripped)->category_id);
    }

    public function test_nested_object_required_lists_every_property(): void
    {
        $address = $this->property(PersonData::class, 'address');

        $this->assertSame(array_keys($address['properties']), $address['required']);
    }

    public function test_nullable_property_allows_null(): void
    {
        $this->assertSame(['string', 'null'], $this->property(TypedSentimentData::class, 'reason')['type']);
    }

    public function test_nested_data_class_maps_to_object_schema(): void
    {
        $address = $this->property(PersonData::class, 'address');

        $this->assertSame('object', $address['type']);
        $this->assertSame('Home address', $address['description']);
        $this->assertSame(['street', 'city'], $address['required']);
    }

    // --- ArrayItemType ---

    public function test_array_without_attribute_defaults_to_string_items(): void
    {
        $tags = $this->property(TeamData::class, 'tags');

        $this->assertSame('array', $tags['type']);
        $this->assertSame(['description' => 'Array item', 'type' => 'string'], $tags['items']);
    }

    public function test_array_with_scalar_item_type(): void
    {
        $this->assertSame(['description' => 'Array item', 'type' => 'number'], $this->property(TeamData::class, 'scores')['items']);
    }

    public function test_array_with_data_class_item_type(): void
    {
        $items = $this->property(TeamData::class, 'members')['items'];

        $this->assertSame('object', $items['type']);
        $this->assertSame('Array item', $items['description']);
        $this->assertSame(['name', 'age', 'address'], array_keys($items['properties']));
    }

    // --- sendStructuredData ---

    public function test_send_structured_data_returns_validated_instance(): void
    {
        OpenRouterFake::respondWith(OpenRouterFake::structured(['sentiment' => 'positive', 'confidence' => 0.95]));

        $result = $this->sendSentiment();

        $this->assertInstanceOf(StructuredDataResult::class, $result);
        $this->assertInstanceOf(SentimentData::class, $result->data);
        $this->assertSame('positive', $result->data->sentiment);
        $this->assertSame(0.95, $result->data->confidence);
    }

    public function test_send_structured_data_retries_on_validation_failure(): void
    {
        OpenRouterFake::respondWith(
            // First attempt: missing required field
            OpenRouterFake::structured(['confidence' => 0.5]),
            // Second attempt: valid
            OpenRouterFake::structured(['sentiment' => 'negative', 'confidence' => 0.8]),
        );

        $result = $this->sendSentiment();

        $this->assertSame('negative', $result->data->sentiment);

        $messages = OpenRouterFake::sentBodies()[1]['messages'];
        $this->assertIsArray($messages);
        $this->assertSame(['role' => 'assistant', 'content' => '{"confidence":0.5}'], $messages[2]);
        $this->assertIsArray($messages[3]);
        $this->assertStringStartsWith('The previous response failed validation:', $messages[3]['content']);
    }

    public function test_send_structured_data_enforces_validation_rules(): void
    {
        OpenRouterFake::respondWith(
            // Out of the range the Data class declares.
            OpenRouterFake::structured(['confidence' => 150]),
            OpenRouterFake::structured(['confidence' => 85]),
        );

        $result = app(AiService::class)->sendStructuredData(
            collect([new UserMessage('How confident are you?')]),
            new PromptData(id: 'test', model: 'openrouter:test-model', prompt: 'Answer.'),
            ValidatedConfidenceData::class,
        );

        // The first answer is rejected and fed back, so the retry is what lands.
        $this->assertSame(85, $result->data->confidence);
    }

    public function test_send_structured_data_throws_when_validation_never_passes(): void
    {
        OpenRouterFake::respondWith(
            OpenRouterFake::structured(['confidence' => 150]),
            OpenRouterFake::structured(['confidence' => 200]),
        );

        $this->expectException(StructuredValidationException::class);

        app(AiService::class)->sendStructuredData(
            collect([new UserMessage('How confident are you?')]),
            new PromptData(id: 'test', model: 'openrouter:test-model', prompt: 'Answer.'),
            ValidatedConfidenceData::class,
            maxAttempts: 2,
        );
    }

    public function test_send_structured_data_throws_after_max_attempts(): void
    {
        OpenRouterFake::respondWith(
            OpenRouterFake::structured(['confidence' => 0.5]),
            OpenRouterFake::structured(['confidence' => 0.6]),
        );

        $this->expectException(StructuredValidationException::class);

        $this->sendSentiment(maxAttempts: 2);
    }

    public function test_structured_validation_exception_tracks_attempts(): void
    {
        OpenRouterFake::respondWith(
            OpenRouterFake::structured(['confidence' => 0.5]),
            OpenRouterFake::structured(['confidence' => 0.5]),
            OpenRouterFake::structured(['confidence' => 0.5]),
        );

        try {
            $this->sendSentiment(maxAttempts: 3);
            $this->fail('Expected StructuredValidationException');
        } catch (StructuredValidationException $e) {
            $this->assertSame(3, $e->attempts);
            $this->assertNotNull($e->getPrevious());
        }
    }

    public function test_send_structured_data_result_includes_response_and_usage(): void
    {
        OpenRouterFake::respondWith(self::reply(['sentiment' => 'positive', 'confidence' => 0.95], 100, 50, thought: 30));

        $result = $this->sendSentiment();

        $this->assertEquals(new Usage(100, 50, thoughtTokens: 30), $result->usage);
        $this->assertEquals($result->response->usage, $result->usage);
        $this->assertSame(FinishReason::Stop, $result->response->finishReason);
    }

    public function test_send_structured_data_usage_adds_up_every_attempt(): void
    {
        OpenRouterFake::respondWith(
            self::reply(['confidence' => 0.5], 100, 50, cacheRead: 20, thought: 10),
            self::reply(['sentiment' => 'negative', 'confidence' => 0.8], 120, 60, thought: 5),
        );

        $result = $this->sendSentiment();

        $this->assertEquals(new Usage(220, 110, cacheReadTokens: 20, thoughtTokens: 15), $result->usage);
        $this->assertEquals(new Usage(120, 60, thoughtTokens: 5), $result->response->usage);
    }

    public function test_structured_validation_exception_carries_the_usage_of_every_attempt(): void
    {
        OpenRouterFake::respondWith(
            self::reply(['confidence' => 0.5], 100, 50, cacheWrite: 40),
            self::reply(['confidence' => 0.6], 120, 60),
        );

        try {
            $this->sendSentiment(maxAttempts: 2);
            $this->fail('Expected StructuredValidationException');
        } catch (StructuredValidationException $e) {
            $this->assertEquals(new Usage(220, 110, cacheWriteTokens: 40), $e->usage());
        }
    }

    public function test_send_structured_data_wraps_a_failed_request_with_the_usage_of_earlier_attempts(): void
    {
        OpenRouterFake::respondWith(
            self::reply(['confidence' => 0.5], 100, 50),
            OpenRouterFake::error(400, 'Invalid request'),
        );

        try {
            $this->sendSentiment();
            $this->fail('Expected StructuredDataRequestException');
        } catch (StructuredDataRequestException $e) {
            $this->assertSame(2, $e->attempts);
            $this->assertEquals(new Usage(100, 50), $e->usage());
            $this->assertInstanceOf(ProviderRequestException::class, $e->getPrevious());
            $this->assertSame($e->getPrevious()->getMessage(), $e->getMessage());
            $this->assertSame(400, $e->getCode());
        }
    }

    public function test_send_structured_data_counts_a_response_rejected_for_its_finish_reason(): void
    {
        config()->set('ai-workflow.retry.times', 1);
        OpenRouterFake::respondWith(
            self::reply(['confidence' => 0.5], 100, 50),
            OpenRouterFake::completion('{"sentiment":"negative","confidence":0.8}', 'error', ['prompt_tokens' => 120, 'completion_tokens' => 60]),
        );

        try {
            $this->sendSentiment();
            $this->fail('Expected StructuredDataRequestException');
        } catch (StructuredDataRequestException $e) {
            $this->assertEquals(new Usage(220, 110), $e->usage());
            $this->assertInstanceOf(UnexpectedFinishReasonException::class, $e->getPrevious());
        }
    }

    public function test_a_retried_finish_reason_counts_every_rejected_response(): void
    {
        OpenRouterFake::respondWith(
            OpenRouterFake::completion('', 'error', ['prompt_tokens' => 120, 'completion_tokens' => 60]),
            OpenRouterFake::completion('', 'error', ['prompt_tokens' => 120, 'completion_tokens' => 60]),
            OpenRouterFake::completion('', 'error', ['prompt_tokens' => 120, 'completion_tokens' => 60]),
        );

        try {
            $this->sendSentiment();
            $this->fail('Expected StructuredDataRequestException');
        } catch (StructuredDataRequestException $e) {
            $this->assertEquals(new Usage(360, 180), $e->usage());
        }
    }

    public function test_structured_data_request_exception_keeps_the_original_code(): void
    {
        $service = app(AiService::class);
        $service->addMiddleware(new class implements AiWorkflowMiddleware
        {
            public function handle(AiWorkflowContext $context, Closure $next): AiWorkflowContext
            {
                throw new RuntimeException('Too many requests', 429);
            }
        });

        try {
            $this->sendSentiment($service);
            $this->fail('Expected StructuredDataRequestException');
        } catch (StructuredDataRequestException $e) {
            $this->assertSame(429, $e->getCode());
        }
    }

    public function test_send_structured_data_wraps_a_failed_first_request_too(): void
    {
        $service = app(AiService::class);
        $service->addMiddleware(new class implements AiWorkflowMiddleware
        {
            public function handle(AiWorkflowContext $context, Closure $next): AiWorkflowContext
            {
                throw new RateLimitedException('Slow down', 'openrouter', 429);
            }
        });

        try {
            $this->sendSentiment($service);
            $this->fail('Expected StructuredDataRequestException');
        } catch (StructuredDataRequestException $e) {
            $this->assertSame(1, $e->attempts);
            $this->assertEquals(new Usage, $e->usage());
            $this->assertInstanceOf(RateLimitedException::class, $e->getPrevious());
        }
    }

    public function test_send_structured_data_does_not_wrap_a_guardrail_violation(): void
    {
        $service = app(AiService::class);
        $service->addMiddleware(new class extends InputGuardrail
        {
            protected function validate(AiWorkflowContext $context): void
            {
                throw new GuardrailViolationException('test-guardrail', GuardrailDirection::Input, 'Blocked by test');
            }
        });

        $this->expectException(GuardrailViolationException::class);

        $this->sendSentiment($service);
    }

    public function test_send_structured_data_adds_earlier_usage_to_a_guardrail_violation(): void
    {
        OpenRouterFake::respondWith(
            self::reply(['confidence' => 0.5], 100, 50),
            self::reply(['confidence' => 0.6], 120, 60),
        );

        $guardrail = new class extends InputGuardrail
        {
            public ?GuardrailViolationException $thrown = null;

            private int $calls = 0;

            protected function validate(AiWorkflowContext $context): void
            {
                if (++$this->calls === 3) {
                    throw $this->thrown = new GuardrailViolationException('test-guardrail', GuardrailDirection::Input, 'Blocked by test');
                }
            }
        };

        $service = app(AiService::class);
        $service->addMiddleware($guardrail);

        try {
            $this->sendSentiment($service);
            $this->fail('Expected GuardrailViolationException');
        } catch (GuardrailViolationException $e) {
            $this->assertSame($guardrail->thrown, $e);
            $this->assertEquals(new Usage(220, 110), $e->usage());
        }
    }

    public function test_send_structured_data_counts_the_response_an_output_guardrail_rejected(): void
    {
        OpenRouterFake::respondWith(
            self::reply(['confidence' => 0.5], 100, 50),
            self::reply(['sentiment' => 'negative', 'confidence' => 0.8], 120, 60),
        );

        $service = app(AiService::class);
        $service->addMiddleware(new class extends OutputGuardrail
        {
            private int $calls = 0;

            protected function validate(AiWorkflowContext $context): void
            {
                if (++$this->calls === 2) {
                    throw new GuardrailViolationException('content-filter', GuardrailDirection::Output, 'Rejected');
                }
            }
        });

        try {
            $this->sendSentiment($service);
            $this->fail('Expected GuardrailViolationException');
        } catch (GuardrailViolationException $e) {
            $this->assertEquals(new Usage(220, 110), $e->usage());
        }
    }

    private function sendSentiment(?AiService $service = null, int $maxAttempts = 3): StructuredDataResult
    {
        return ($service ?? app(AiService::class))->sendStructuredData(
            collect([new UserMessage('Analyze')]),
            new PromptData(id: 'test', model: 'openrouter:test-model', prompt: 'Analyze.'),
            SentimentData::class,
            $maxAttempts,
        );
    }

    /**
     * @param  class-string<Data>  $dataClass
     * @return array<string, mixed>
     */
    private function property(string $dataClass, string $name): array
    {
        $properties = SchemaBuilder::fromDataClass($dataClass)->toArray()['properties'];
        $this->assertIsArray($properties);
        $this->assertIsArray($properties[$name]);

        return $properties[$name];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function reply(array $data, int $input, int $output, ?int $cacheRead = null, ?int $cacheWrite = null, ?int $thought = null): PromiseInterface
    {
        return OpenRouterFake::structured($data, OpenRouterFake::tokens($input, $output, $cacheRead, $cacheWrite, $thought));
    }
}
