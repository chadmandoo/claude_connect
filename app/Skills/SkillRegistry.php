<?php

declare(strict_types=1);

namespace App\Skills;

use App\Storage\PostgresStore;
use Hyperf\Di\Annotation\Inject;
use Psr\Log\LoggerInterface;

/**
 * Registry for MCP server skills with layered resolution (builtin, global, user-specific).
 *
 * User-scoped skills override global skills, which override built-in skills,
 * allowing per-user customization of available Claude CLI tool servers.
 */
class SkillRegistry
{
    #[Inject]
    private PostgresStore $store;

    #[Inject]
    private BuiltinSkills $builtinSkills;

    #[Inject]
    private LoggerInterface $logger;

    /**
     * Register a global MCP server skill.
     */
    public function registerGlobal(string $name, array $config): void
    {
        $this->store->setSkill('global', $name, $config);
        $this->logger->info("Skill registered globally: {$name}");
    }

    /**
     * Register a user-specific MCP server skill.
     */
    public function registerForUser(string $userId, string $name, array $config): void
    {
        $this->store->setSkill($userId, $name, $config);
        $this->logger->info("Skill registered for user {$userId}: {$name}");
    }

    /**
     * Get all skills for a user (merges builtin + global + user-specific).
     * User skills override global, global overrides builtin.
     */
    public function getSkillsForUser(string $userId): array
    {
        $builtin = $this->builtinSkills->getAll();
        $global = $this->store->getAllSkills('global');
        $userSkills = $this->store->getAllSkills($userId);

        return array_merge($builtin, $global, $userSkills);
    }

    /**
     * List skills for a specific scope.
     */
    public function listSkills(string $scope): array
    {
        if ($scope === 'builtin') {
            return $this->builtinSkills->getAll();
        }

        return $this->store->getAllSkills($scope);
    }

    /**
     * Remove a skill from a scope.
     */
    public function removeSkill(string $scope, string $name): void
    {
        $this->store->deleteSkill($scope, $name);
        $this->logger->info("Skill removed from {$scope}: {$name}");
    }
}
