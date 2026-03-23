<?php

declare(strict_types=1);

namespace App\Skills;

/**
 * Provides default MCP server configurations that are always available unless overridden.
 */
class BuiltinSkills
{
    /**
     * Get all built-in MCP server configurations.
     * These are always available unless overridden.
     */
    public function getAll(): array
    {
        return [
            'filesystem' => [
                'command' => 'npx',
                'args' => ['-y', '@modelcontextprotocol/server-filesystem', '/tmp'],
            ],
            'fetch' => [
                'command' => 'npx',
                'args' => ['-y', 'mcp-server-fetch'],
            ],
            'browser' => [
                'command' => 'npx',
                'args' => ['-y', '@playwright/mcp@latest'],
            ],
        ];
    }
}
