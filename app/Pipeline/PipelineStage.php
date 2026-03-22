<?php

declare(strict_types=1);

namespace App\Pipeline;

interface PipelineStage
{
    public function name(): string;

    public function shouldRun(PipelineContext $context): bool;

    /**
     * Execute this stage.
     *
     * @return array{success: bool, error?: string}
     */
    public function execute(PipelineContext $context): array;
}
