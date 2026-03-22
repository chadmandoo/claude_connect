<?php

declare(strict_types=1);

namespace App\Command;

use App\Prompts\PromptLoader;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;

#[Command]
class ClaudeSwooleHelperCommand extends HyperfCommand
{
    protected ?string $name = 'claude-swoole-helper';

    protected string $description = 'Launch interactive Claude session with helper persona';

    public function __construct(
        private ContainerInterface $container,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $promptLoader = $this->container->get(PromptLoader::class);
        $config = $this->container->get(ConfigInterface::class);

        $prompt = $promptLoader->load('helper');
        if ($prompt === '') {
            $this->error('Failed to load helper prompt.');
            return;
        }

        $cliPath = $config->get('mcp.claude.cli_path', '/Users/chadpeppers/.local/bin/claude');

        $command = [
            $cliPath,
            '--append-system-prompt', $prompt,
        ];

        $this->info('Launching Claude with helper persona...');
        $this->info('Press Ctrl+C to exit.');
        $this->line('');

        $descriptors = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];

        $process = proc_open($command, $descriptors, $pipes);

        if (!is_resource($process)) {
            $this->error('Failed to start Claude CLI process.');
            return;
        }

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $this->warn("Claude exited with code {$exitCode}");
        }
    }
}
