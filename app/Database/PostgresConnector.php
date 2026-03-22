<?php

declare(strict_types=1);

namespace App\Database;

use Hyperf\Database\Connectors\Connector;
use Hyperf\Database\Connectors\ConnectorInterface;
use PDO;

class PostgresConnector extends Connector implements ConnectorInterface
{
    public function connect(array $config): PDO
    {
        $dsn = $this->getDsn($config);
        $options = $this->getOptions($config);
        $connection = $this->createConnection($dsn, $config, $options);

        $this->configureEncoding($connection, $config);
        $this->configureTimezone($connection, $config);
        $this->configureSchema($connection, $config);

        return $connection;
    }

    protected function getDsn(array $config): string
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = $config['port'] ?? 5432;
        $database = $config['database'] ?? 'forge';

        return "pgsql:host={$host};port={$port};dbname={$database}";
    }

    protected function configureEncoding(PDO $connection, array $config): void
    {
        if (isset($config['charset'])) {
            $connection->prepare("set names '{$config['charset']}'")->execute();
        }
    }

    protected function configureTimezone(PDO $connection, array $config): void
    {
        if (isset($config['timezone'])) {
            $connection->prepare("set timezone to '{$config['timezone']}'")->execute();
        }
    }

    protected function configureSchema(PDO $connection, array $config): void
    {
        if (isset($config['schema'])) {
            $schema = is_array($config['schema'])
                ? implode(', ', $config['schema'])
                : $config['schema'];
            $connection->prepare("set search_path to {$schema}")->execute();
        }
    }
}
