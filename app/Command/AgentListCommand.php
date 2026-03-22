<?php

declare(strict_types=1);

namespace App\Command;

use App\Agent\AgentManager;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Command\Annotation\Command;
use Psr\Container\ContainerInterface;

#[Command]
class AgentListCommand extends HyperfCommand
{
    protected ?string $name = 'agent:list';

    protected string $description = 'List all agents';

    public function __construct(
        private ContainerInterface $container,
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $agentManager = $this->container->get(AgentManager::class);
        $agents = $agentManager->listAgents();

        if (empty($agents)) {
            $this->warn('No agents found.');
            return;
        }

        $rows = [];
        foreach ($agents as $agent) {
            $flags = [];
            if (($agent['is_default'] ?? false) || ($agent['is_default'] ?? '') === '1') {
                $flags[] = 'default';
            }
            if (($agent['is_system'] ?? false) || ($agent['is_system'] ?? '') === '1') {
                $flags[] = 'system';
            }

            $rows[] = [
                $agent['slug'],
                $agent['name'],
                $agent['color'] ?? '#6366f1',
                $agent['icon'] ?? 'bot',
                implode(', ', $flags) ?: '-',
                $agent['model'] ?? '-',
                substr($agent['id'], 0, 8),
            ];
        }

        $this->table(['Slug', 'Name', 'Color', 'Icon', 'Flags', 'Model', 'ID'], $rows);
    }
}
