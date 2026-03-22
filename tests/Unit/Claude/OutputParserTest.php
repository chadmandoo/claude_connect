<?php

declare(strict_types=1);

namespace Tests\Unit\Claude;

use App\Claude\OutputParser;
use PHPUnit\Framework\TestCase;

class OutputParserTest extends TestCase
{
    private OutputParser $parser;

    protected function setUp(): void
    {
        $this->parser = new OutputParser();
    }

    public function testEmptyOutputReturnsFailure(): void
    {
        $result = $this->parser->parse('', 0);

        $this->assertFalse($result->success);
        $this->assertSame('Empty output from Claude CLI', $result->error);
    }

    public function testWhitespaceOnlyOutputReturnsFailure(): void
    {
        $result = $this->parser->parse('   ', 0);

        $this->assertFalse($result->success);
        $this->assertSame('Empty output from Claude CLI', $result->error);
    }

    public function testNonJsonWithExitZeroReturnsSuccess(): void
    {
        $result = $this->parser->parse('plain text output', 0);

        $this->assertTrue($result->success);
        $this->assertSame('plain text output', $result->result);
        $this->assertNull($result->sessionId);
    }

    public function testNonJsonWithNonZeroExitReturnsFailure(): void
    {
        $result = $this->parser->parse('error text', 1);

        $this->assertFalse($result->success);
        $this->assertSame('error text', $result->error);
    }

    public function testJsonWithResultField(): void
    {
        $json = json_encode(['result' => 'Hello from Claude', 'session_id' => 'abc-123']);
        $result = $this->parser->parse($json, 0);

        $this->assertTrue($result->success);
        $this->assertSame('Hello from Claude', $result->result);
        $this->assertSame('abc-123', $result->sessionId);
    }

    public function testJsonWithContentStringField(): void
    {
        $json = json_encode(['content' => 'Content output']);
        $result = $this->parser->parse($json, 0);

        $this->assertTrue($result->success);
        $this->assertSame('Content output', $result->result);
    }

    public function testJsonWithMessageField(): void
    {
        $json = json_encode(['message' => 'Message output']);
        $result = $this->parser->parse($json, 0);

        $this->assertTrue($result->success);
        $this->assertSame('Message output', $result->result);
    }

    public function testJsonWithContentBlocks(): void
    {
        $json = json_encode([
            'content' => [
                ['text' => 'First block'],
                ['text' => 'Second block'],
            ],
        ]);
        $result = $this->parser->parse($json, 0);

        $this->assertTrue($result->success);
        $this->assertSame("First block\nSecond block", $result->result);
    }

    public function testJsonWithContentBlocksNoText(): void
    {
        $json = json_encode([
            'content' => [
                ['type' => 'image', 'url' => 'http://example.com'],
            ],
        ]);
        $result = $this->parser->parse($json, 0);

        $this->assertTrue($result->success);
        // Falls through to JSON pretty-print fallback
        $this->assertStringContainsString('"content"', $result->result);
    }

    public function testJsonWithSessionIdCamelCase(): void
    {
        $json = json_encode(['result' => 'ok', 'sessionId' => 'camel-case-id']);
        $result = $this->parser->parse($json, 0);

        $this->assertSame('camel-case-id', $result->sessionId);
    }

    public function testJsonWithSessionIdSnakeCase(): void
    {
        $json = json_encode(['result' => 'ok', 'session_id' => 'snake-case-id']);
        $result = $this->parser->parse($json, 0);

        $this->assertSame('snake-case-id', $result->sessionId);
    }

    public function testJsonWithErrorStringField(): void
    {
        $json = json_encode(['error' => 'rate_limited']);
        $result = $this->parser->parse($json, 0);

        $this->assertFalse($result->success);
        $this->assertSame('rate_limited', $result->error);
    }

    public function testJsonWithErrorObjectField(): void
    {
        $json = json_encode(['error' => ['code' => 429, 'message' => 'Too many requests']]);
        $result = $this->parser->parse($json, 0);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('429', $result->error);
        $this->assertStringContainsString('Too many requests', $result->error);
    }

    public function testJsonWithNonZeroExitAndNoResult(): void
    {
        // extractResult falls through to json_encode which is non-empty,
        // so this actually returns success with the pretty-printed JSON
        $json = json_encode(['some_field' => 'value']);
        $result = $this->parser->parse($json, 1);

        $this->assertTrue($result->success);
        $this->assertStringContainsString('some_field', $result->result);
    }

    public function testJsonWithNonZeroExitAndMessage(): void
    {
        $json = json_encode(['message' => 'Custom error message']);
        $result = $this->parser->parse($json, 1);

        // message is extracted as result, and since it's non-empty, exit code doesn't cause failure
        $this->assertTrue($result->success);
        $this->assertSame('Custom error message', $result->result);
    }

    public function testJsonFallbackToPrettyPrint(): void
    {
        $data = ['key1' => 'value1', 'key2' => 42];
        $json = json_encode($data);
        $result = $this->parser->parse($json, 0);

        $this->assertTrue($result->success);
        $this->assertSame(json_encode($data, JSON_PRETTY_PRINT), $result->result);
    }

    public function testJsonWithCostAndTokens(): void
    {
        $json = json_encode([
            'result' => 'ok',
            'cost_usd' => 0.05,
            'input_tokens' => 100,
            'output_tokens' => 200,
        ]);
        $result = $this->parser->parse($json, 0);

        $this->assertTrue($result->success);
        $this->assertSame(0.05, $result->costUsd);
        $this->assertSame(100, $result->inputTokens);
        $this->assertSame(200, $result->outputTokens);
    }

    public function testResultFieldPriorityOverContent(): void
    {
        $json = json_encode([
            'result' => 'from result',
            'content' => 'from content',
        ]);
        $result = $this->parser->parse($json, 0);

        $this->assertSame('from result', $result->result);
    }

    public function testContentFieldPriorityOverMessage(): void
    {
        $json = json_encode([
            'content' => 'from content',
            'message' => 'from message',
        ]);
        $result = $this->parser->parse($json, 0);

        $this->assertSame('from content', $result->result);
    }

    public function testErrorFieldTakesPriorityOverResult(): void
    {
        $json = json_encode([
            'result' => 'some result',
            'error' => 'but there is an error',
        ]);
        $result = $this->parser->parse($json, 0);

        $this->assertFalse($result->success);
        $this->assertSame('but there is an error', $result->error);
    }

    public function testNoSessionIdReturnsNull(): void
    {
        $json = json_encode(['result' => 'no session']);
        $result = $this->parser->parse($json, 0);

        $this->assertNull($result->sessionId);
    }
}
