<?php

declare(strict_types=1);

namespace Tests\Unit\Claude;

use App\Claude\ParsedOutput;
use PHPUnit\Framework\TestCase;

class ParsedOutputTest extends TestCase
{
    public function testFromSuccessBasic(): void
    {
        $output = ParsedOutput::fromSuccess('Hello world');

        $this->assertTrue($output->success);
        $this->assertSame('Hello world', $output->result);
        $this->assertNull($output->sessionId);
        $this->assertNull($output->error);
        $this->assertSame(0.0, $output->costUsd);
        $this->assertSame(0, $output->inputTokens);
        $this->assertSame(0, $output->outputTokens);
        $this->assertSame([], $output->raw);
    }

    public function testFromSuccessWithSessionId(): void
    {
        $output = ParsedOutput::fromSuccess('result', 'sess-123');

        $this->assertSame('sess-123', $output->sessionId);
    }

    public function testFromSuccessWithRawData(): void
    {
        $raw = [
            'cost_usd' => 0.05,
            'input_tokens' => 100,
            'output_tokens' => 200,
        ];
        $output = ParsedOutput::fromSuccess('result', null, $raw);

        $this->assertSame(0.05, $output->costUsd);
        $this->assertSame(100, $output->inputTokens);
        $this->assertSame(200, $output->outputTokens);
        $this->assertSame($raw, $output->raw);
    }

    public function testFromSuccessWithPartialRawData(): void
    {
        $raw = ['cost_usd' => 0.01];
        $output = ParsedOutput::fromSuccess('result', null, $raw);

        $this->assertSame(0.01, $output->costUsd);
        $this->assertSame(0, $output->inputTokens);
        $this->assertSame(0, $output->outputTokens);
    }

    public function testFromFailureBasic(): void
    {
        $output = ParsedOutput::fromFailure('Something went wrong');

        $this->assertFalse($output->success);
        $this->assertSame('', $output->result);
        $this->assertNull($output->sessionId);
        $this->assertSame('Something went wrong', $output->error);
        $this->assertSame(0.0, $output->costUsd);
        $this->assertSame(0, $output->inputTokens);
        $this->assertSame(0, $output->outputTokens);
        $this->assertSame([], $output->raw);
    }

    public function testFromFailureWithRawData(): void
    {
        $raw = ['error' => 'rate_limited'];
        $output = ParsedOutput::fromFailure('Rate limited', $raw);

        $this->assertSame($raw, $output->raw);
    }

    public function testPropertiesAreReadonly(): void
    {
        $output = ParsedOutput::fromSuccess('test');

        $reflection = new \ReflectionClass($output);
        $properties = $reflection->getProperties();

        foreach ($properties as $property) {
            $this->assertTrue($property->isReadOnly(), "Property {$property->getName()} should be readonly");
        }
    }

    public function testConstructorDirectly(): void
    {
        $output = new ParsedOutput(
            success: true,
            result: 'direct',
            sessionId: 'sid',
            error: null,
            costUsd: 1.5,
            inputTokens: 50,
            outputTokens: 75,
            images: [],
            raw: ['key' => 'value'],
        );

        $this->assertTrue($output->success);
        $this->assertSame('direct', $output->result);
        $this->assertSame('sid', $output->sessionId);
        $this->assertNull($output->error);
        $this->assertSame(1.5, $output->costUsd);
        $this->assertSame(50, $output->inputTokens);
        $this->assertSame(75, $output->outputTokens);
        $this->assertSame([], $output->images);
        $this->assertSame(['key' => 'value'], $output->raw);
    }
}
