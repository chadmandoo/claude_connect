<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Pipeline\PipelineContext;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Tests for PipelineContext.
 *
 * Covers: construction with default and explicit parameters, writable bag property
 * for inter-stage data passing, and readonly enforcement on task and userId.
 */
class PipelineContextTest extends TestCase
{
    public function testConstructionWithDefaults(): void
    {
        $task = ['id' => 'task-1', 'prompt' => 'do something'];
        $userId = 'user-42';

        $context = new PipelineContext($task, $userId);

        $this->assertSame($task, $context->task);
        $this->assertSame('user-42', $context->userId);
        $this->assertSame([], $context->templateConfig);
        $this->assertSame('', $context->conversationId);
        $this->assertSame('', $context->conversationType);
        $this->assertSame([], $context->bag);
    }

    public function testConstructionWithAllParams(): void
    {
        $task = ['id' => 'task-2', 'prompt' => 'build feature'];
        $userId = 'user-99';
        $templateConfig = ['model' => 'claude', 'timeout' => 30];
        $conversationId = 'conv-abc';
        $conversationType = 'task';

        $context = new PipelineContext($task, $userId, $templateConfig, $conversationId, $conversationType);

        $this->assertSame($task, $context->task);
        $this->assertSame('user-99', $context->userId);
        $this->assertSame($templateConfig, $context->templateConfig);
        $this->assertSame('conv-abc', $context->conversationId);
        $this->assertSame('task', $context->conversationType);
        $this->assertSame([], $context->bag);
    }

    public function testBagPropertyIsWritable(): void
    {
        $context = new PipelineContext(['id' => 'task-3'], 'user-1');

        $this->assertSame([], $context->bag);

        $context->bag['result_posted'] = true;
        $context->bag['count'] = 42;

        $this->assertTrue($context->bag['result_posted']);
        $this->assertSame(42, $context->bag['count']);
        $this->assertCount(2, $context->bag);
    }

    public function testTaskAndUserIdAreReadonly(): void
    {
        $context = new PipelineContext(['id' => 'task-4'], 'user-5');

        $reflection = new ReflectionProperty(PipelineContext::class, 'task');
        $this->assertTrue($reflection->isReadOnly());

        $reflection = new ReflectionProperty(PipelineContext::class, 'userId');
        $this->assertTrue($reflection->isReadOnly());
    }
}
