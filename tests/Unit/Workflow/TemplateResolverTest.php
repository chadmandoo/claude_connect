<?php

declare(strict_types=1);

namespace Tests\Unit\Workflow;

use App\Workflow\TemplateResolver;
use Hyperf\Contract\ConfigInterface;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Tests\Helpers\ReflectionHelper;

class TemplateResolverTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use ReflectionHelper;

    private ConfigInterface|Mockery\MockInterface $config;

    protected function setUp(): void
    {
        $this->config = Mockery::mock(ConfigInterface::class);
    }

    public function testResolveWithExplicitTemplateName(): void
    {
        $templates = [
            'quick' => [
                'label' => 'Quick Task',
                'max_turns' => 5,
                'max_budget_usd' => 0.50,
                'progress_interval' => 10,
                'pipeline_stages' => [],
                'keywords' => [],
            ],
            'standard' => [
                'label' => 'Standard Task',
                'max_turns' => 35,
                'max_budget_usd' => 5.00,
                'progress_interval' => 30,
                'pipeline_stages' => ['post_result'],
                'keywords' => [],
            ],
        ];

        $this->config->shouldReceive('get')
            ->with('mcp.workflow.templates', [])
            ->andReturn($templates);

        $this->config->shouldReceive('get')
            ->with('mcp.workflow.default_template', 'standard')
            ->andReturn('standard');

        $resolver = new TemplateResolver($this->config);
        $result = $resolver->resolve('quick', 'test prompt');

        $this->assertIsArray($result);
        $this->assertSame('quick', $result['name']);
        $this->assertSame(5, $result['max_turns']);
        $this->assertSame(0.50, $result['max_budget_usd']);
    }

    public function testResolveWithNullTemplateAutoDetects(): void
    {
        $templates = [
            'quick' => [
                'label' => 'Quick Task',
                'max_turns' => 5,
                'max_budget_usd' => 0.50,
                'progress_interval' => 10,
                'pipeline_stages' => [],
                'keywords' => ['quick question', 'short'],
            ],
            'standard' => [
                'label' => 'Standard Task',
                'max_turns' => 35,
                'max_budget_usd' => 5.00,
                'progress_interval' => 30,
                'pipeline_stages' => ['post_result'],
                'keywords' => ['build', 'implement', 'create feature'],
            ],
        ];

        $this->config->shouldReceive('get')
            ->with('mcp.workflow.templates', [])
            ->andReturn($templates);

        $this->config->shouldReceive('get')
            ->with('mcp.workflow.default_template', 'standard')
            ->andReturn('standard');

        $this->config->shouldReceive('get')
            ->with('mcp.workflow.auto_detect', true)
            ->andReturn(true);

        $resolver = new TemplateResolver($this->config);
        $result = $resolver->resolve(null, 'quick question about PHP');

        $this->assertIsArray($result);
        $this->assertSame('quick', $result['name']);
        $this->assertSame(5, $result['max_turns']);
    }

    public function testResolveWithNullTemplateFallsToDefault(): void
    {
        $templates = [
            'standard' => [
                'label' => 'Standard Task',
                'max_turns' => 25,
                'max_budget_usd' => 5.00,
                'progress_interval' => 30,
                'pipeline_stages' => ['post_result'],
                'keywords' => [],
            ],
        ];

        $this->config->shouldReceive('get')
            ->with('mcp.workflow.templates', [])
            ->andReturn($templates);

        $this->config->shouldReceive('get')
            ->with('mcp.workflow.auto_detect', true)
            ->andReturn(true);

        $this->config->shouldReceive('get')
            ->with('mcp.workflow.default_template', 'standard')
            ->andReturn('standard');

        $resolver = new TemplateResolver($this->config);
        $result = $resolver->resolve(null, 'some random prompt');

        $this->assertIsArray($result);
        $this->assertSame('standard', $result['name']);
        $this->assertSame(25, $result['max_turns']);
    }

    public function testResolveAbsoluteFallbackWhenConfigMissing(): void
    {
        $this->config->shouldReceive('get')
            ->with('mcp.workflow.templates', [])
            ->andReturn([]);

        $this->config->shouldReceive('get')
            ->with('mcp.workflow.auto_detect', true)
            ->andReturn(false);

        $this->config->shouldReceive('get')
            ->with('mcp.workflow.default_template', 'standard')
            ->andReturn('standard');

        $resolver = new TemplateResolver($this->config);
        $result = $resolver->resolve(null, 'test');

        $this->assertIsArray($result);
        $this->assertSame('standard', $result['name']);
        $this->assertSame(25, $result['max_turns']);
        $this->assertSame(5.00, $result['max_budget_usd']);
    }

    public function testListTemplates(): void
    {
        $templates = [
            'quick' => ['label' => 'Quick', 'max_turns' => 5],
            'standard' => ['label' => 'Standard', 'max_turns' => 25],
            'deep' => ['label' => 'Deep Work', 'max_turns' => 100],
        ];

        $this->config->shouldReceive('get')
            ->with('mcp.workflow.templates', [])
            ->andReturn($templates);

        $resolver = new TemplateResolver($this->config);
        $result = $resolver->listTemplates();

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertArrayHasKey('quick', $result);
        $this->assertArrayHasKey('standard', $result);
        $this->assertArrayHasKey('deep', $result);
    }
}
