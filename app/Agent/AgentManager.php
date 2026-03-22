<?php

declare(strict_types=1);

namespace App\Agent;

use App\Storage\PostgresStore;
use App\Prompts\PromptLoader;
use Hyperf\Di\Annotation\Inject;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

class AgentManager
{
    #[Inject]
    private PostgresStore $store;

    #[Inject]
    private PromptLoader $promptLoader;

    #[Inject]
    private LoggerInterface $logger;

    public function createAgent(array $data): string
    {
        $id = Uuid::uuid4()->toString();

        $this->store->createAgent($id, [
            'id' => $id,
            'slug' => $data['slug'],
            'name' => $data['name'],
            'description' => $data['description'] ?? '',
            'system_prompt' => $data['system_prompt'] ?? '',
            'model' => $data['model'] ?? '',
            'tool_access' => json_encode($data['tool_access'] ?? []),
            'project_id' => !empty($data['project_id']) ? $data['project_id'] : null,
            'memory_scope' => $data['memory_scope'] ?? '',
            'is_default' => $data['is_default'] ?? false,
            'is_system' => $data['is_system'] ?? false,
            'color' => $data['color'] ?? '#6366f1',
            'icon' => $data['icon'] ?? 'bot',
            'created_at' => time(),
            'updated_at' => time(),
        ]);

        $this->logger->info("Agent created: {$data['slug']} ({$id})");
        return $id;
    }

    public function getAgent(string $id): ?array
    {
        return $this->store->getAgent($id);
    }

    public function getAgentBySlug(string $slug): ?array
    {
        return $this->store->getAgentBySlug($slug);
    }

    public function getDefaultAgent(): array
    {
        $agent = $this->store->getDefaultAgent();
        if ($agent !== null) {
            return $agent;
        }

        // Fallback: return the PM agent by slug
        $pm = $this->store->getAgentBySlug('pm');
        if ($pm !== null) {
            return $pm;
        }

        // Last resort: seed and return
        $this->seedDefaultAgents();
        return $this->store->getDefaultAgent() ?? $this->store->getAgentBySlug('pm') ?? [];
    }

    public function listAgents(?string $projectId = null): array
    {
        return $this->store->listAgents($projectId);
    }

