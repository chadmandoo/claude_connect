<?php

declare(strict_types=1);

namespace App\Pipeline;

class PipelineContext
{
    /**
     * Mutable bag for stages to pass data downstream.
     * E.g. PostResultStage sets 'result_posted' => true.
     */
    public array $bag = [];

    public function __construct(
        public readonly array $task,
        public readonly string $userId,
        public readonly array $templateConfig = [],
        public readonly string $conversationId = '',
        public readonly string $conversationType = '',
    ) {}
}
