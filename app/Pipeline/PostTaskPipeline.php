<?php

declare(strict_types=1);

namespace App\Pipeline;

use Psr\Log\LoggerInterface;

class PostTaskPipeline
{
    /** @var array<string, PipelineStage> */
    private array $stages = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function registerStage(PipelineStage $stage): void
    {
        $this->stages[$stage->name()] = $stage;
    }

    /**
     * Run pipeline stages in order. Never aborts on stage error.
     *
     * @param PipelineContext $context Shared context for all stages
     * @param string[]|null $stageNames Limit to these stages (from template config). Null = all registered.
     */
    public function run(PipelineContext $context, ?array $stageNames = null): void
    {
        $toRun = $stageNames !== null
            ? array_filter(
                array_map(fn (string $name) => $this->stages[$name] ?? null, $stageNames),
                fn (?PipelineStage $s) => $s !== null,
            )
            : array_values($this->stages);

        foreach ($toRun as $stage) {
            if (!$stage->shouldRun($context)) {
                $this->logger->debug("Pipeline: skipping stage '{$stage->name()}' (shouldRun=false)");
                continue;
            }

            $start = microtime(true);
            try {
                $result = $stage->execute($context);
                $duration = round((microtime(true) - $start) * 1000);
                $success = $result['success'] ?? false;

                if ($success) {
                    $this->logger->info("Pipeline: stage '{$stage->name()}' completed in {$duration}ms");
                } else {
                    $error = $result['error'] ?? 'unknown';
                    $this->logger->warning("Pipeline: stage '{$stage->name()}' failed in {$duration}ms: {$error}");
                }
            } catch (\Throwable $e) {
                $duration = round((microtime(true) - $start) * 1000);
                $this->logger->error("Pipeline: stage '{$stage->name()}' threw exception in {$duration}ms: {$e->getMessage()}");
            }
        }
    }
}
