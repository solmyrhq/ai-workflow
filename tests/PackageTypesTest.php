<?php

declare(strict_types=1);

namespace AiWorkflow\Tests;

use AiWorkflow\Exceptions\HttpErrorDetails;
use AiWorkflow\Exceptions\ProviderRequestException;
use AiWorkflow\Messages\Attachment;
use AiWorkflow\Messages\AttachmentKind;
use AiWorkflow\Responses\Usage;
use AiWorkflow\Schema\ResponseSchema;
use AiWorkflow\Schema\SchemaPropertyOrder;
use AiWorkflow\Tools\Tool;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Exceptions;
use InvalidArgumentException;
use RuntimeException;

class PackageTypesTest extends TestCase
{
    public function test_usage_adds_optional_counts_only_when_reported(): void
    {
        $total = (new Usage(100, 50, cacheReadTokens: 20))
            ->add(new Usage(10, 5, thoughtTokens: 3))
            ->add(new Usage(1, 1));

        $this->assertEquals(new Usage(111, 56, cacheReadTokens: 20, cacheWriteTokens: null, thoughtTokens: 3), $total);
    }

    public function test_response_schema_rejects_a_root_that_is_not_an_object(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ResponseSchema('answer', ['type' => 'string']);
    }

    public function test_property_order_is_restored_from_required_at_every_level(): void
    {
        $sorted = [
            'type' => 'object',
            'properties' => [
                'answer' => ['type' => 'string'],
                'details' => [
                    'type' => 'object',
                    'properties' => ['b' => ['type' => 'string'], 'a' => ['type' => 'string']],
                    'required' => ['a', 'b'],
                ],
                'reasoning' => ['type' => 'string'],
            ],
            'required' => ['reasoning', 'details', 'answer'],
        ];

        $restored = SchemaPropertyOrder::restore($sorted);

        $this->assertIsArray($restored['properties']);
        $this->assertSame(['reasoning', 'details', 'answer'], array_keys($restored['properties']));
        $this->assertIsArray($restored['properties']['details']);
        $this->assertIsArray($restored['properties']['details']['properties']);
        $this->assertSame(['a', 'b'], array_keys($restored['properties']['details']['properties']));
    }

    public function test_property_order_is_left_alone_when_required_does_not_name_every_property(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => ['b' => ['type' => 'string'], 'a' => ['type' => 'string']],
            'required' => ['a'],
        ];

        $this->assertSame($schema, SchemaPropertyOrder::restore($schema));
    }

    public function test_tool_returns_strings_as_they_are_and_encodes_other_results(): void
    {
        $echo = new Tool('echo', 'Echo', [], fn (array $arguments): mixed => $arguments['value'] ?? null);

        $this->assertSame('hi', $echo->handle(['value' => 'hi']));
        $this->assertSame('{"a":1}', $echo->handle(['value' => ['a' => 1]]));
    }

    public function test_tool_reports_a_failure_and_returns_it_to_the_model(): void
    {
        Exceptions::fake();

        $tool = new Tool('broken', 'Always fails', [], function (): never {
            throw new RuntimeException('Database is down');
        });

        $this->assertSame(
            'Tool execution error: Database is down. This error occurred during tool execution, not due to invalid parameters.',
            $tool->handle([]),
        );
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_attachment_from_path_reads_and_encodes_the_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'att');
        $this->assertIsString($path);
        file_put_contents($path, 'hello');

        $attachment = Attachment::fromPath(AttachmentKind::Document, $path, title: 'Greeting');

        $this->assertSame(base64_encode('hello'), $attachment->base64);
        $this->assertSame('text/plain', $attachment->mimeType);
        $this->assertSame('Greeting', $attachment->title);

        unlink($path);
    }

    public function test_http_error_details_take_each_field_from_the_outermost_carrier(): void
    {
        $response = new Response(new Psr7Response(403, [], '{"error":"forbidden"}'));
        $error = new ProviderRequestException('Forbidden', 'openrouter', 403, null, new RequestException($response));

        $this->assertSame(['status' => 403, 'body' => '{"error":"forbidden"}'], HttpErrorDetails::extract($error));
    }
}
