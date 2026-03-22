<?php

declare(strict_types=1);

namespace App\Database;

use Hyperf\Database\Connection;
use Hyperf\Database\Query\Grammars\Grammar as QueryGrammar;
use Hyperf\Database\Schema\Grammars\Grammar as SchemaGrammar;
use Hyperf\Database\Query\Processors\Processor;

class PostgresConnection extends Connection
{
    protected function getDefaultQueryGrammar(): QueryGrammar
    {
        return $this->withTablePrefix(new PostgresQueryGrammar());
    }

    protected function getDefaultSchemaGrammar(): SchemaGrammar
    {
        // Not needed for query builder usage
        return $this->withTablePrefix(new SchemaGrammar());
    }

    protected function getDefaultPostProcessor(): Processor
    {
        return new Processor();
    }
}
