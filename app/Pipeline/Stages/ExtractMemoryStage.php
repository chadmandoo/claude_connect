<?php

declare(strict_types=1);

namespace App\Pipeline\Stages;

use App\Pipeline\PipelineContext;
use App\Pipeline\PipelineStage;
use App\StateMachine\TaskManager;
use App\Claude\ProcessManager;
use App\Memory\MemoryManager;
use Psr\Log\LoggerInterface;

class ExtractMemoryStage implements PipelineStage
{
    public function __construct(
        private readonly TaskManager $taskManager,
        private readonly ProcessManager $processManager,
        private readonly MemoryManager $memoryManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function name(): string
    {
        return 'extract_memory';
    }

    public function shouldRun(PipelineContext $context): bool
    {
        return $context->userId !== '';
    }

    public function execute(PipelineContext $context): array
    {
        $task = $context->task;
        $userId = $context->userId;
        $prompt = $task['prompt'] ?? '';
        $result = $task['result'] ?? '';

        if ($prompt === '' || $result === '') {
            return ['success' => true, 'skipped' => 'empty prompt or result'];
        }

        // Truncate for extraction to keep cost low
        $prompt = mb_substr($prompt, 0, 1000);
        $result = mb_substr($result, 0, 2000);

        $extractionPrompt = <<<PROMPT
Analyze this conversation exchange and extract structured memory.

User asked: {$prompt}
Assistant replied: {$result}

Respond ONLY with valid JSON:
{
  "summary": "2-3 sentence summary of what was discussed and accomplished",
  "topics": ["topic1", "topic2"],
  "memories": [
    {"category": "preference|project|fact|context", "content": "specific thing to remember", "importance": "high|normal|low"}
  ]
}

Categories:
- preference: personal preferences, habits, communication style
- project: project details, codebases, tech stacks, architecture decisions
- fact: personal facts, names, locations, schedules
- context: situational context, ongoing work, recurring topics

Only include memories worth remembering long-term. If nothing notable, use empty array for memories.
PROMPT;

        $extractionTaskId = $this->taskManager->createTask($extractionPrompt, null, [
            'source' => 'extraction',
            'model' => 'claude-haiku-4-5-20251001',
            'max_turns' => 1,
            'max_budget_usd' => 0.05,
        ]);

        $this->processManager->executeTask($extractionTaskId);

        // Wait synchronously for extraction to complete
        $maxWait = 30;
        $elapsed = 0;
        while ($elapsed < $maxWait) {
            \Swoole\Coroutine::sleep(1);
            $elapsed++;

            $extractionTask = $this->taskManager->getTask($extractionTaskId);
            if (!$extractionTask) {
                return ['success' => false, 'error' => 'extraction task disappeared'];
            }

            $state = $extractionTask['state'] ?? '';
            if ($state === 'completed') {
                $extractResult = $extractionTask['result'] ?? '';
                $this->parseAndStoreMemory($userId, $extractResult);
                return ['success' => true];
            }

            if ($state === 'failed') {
                return ['success' => false, 'error' => 'extraction task failed'];
            }
        }

        return ['success' => false, 'error' => 'extraction timed out'];
    }

    private function parseAndStoreMemory(string $userId, string $extractResult): void
    {
        if (!preg_match('/\{[\s\S]*\}/', $extractResult, $matches)) {
            return;
        }

        $data = json_decode($matches[0], true);
        if (!is_array($data)) {
            return;
        }

        // Store richer summary in conversation log
        if (!empty($data['summary'])) {
            $summary = $data['summary'];
            if (!empty($data['topics'])) {
                $summary .= ' [' . implode(', ', $data['topics']) . ']';
            }
            $this->memoryManager->logConversation($userId, $summary);
        }

        // Store structured memories
        if (!empty($data['memories']) && is_array($data['memories'])) {
            foreach ($data['memories'] as $mem) {
                $category = $mem['category'] ?? 'fact';
                $content = $mem['content'] ?? '';
                $importance = $mem['importance'] ?? 'normal';

                if ($content !== '' && in_array($category, ['preference', 'project', 'fact', 'context', 'rule', 'conversation'], true)) {
                    $this->memoryManager->storeMemory($userId, $category, $content, $importance, 'extraction');
                }
            }
        }

        // Backward compat: also store flat facts if present (old format)
        if (!empty($data['facts']) && is_array($data['facts'])) {
            foreach ($data['facts'] as $key => $value) {
                if (is_string($key) && is_string($value) && $key !== '' && $value !== '') {
                    $this->memoryManager->remember($userId, $key, $value);
                }
            }
        }
    }
}
