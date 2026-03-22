<?php

declare(strict_types=1);

namespace App\Skills;

use Hyperf\Di\Annotation\Inject;
use Hyperf\Contract\ConfigInterface;
use Psr\Log\LoggerInterface;

class McpConfigGenerator
{
    private const TEMP_PREFIX = '/tmp/cc-mcp-';

    #[Inject]
    private ConfigInterface $config;

    #[Inject]
    private LoggerInterface $logger;

    /**
     * Generate an MCP config JSON file for a task.
     * Returns the path to the generated file, or null if no skills.
     */
    public function generateForTask(string $taskId, array $skills): ?string
    {
        if (empty($skills)) {
            return null;
        }

        $mcpConfig = ['mcpServers' => $skills];
        $json = json_encode($mcpConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $path = self::TEMP_PREFIX . $taskId . '.json';
        file_put_contents($path, $json);
        $this->logger->debug("MCP config written to {$path}");
        return $path;
    }

    /**
     * Cleanup the MCP config file for a task.
     */
    public function cleanup(string $taskId): void
    {
        $path = self::TEMP_PREFIX . $taskId . '.json';

        if (file_exists($path)) {
            unlink($path);
            $this->logger->debug("MCP config cleaned up: {$path}");
        }
    }
}