    public function updateAgent(string $id, array $data): void
    {
        $agent = $this->store->getAgent($id);
        if ($agent === null) {
            throw new \RuntimeException("Agent {$id} not found");
        }

        // Prevent editing slug on system agents
        if (($agent['is_system'] ?? '0') === '1' && isset($data['slug']) && $data['slug'] !== $agent['slug']) {
            throw new \RuntimeException("Cannot change slug of system agent");
        }

        $update = [];
        $allowed = ['slug', 'name', 'description', 'system_prompt', 'model', 'tool_access', 'project_id', 'memory_scope', 'is_default', 'color', 'icon'];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                if ($field === 'tool_access' && is_array($data[$field])) {
                    $update[$field] = json_encode($data[$field]);
                } elseif ($field === 'project_id') {
                    $update[$field] = !empty($data[$field]) ? $data[$field] : null;
                } elseif ($field === 'is_default') {
                    $update[$field] = (bool) $data[$field];
                } else {
                    $update[$field] = $data[$field];
                }
            }
        }

        if (!empty($update)) {
            $update['updated_at'] = time();

            // If setting as default, clear other defaults
            if (isset($update['is_default']) && $update['is_default']) {
                $this->store->clearDefaultAgents();
            }

            $this->store->updateAgent($id, $update);
        }
    }

    public function deleteAgent(string $id): void
    {
        $agent = $this->store->getAgent($id);
        if ($agent === null) {
            throw new \RuntimeException("Agent {$id} not found");
        }

        if (($agent['is_system'] ?? '0') === '1') {
            throw new \RuntimeException("Cannot delete system agent");
        }

        $this->store->deleteAgent($id);
        $this->logger->info("Agent deleted: {$agent['slug']} ({$id})");
    }

    /**
     * Seed the 3 system agents from prompt .md files.
     * Idempotent — skips agents that already exist by slug.
     */
    public function seedDefaultAgents(): void
    {
        $agents = [
            [
                'slug' => 'pm',
                'name' => 'PM',
                'description' => 'Conversational Project Manager — helps plan, brainstorm, and delegate tasks',
                'prompt_file' => 'chat_pm',
                'is_default' => true,
                'is_system' => true,
                'color' => '#6366f1',
                'icon' => 'briefcase',
            ],
            [
                'slug' => 'general',
                'name' => 'General',
                'description' => 'General-purpose assistant — answers questions, writes, analyzes, and delegates work',
                'prompt_file' => 'general',
                'is_default' => false,
                'is_system' => true,
                'color' => '#3b82f6',
                'icon' => 'bot',
            ],
            [
                'slug' => 'project',
                'name' => 'Project Agent',
                'description' => 'Focused project specialist — executes tasks within a project context',
                'prompt_file' => 'project_agent',
                'is_default' => false,
                'is_system' => true,
                'color' => '#10b981',
                'icon' => 'code',
            ],
            [
                'slug' => 'architect',
                'name' => 'Architect',
                'description' => 'Full CLI access to Claude Connect — fix bugs, add features, review code',
                'prompt_file' => 'helper',
                'is_default' => false,
                'is_system' => true,
                'color' => '#f59e0b',
                'icon' => 'wrench',
            ],
            [
                'slug' => 'claude-swoole',
                'name' => 'Claude Swoole',
                'description' => 'Claude Connect development agent — PHP 8.3 + Swoole 6.0 + Hyperf 3.1 specialist',
                'prompt_file' => 'claude_swoole',
                'is_default' => false,
                'is_system' => true,
                'color' => '#ec4899',
                'icon' => 'wrench',
            ],
        ];

        foreach ($agents as $agentData) {
            $existing = $this->store->getAgentBySlug($agentData['slug']);
            if ($existing !== null) {
                continue;
            }

            $promptContent = '';
            try {
                $promptContent = $this->promptLoader->load($agentData['prompt_file']);
            } catch (\Throwable $e) {
                $this->logger->warning("Failed to load prompt for {$agentData['slug']}: {$e->getMessage()}");
            }

            $this->createAgent([
                'slug' => $agentData['slug'],
                'name' => $agentData['name'],
                'description' => $agentData['description'],
                'system_prompt' => $promptContent,
                'is_default' => $agentData['is_default'],
                'is_system' => $agentData['is_system'],
                'color' => $agentData['color'],
                'icon' => $agentData['icon'],
            ]);
        }

        $this->logger->info("Default agents seeded");

        // Seed a channel per system agent (idempotent)
        $this->seedAgentChannels();
    }

    /**
     * Create a dedicated channel for each system agent.
     * Idempotent — skips channels that already exist.
     */
    public function seedAgentChannels(): void
    {
        $systemAgents = array_filter(
            $this->listAgents(),
            fn($a) => in_array($a['is_system'] ?? false, [true, 1, '1', 't'], true)
        );

        foreach ($systemAgents as $agent) {
            $slug = $agent['slug'] ?? '';
            $agentId = $agent['id'] ?? '';
            if ($slug === '' || $agentId === '') continue;

            $channelId = "agent_{$slug}";
            $existing = $this->store->getChannel($channelId);
            if ($existing !== null) {
                // Ensure the agent is assigned to its channel
                $roomAgents = $this->store->getRoomAgents($channelId);
                $agentIds = array_column($roomAgents, 'id');
                if (!in_array($agentId, $agentIds, true)) {
                    $this->store->addRoomAgent($channelId, $agentId, true);
                }
                continue;
            }

            $this->store->saveChannel([
                'id' => $channelId,
                'name' => $agent['name'] ?? $slug,
                'description' => $agent['description'] ?? '',
                'member_count' => 1,
                'created_at' => time(),
            ]);

            $this->store->addRoomAgent($channelId, $agentId, true);
            $this->logger->info("Seeded channel #{$slug} for agent {$agent['name']}");
        }
    }

    /**
     * Backfill existing conversations that have no agent_id with the PM agent.
     */
    public function backfillConversationAgents(): void
    {
        $pmAgent = $this->store->getAgentBySlug('pm');
        if ($pmAgent === null) {
            return;
        }

        $this->store->backfillConversationAgentId($pmAgent['id']);
        $this->logger->info("Backfilled conversations with PM agent_id={$pmAgent['id']}");
    }

    // --- Room agent management ---

    public function addAgentToRoom(string $roomId, string $agentId, bool $isDefault = false): void
    {
        $this->store->addRoomAgent($roomId, $agentId, $isDefault);
    }

    public function removeAgentFromRoom(string $roomId, string $agentId): void
    {
        $this->store->removeRoomAgent($roomId, $agentId);
    }

    public function getRoomAgents(string $roomId): array
    {
        return $this->store->getRoomAgents($roomId);
    }

    public function setRoomDefaultAgent(string $roomId, string $agentId): void
    {
        $this->store->setRoomDefaultAgent($roomId, $agentId);
    }

    public function getRoomDefaultAgent(string $roomId): ?array
    {
        return $this->store->getRoomDefaultAgent($roomId);
    }
}
