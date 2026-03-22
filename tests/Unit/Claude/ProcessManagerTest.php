<?php

declare(strict_types=1);

namespace Tests\Unit\Claude;

use App\Claude\OutputParser;
use App\Claude\ProcessManager;
use App\StateMachine\TaskManager;
use Hyperf\Contract\ConfigInterface;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tests\Helpers\ReflectionHelper;

class ProcessManagerTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use ReflectionHelper;

    private ProcessManager $manager;
    private TaskManager|Mockery\MockInterface $taskManager;
    private OutputParser|Mockery\MockInterface $outputParser;
    private ConfigInterface|Mockery\MockInterface $config;
    private LoggerInterface|Mockery\MockInterface $logger;

    protected function setUp(): void
    {
        $this->taskManager = Mockery::mock(TaskManager::class);
        $this->outputParser = Mockery::mock(OutputParser::class);
        $this->config = Mockery::mock(ConfigInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('info', 'debug', 'warning', 'error')->byDefault();

        $this->manager = new ProcessManager();
        $this->setProperty($this->manager, 'taskManager', $this->taskManager);
        $this->setProperty($this->manager, 'outputParser', $this->outputParser);
        $this->setProperty($this->manager, 'config', $this->config);
        $this->setProperty($this->manager, 'logger', $this->logger);
    }

    private function setupDefaultConfig(): void
    {
        $this->config->shouldReceive('get')
            ->with('mcp.claude.cli_path', Mockery::any())
            ->andReturn('/usr/bin/claude');
        $this->config->shouldReceive('get')
            ->with('mcp.claude.max_turns', Mockery::any())
            ->andReturn(25);
        $this->config->shouldReceive('get')
            ->with('mcp.claude.max_budget_usd', Mockery::any())
            ->andReturn(5.00);
        $this->config->shouldReceive('get')
            ->with('mcp.claude.default_model', Mockery::any())
            ->andReturn('');
    }

    // buildCommand tests via reflection

    public function testBuildCommandBasic(): void
    {
        $this->setupDefaultConfig();

        $task = [
            'prompt' => 'Hello world',
            'session_id' => '',
            'options' => '{}',
        ];

        $cmd = $this->callMethod($this->manager, 'buildCommand', [$task]);

        $this->assertSame('/usr/bin/claude', $cmd[0]);
        $this->assertSame('-p', $cmd[1]);
        $this->assertSame('Hello world', $cmd[2]);
        $this->assertSame('--output-format', $cmd[3]);
        $this->assertSame('json', $cmd[4]);
        $this->assertSame('--dangerously-skip-permissions', $cmd[5]);
        $this->assertSame('--max-turns', $cmd[6]);
        $this->assertSame('25', $cmd[7]);
        $this->assertContains('--max-budget-usd', $cmd);
    }

    public function testBuildCommandWithModel(): void
    {
        $this->setupDefaultConfig();

        $task = [
            'prompt' => 'test',
            'session_id' => '',
            'options' => json_encode(['model' => 'claude-sonnet']),
        ];

        $cmd = $this->callMethod($this->manager, 'buildCommand', [$task]);

        $idx = array_search('--model', $cmd);
        $this->assertNotFalse($idx);
        $this->assertSame('claude-sonnet', $cmd[$idx + 1]);
    }

    public function testBuildCommandWithSessionIdAddsResume(): void
    {
        $this->setupDefaultConfig();

        $task = [
            'prompt' => 'follow up',
            'session_id' => 'sess-abc-123',
            'options' => '{}',
        ];

        $cmd = $this->callMethod($this->manager, 'buildCommand', [$task]);

        $idx = array_search('--resume', $cmd);
        $this->assertNotFalse($idx);
        $this->assertSame('sess-abc-123', $cmd[$idx + 1]);
    }

    public function testBuildCommandWithoutSessionIdNoResume(): void
    {
        $this->setupDefaultConfig();

        $task = [
            'prompt' => 'test',
            'session_id' => '',
            'options' => '{}',
        ];

        $cmd = $this->callMethod($this->manager, 'buildCommand', [$task]);

        $this->assertNotContains('--resume', $cmd);
    }

    public function testBuildCommandWithMcpConfig(): void
    {
        $this->setupDefaultConfig();

        $task = [
            'prompt' => 'test',
            'session_id' => '',
            'options' => json_encode(['mcp_config' => '/path/to/config.json']),
        ];

        $cmd = $this->callMethod($this->manager, 'buildCommand', [$task]);

        $idx = array_search('--mcp-config', $cmd);
        $this->assertNotFalse($idx);
        $this->assertSame('/path/to/config.json', $cmd[$idx + 1]);
    }

    public function testBuildCommandWithCustomMaxTurns(): void
    {
        $this->setupDefaultConfig();

        $task = [
            'prompt' => 'test',
            'session_id' => '',
            'options' => json_encode(['max_turns' => 10]),
        ];

        $cmd = $this->callMethod($this->manager, 'buildCommand', [$task]);

        $idx = array_search('--max-turns', $cmd);
        $this->assertSame('10', $cmd[$idx + 1]);
    }

    public function testBuildCommandWithZeroBudgetNoBudgetFlag(): void
    {
        $this->config->shouldReceive('get')
            ->with('mcp.claude.cli_path', Mockery::any())
            ->andReturn('/usr/bin/claude');
        $this->config->shouldReceive('get')
            ->with('mcp.claude.max_turns', Mockery::any())
            ->andReturn(25);
        $this->config->shouldReceive('get')
            ->with('mcp.claude.max_budget_usd', Mockery::any())
            ->andReturn(0.0);
        $this->config->shouldReceive('get')
            ->with('mcp.claude.default_model', Mockery::any())
            ->andReturn('');

        $task = [
            'prompt' => 'test',
            'session_id' => '',
            'options' => '{}',
        ];

        $cmd = $this->callMethod($this->manager, 'buildCommand', [$task]);

        $this->assertNotContains('--max-budget-usd', $cmd);
    }

    public function testBuildCommandWithDefaultModel(): void
    {
        $this->config->shouldReceive('get')
            ->with('mcp.claude.cli_path', Mockery::any())
            ->andReturn('/usr/bin/claude');
        $this->config->shouldReceive('get')
            ->with('mcp.claude.max_turns', Mockery::any())
            ->andReturn(25);
        $this->config->shouldReceive('get')
            ->with('mcp.claude.max_budget_usd', Mockery::any())
            ->andReturn(5.0);
        $this->config->shouldReceive('get')
            ->with('mcp.claude.default_model', Mockery::any())
            ->andReturn('claude-opus-4-6');

        $task = [
            'prompt' => 'test',
            'session_id' => '',
            'options' => '{}',
        ];

        $cmd = $this->callMethod($this->manager, 'buildCommand', [$task]);

        $idx = array_search('--model', $cmd);
        $this->assertNotFalse($idx);
        $this->assertSame('claude-opus-4-6', $cmd[$idx + 1]);
    }

    public function testBuildCommandOptionsOverrideDefaults(): void
    {
        $this->setupDefaultConfig();

        $task = [
            'prompt' => 'test',
            'session_id' => '',
            'options' => json_encode(['model' => 'custom-model', 'max_turns' => 5]),
        ];

        $cmd = $this->callMethod($this->manager, 'buildCommand', [$task]);

        $modelIdx = array_search('--model', $cmd);
        $this->assertSame('custom-model', $cmd[$modelIdx + 1]);

        $turnsIdx = array_search('--max-turns', $cmd);
        $this->assertSame('5', $cmd[$turnsIdx + 1]);
    }

    // buildEnvironment tests via reflection

    public function testBuildEnvironmentRemovesClaudeCode(): void
    {
        $_ENV['CLAUDECODE'] = '1';
        $_ENV['CLAUDE_CODE_ENTRY_POINT'] = 'test';

        try {
            $env = $this->callMethod($this->manager, 'buildEnvironment', []);

            $this->assertArrayNotHasKey('CLAUDECODE', $env);
            $this->assertArrayNotHasKey('CLAUDE_CODE_ENTRY_POINT', $env);
        } finally {
            unset($_ENV['CLAUDECODE'], $_ENV['CLAUDE_CODE_ENTRY_POINT']);
        }
    }

    public function testBuildEnvironmentSetsHome(): void
    {
        $env = $this->callMethod($this->manager, 'buildEnvironment', []);

        $this->assertArrayHasKey('HOME', $env);
    }

    public function testBuildEnvironmentEnsuresLocalBinInPath(): void
    {
        $env = $this->callMethod($this->manager, 'buildEnvironment', []);

        $this->assertArrayHasKey('PATH', $env);
        $this->assertStringContainsString('/Users/chadpeppers/.local/bin', $env['PATH']);
    }

    public function testBuildEnvironmentDoesNotDuplicateLocalBin(): void
    {
        $origEnvPath = $_ENV['PATH'] ?? null;
        $origServerPath = $_SERVER['PATH'] ?? null;

        $_ENV['PATH'] = '/Users/chadpeppers/.local/bin:/usr/bin';
        $_SERVER['PATH'] = '/Users/chadpeppers/.local/bin:/usr/bin';

        try {
            $env = $this->callMethod($this->manager, 'buildEnvironment', []);

            // Should not add it again since it's already there
            $this->assertStringContainsString('/Users/chadpeppers/.local/bin', $env['PATH']);
            $this->assertSame(1, substr_count($env['PATH'], '/Users/chadpeppers/.local/bin'));
        } finally {
            if ($origEnvPath !== null) {
                $_ENV['PATH'] = $origEnvPath;
            } else {
                unset($_ENV['PATH']);
            }
            if ($origServerPath !== null) {
                $_SERVER['PATH'] = $origServerPath;
            } else {
                unset($_SERVER['PATH']);
            }
        }
    }

    public function testBuildEnvironmentExcludesHttpServerVars(): void
    {
        $_SERVER['HTTP_HOST'] = 'localhost';
        $_SERVER['HTTP_USER_AGENT'] = 'test';
        $_SERVER['NORMAL_KEY'] = 'value';

        try {
            $env = $this->callMethod($this->manager, 'buildEnvironment', []);

            $this->assertArrayNotHasKey('HTTP_HOST', $env);
            $this->assertArrayNotHasKey('HTTP_USER_AGENT', $env);
        } finally {
            unset($_SERVER['HTTP_HOST'], $_SERVER['HTTP_USER_AGENT'], $_SERVER['NORMAL_KEY']);
        }
    }

    // continueTask tests

    public function testContinueTaskSuccess(): void
    {
        $this->taskManager->shouldReceive('getTask')
            ->with('parent-1')
            ->andReturn([
                'id' => 'parent-1',
                'claude_session_id' => 'claude-sess-xyz',
            ]);

        $this->taskManager->shouldReceive('createTask')
            ->with('follow up', 'claude-sess-xyz', [])
            ->andReturn('new-task-1');

        $this->taskManager->shouldReceive('setParentTaskId')
            ->with('new-task-1', 'parent-1')
            ->once();

        // executeTask spawns a Swoole coroutine which may run inline in test context.
        // Allow any additional calls from runTask that may occur.
        $this->taskManager->shouldReceive('getTask')->with('new-task-1')->andReturn(null)->byDefault();
        $this->taskManager->shouldReceive('transition')->byDefault();
        $this->taskManager->shouldReceive('setTaskError')->byDefault();

        $newTaskId = $this->manager->continueTask('parent-1', 'follow up');

        $this->assertSame('new-task-1', $newTaskId);
    }

    public function testContinueTaskParentNotFound(): void
    {
        $this->taskManager->shouldReceive('getTask')
            ->with('missing-parent')
            ->andReturn(null);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Parent task missing-parent not found');

        $this->manager->continueTask('missing-parent', 'follow up');
    }

    public function testContinueTaskNoSessionId(): void
    {
        $this->taskManager->shouldReceive('getTask')
            ->with('parent-1')
            ->andReturn([
                'id' => 'parent-1',
                'claude_session_id' => '',
            ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('has no claude_session_id');

        $this->manager->continueTask('parent-1', 'follow up');
    }

    public function testContinueTaskWithOptions(): void
    {
        $this->taskManager->shouldReceive('getTask')
            ->with('parent-1')
            ->andReturn([
                'id' => 'parent-1',
                'claude_session_id' => 'sess-abc',
            ]);

        $this->taskManager->shouldReceive('createTask')
            ->with('follow up', 'sess-abc', ['max_turns' => 10])
            ->andReturn('new-task-2');

        $this->taskManager->shouldReceive('setParentTaskId')->once();

        // Allow any additional calls from the coroutine that executeTask spawns
        $this->taskManager->shouldReceive('getTask')->with('new-task-2')->andReturn(null)->byDefault();
        $this->taskManager->shouldReceive('transition')->byDefault();
        $this->taskManager->shouldReceive('setTaskError')->byDefault();

        $newTaskId = $this->manager->continueTask('parent-1', 'follow up', ['max_turns' => 10]);

        $this->assertSame('new-task-2', $newTaskId);
    }
}
