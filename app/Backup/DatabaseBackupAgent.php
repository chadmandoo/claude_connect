<?php

declare(strict_types=1);

namespace App\Backup;

use App\Scheduler\SystemChannel;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\Inject;
use Psr\Log\LoggerInterface;

class DatabaseBackupAgent
{
    #[Inject]
    private ConfigInterface $config;

    #[Inject]
    private SystemChannel $systemChannel;

    #[Inject]
    private LoggerInterface $logger;

    public function run(): string
    {
        $backupConfig = $this->getConfig();

        if (!$backupConfig['enabled']) {
            return 'Database backup disabled';
        }

        $backupDir = $backupConfig['backup_dir'];
        if (!is_dir($backupDir)) {
            if (!mkdir($backupDir, 0750, true)) {
                $this->logger->error("DatabaseBackup: failed to create backup directory: {$backupDir}");
                return "Error: failed to create backup directory: {$backupDir}";
            }
        }

        $timestamp = date('Y-m-d_His');
        $filename = "claude_connect_{$timestamp}.sql.gz";
        $filepath = rtrim($backupDir, '/') . '/' . $filename;

        $dbHost = $this->config->get('databases.default.host', '127.0.0.1');
        $dbPort = (int) $this->config->get('databases.default.port', 5433);
        $dbName = $this->config->get('databases.default.database', 'claude_connect');
        $dbUser = $this->config->get('databases.default.username', 'claude_connect');
        $dbPass = $this->config->get('databases.default.password', '');

        $this->logger->info("DatabaseBackup: starting backup of '{$dbName}' to '{$filepath}'");

        $env = [];
        if ($dbPass !== '') {
            $env['PGPASSWORD'] = $dbPass;
        }

        $envPrefix = '';
        foreach ($env as $key => $value) {
            $envPrefix .= sprintf('%s=%s ', $key, escapeshellarg($value));
        }

        $cmd = sprintf(
            '%spg_dump -h %s -p %d -U %s -Fc %s | gzip > %s',
            $envPrefix,
            escapeshellarg($dbHost),
            $dbPort,
            escapeshellarg($dbUser),
            escapeshellarg($dbName),
            escapeshellarg($filepath)
        );

        $output = [];
        $returnCode = 0;
        exec($cmd . ' 2>&1', $output, $returnCode);

        if ($returnCode !== 0) {
            $error = implode("\n", $output);
            $this->logger->error("DatabaseBackup: pg_dump failed (code {$returnCode}): {$error}");
            // Clean up failed file
            if (file_exists($filepath)) {
                unlink($filepath);
            }
            return "Error: pg_dump failed (code {$returnCode}): " . mb_substr($error, 0, 300);
        }

        $fileSize = file_exists($filepath) ? filesize($filepath) : 0;
        $fileSizeHuman = $this->humanFileSize($fileSize);

        $this->logger->info("DatabaseBackup: completed — {$filename} ({$fileSizeHuman})");

        // Prune old backups
        $pruned = $this->pruneOldBackups($backupDir, $backupConfig['retention_days']);

        $result = "Backup complete: {$filename} ({$fileSizeHuman})";
        if ($pruned > 0) {
            $result .= ", pruned {$pruned} old backup(s)";
        }

        $this->systemChannel->post(
            "**Database backup complete** — `{$filename}` ({$fileSizeHuman})" . ($pruned > 0 ? ", pruned {$pruned} old backup(s)" : ''),
            'System'
        );

        return $result;
    }

    private function pruneOldBackups(string $backupDir, int $retentionDays): int
    {
        $cutoff = time() - ($retentionDays * 86400);
        $pruned = 0;

        $files = glob(rtrim($backupDir, '/') . '/claude_connect_*.sql.gz');
        if ($files === false) {
            return 0;
        }

        foreach ($files as $file) {
            $mtime = filemtime($file);
            if ($mtime !== false && $mtime < $cutoff) {
                if (unlink($file)) {
                    $pruned++;
                    $this->logger->info("DatabaseBackup: pruned old backup: " . basename($file));
                }
            }
        }

        return $pruned;
    }

    private function getConfig(): array
    {
        return [
            'enabled' => (bool) $this->config->get('mcp.backup.enabled', true),
            'backup_dir' => (string) $this->config->get('mcp.backup.backup_dir', dirname(__DIR__, 2) . '/backups'),
            'retention_days' => (int) $this->config->get('mcp.backup.retention_days', 14),
        ];
    }

    private function humanFileSize(int|false $bytes): string
    {
        if ($bytes === false || $bytes === 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
