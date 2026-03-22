<?php

declare(strict_types=1);

namespace App\Workflow;

use Hyperf\Contract\ConfigInterface;

class TemplateResolver
{
    public function __construct(
        private readonly ConfigInterface $config,
    ) {}

    /**
     * Resolve a workflow template by explicit name or auto-detection from prompt.
     *
     * @return array Template config with keys: name, label, max_turns, max_budget_usd, progress_interval, pipeline_stages, keywords
     */
    public function resolve(?string $templateName, string $prompt): array
    {
        $templates = $this->config->get('mcp.workflow.templates', []);
        $defaultName = $this->config->get('mcp.workflow.default_template', 'standard');

        // Explicit name takes priority
        if ($templateName !== null && isset($templates[$templateName])) {
            return array_merge($templates[$templateName], ['name' => $templateName]);
        }

        // Auto-detect from prompt keywords
        if ($this->config->get('mcp.workflow.auto_detect', true)) {
            $detected = $this->autoDetect($prompt, $templates);
            if ($detected !== null) {
                return $detected;
            }
        }

        // Fallback to default
        if (isset($templates[$defaultName])) {
            return array_merge($templates[$defaultName], ['name' => $defaultName]);
        }

        // Absolute fallback if config is missing
        return [
            'name' => 'standard',
            'label' => 'Standard Task',
            'max_turns' => 25,
            'max_budget_usd' => 5.00,
            'progress_interval' => 30,
            'pipeline_stages' => ['post_result', 'upload_images', 'extract_memory', 'project_detection'],
            'keywords' => [],
        ];
    }

    /**
     * List all available templates.
     *
     * @return array<string, array>
     */
    public function listTemplates(): array
    {
        return $this->config->get('mcp.workflow.templates', []);
    }

    /**
     * Auto-detect template from prompt using keyword scoring.
     * Longest keyword match wins to avoid false positives from short matches.
     */
    private function autoDetect(string $prompt, array $templates): ?array
    {
        $promptLower = strtolower($prompt);
        $bestMatch = null;
        $bestScore = 0;

        foreach ($templates as $name => $template) {
            $keywords = $template['keywords'] ?? [];
            if (empty($keywords)) {
                continue;
            }

            foreach ($keywords as $keyword) {
                $keywordLower = strtolower($keyword);
                if (str_contains($promptLower, $keywordLower)) {
                    // Score by keyword length — longer matches are more specific
                    $score = strlen($keywordLower);
                    if ($score > $bestScore) {
                        $bestScore = $score;
                        $bestMatch = array_merge($template, ['name' => $name]);
                    }
                }
            }
        }

        return $bestMatch;
    }
}
