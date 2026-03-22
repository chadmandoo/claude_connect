<?php

declare(strict_types=1);

namespace Tests\Unit\Pipeline;

use App\Pipeline\PipelineContext;
use App\Pipeline\PipelineStage;
use App\Pipeline\PostTaskPipeline;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PostTaskPipelineTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private PostTaskPipeline $pipeline;
    private LoggerInterface|Mockery\MockInterface $logger;

    protected function setUp(): void
    {
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('debug')->byDefault();
        $this->logger->shouldReceive('info')->byDefault();
        $this->logger->shouldReceive('warning')->byDefault();
        $this->logger->shouldReceive('error')->byDefault();

        $this->pipeline = new PostTaskPipeline($this->logger);
    }

    public function testRegisterStageAndRun(): void
    {
        $stage = Mockery::mock(PipelineStage::class);
        $stage->shouldReceive('name')->andReturn('test_stage');
        $stage->shouldReceive('shouldRun')->once()->andReturn(true);
        $stage->shouldReceive('execute')->once()->andReturn(['success' => true]);

        $this->pipeline->registerStage($stage);

        $context = new PipelineContext(
            task: ['id' => 'task-1', 'prompt' => 'hello'],
            userId: 'user-1',
        );

        $this->pipeline->run($context);
    }

    public function testRunWithNoStagesDoesNotThrow(): void
    {
        $context = new PipelineContext(
            task: ['id' => 'task-1', 'prompt' => 'hello'],
            userId: 'user-1',
        );

        $this->pipeline->run($context);

        // If we reach here without exception, the test passes
        $this->assertTrue(true);
    }

    public function testRunWithStageNamesFilterRunsOnlyMatchingStages(): void
    {
        $stageA = Mockery::mock(PipelineStage::class);
        $stageA->shouldReceive('name')->andReturn('stage_a');
        $stageA->shouldReceive('shouldRun')->once()->andReturn(true);
        $stageA->shouldReceive('execute')->once()->andReturn(['success' => true]);

        $stageB = Mockery::mock(PipelineStage::class);
        $stageB->shouldReceive('name')->andReturn('stage_b');
        $stageB->shouldReceive('shouldRun')->never();
        $stageB->shouldReceive('execute')->never();

        $this->pipeline->registerStage($stageA);
        $this->pipeline->registerStage($stageB);

        $context = new PipelineContext(
            task: ['id' => 'task-1', 'prompt' => 'hello'],
            userId: 'user-1',
        );

        $this->pipeline->run($context, ['stage_a']);
    }

    public function testRunCatchesStageExceptionAndContinues(): void
    {
        $failingStage = Mockery::mock(PipelineStage::class);
        $failingStage->shouldReceive('name')->andReturn('failing_stage');
        $failingStage->shouldReceive('shouldRun')->once()->andReturn(true);
        $failingStage->shouldReceive('execute')->once()->andThrow(new \RuntimeException('Stage exploded'));

        $successStage = Mockery::mock(PipelineStage::class);
        $successStage->shouldReceive('name')->andReturn('success_stage');
        $successStage->shouldReceive('shouldRun')->once()->andReturn(true);
        $successStage->shouldReceive('execute')->once()->andReturn(['success' => true]);

        $this->logger->shouldReceive('error')
            ->once()
            ->withArgs(fn(string $msg) => str_contains($msg, 'failing_stage') && str_contains($msg, 'Stage exploded'));

        $this->pipeline->registerStage($failingStage);
        $this->pipeline->registerStage($successStage);

        $context = new PipelineContext(
            task: ['id' => 'task-1', 'prompt' => 'hello'],
            userId: 'user-1',
        );

        $this->pipeline->run($context);
    }

    public function testRunSkipsStageWhenShouldRunReturnsFalse(): void
    {
        $stage = Mockery::mock(PipelineStage::class);
        $stage->shouldReceive('name')->andReturn('skipped_stage');
        $stage->shouldReceive('shouldRun')->once()->andReturn(false);
        $stage->shouldReceive('execute')->never();

        $this->logger->shouldReceive('debug')
            ->once()
            ->withArgs(fn(string $msg) => str_contains($msg, 'skipped_stage') && str_contains($msg, 'shouldRun=false'));

        $this->pipeline->registerStage($stage);

        $context = new PipelineContext(
            task: ['id' => 'task-1', 'prompt' => 'hello'],
            userId: 'user-1',
        );

        $this->pipeline->run($context);
    }

    public function testRunLogsWarningWhenStageReturnsFailure(): void
    {
        $stage = Mockery::mock(PipelineStage::class);
        $stage->shouldReceive('name')->andReturn('warn_stage');
        $stage->shouldReceive('shouldRun')->once()->andReturn(true);
        $stage->shouldReceive('execute')->once()->andReturn(['success' => false, 'error' => 'something went wrong']);

        $this->logger->shouldReceive('warning')
            ->once()
            ->withArgs(fn(string $msg) => str_contains($msg, 'warn_stage') && str_contains($msg, 'something went wrong'));

        $this->pipeline->registerStage($stage);

        $context = new PipelineContext(
            task: ['id' => 'task-1', 'prompt' => 'hello'],
            userId: 'user-1',
        );

        $this->pipeline->run($context);
    }

    public function testRunWithUnknownStageNameSkipsGracefully(): void
    {
        $stage = Mockery::mock(PipelineStage::class);
        $stage->shouldReceive('name')->andReturn('existing_stage');
        $stage->shouldReceive('shouldRun')->never();
        $stage->shouldReceive('execute')->never();

        $this->pipeline->registerStage($stage);

        $context = new PipelineContext(
            task: ['id' => 'task-1', 'prompt' => 'hello'],
            userId: 'user-1',
        );

        // Requesting a stage name that was not registered should not throw
        $this->pipeline->run($context, ['nonexistent_stage']);

        $this->assertTrue(true);
    }

    public function testRegisterStageOverwritesDuplicate(): void
    {
        $stageV1 = Mockery::mock(PipelineStage::class);
        $stageV1->shouldReceive('name')->andReturn('my_stage');
        $stageV1->shouldReceive('shouldRun')->never();
        $stageV1->shouldReceive('execute')->never();

        $stageV2 = Mockery::mock(PipelineStage::class);
        $stageV2->shouldReceive('name')->andReturn('my_stage');
        $stageV2->shouldReceive('shouldRun')->once()->andReturn(true);
        $stageV2->shouldReceive('execute')->once()->andReturn(['success' => true]);

        $this->pipeline->registerStage($stageV1);
        $this->pipeline->registerStage($stageV2);

        $context = new PipelineContext(
            task: ['id' => 'task-1', 'prompt' => 'hello'],
            userId: 'user-1',
        );

        $this->pipeline->run($context);
    }
}
