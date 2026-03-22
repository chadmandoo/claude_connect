<?php

declare(strict_types=1);

namespace Tests\Unit\Prompts;

use App\Prompts\PromptLoader;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tests\Helpers\ReflectionHelper;

class PromptLoaderTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use ReflectionHelper;

    private PromptLoader $loader;
    private LoggerInterface|Mockery\MockInterface $logger;

    protected function setUp(): void
    {
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', dirname(__DIR__, 3));
        }

        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->loader = new PromptLoader();
        $this->setProperty($this->loader, 'logger', $this->logger);
    }

    public function testLoadWithExistingFile(): void
    {
        $this->logger->shouldReceive('warning')->never();

        $content = $this->loader->load('general');

        $this->assertIsString($content);
        $this->assertNotEmpty($content);
    }

    public function testLoadWithHelperFile(): void
    {
        $this->logger->shouldReceive('warning')->never();

        $content = $this->loader->load('helper');

        $this->assertIsString($content);
        $this->assertNotEmpty($content);
    }

    public function testLoadWithNonExistentFile(): void
    {
        $this->logger->shouldReceive('warning')
            ->once()
            ->with(Mockery::pattern('/Prompt file not found.*nonexistent_xyz/'));

        $content = $this->loader->load('nonexistent_xyz');

        $this->assertSame('', $content);
    }

    public function testLoadExtractionPromptWithExistingType(): void
    {
        $this->logger->shouldReceive('warning')->never();

        $content = $this->loader->loadExtractionPrompt('task');

        $this->assertIsString($content);
        $this->assertNotEmpty($content);
    }

    public function testLoadExtractionPromptFallsBackToTask(): void
    {
        // First call for extraction/nonexistent_type will warn
        $this->logger->shouldReceive('warning')
            ->once()
            ->with(Mockery::pattern('/Prompt file not found.*nonexistent_type/'));

        $content = $this->loader->loadExtractionPrompt('nonexistent_type');

        // Should fall back to extraction/task which exists
        $this->assertIsString($content);
        $this->assertNotEmpty($content);
    }

    public function testBuildGenericPrompt(): void
    {
        $this->logger->shouldReceive('warning')->never();

        $content = $this->loader->buildGenericPrompt();

        $this->assertIsString($content);
        $this->assertNotEmpty($content);
    }

    public function testBuildGenericPromptWithMemoryContext(): void
    {
        $this->logger->shouldReceive('warning')->never();

        $memoryContext = 'User prefers PHP 8.3';
        $content = $this->loader->buildGenericPrompt($memoryContext);

        $this->assertIsString($content);
        $this->assertNotEmpty($content);
        $this->assertStringContainsString($memoryContext, $content);
    }
}
