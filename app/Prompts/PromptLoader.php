<?php

declare(strict_types=1);

namespace App\Prompts;

use Psr\Log\LoggerInterface;
use Hyperf\Di\Annotation\Inject;

class PromptLoader
{
    #[Inject]
    private LoggerInterface $logger;

    private string $promptDir;

    public function __construct()
    {
        $this->promptDir = BASE_PATH . '/prompts';
    }

    /**
     * Load a prompt file by name (without extension).
     * Returns the file contents or empty string if not found.
     */
    public function load(string $name): string
    {
        $path = $this->promptDir . '/' . $name . '.md';

        if (!file_exists($path)) {
            $this->logger->warning("Prompt file not found: {$path}");
            return '';
        }

        $content = file_get_contents($path);
        if ($content === false) {
            $this->logger->error("Failed to read prompt file: {$path}");
            return '';
        }

        return trim($content);
    }

    /**
     * Load an extraction prompt for a conversation type.
     * Falls back to the generic extraction format if not found.
     */
    public function loadExtractionPrompt(string $type): string
    {
        $prompt = $this->load("extraction/{$type}");
        if ($prompt !== '') {
            return $prompt;
        }
        // Fallback: generic extraction prompt
        return $this->load('extraction/task');
    }

    /**
     * Build the combined system prompt for generic tasks.
     * Combines the helper persona with optional user memory context.
     */
    public function buildGenericPrompt(string $memoryContext = ''): string
    {
        $parts = [];

        $helper = $this->load('helper');
        if ($helper !== '') {
            $parts[] = $helper;
        }

        if ($memoryContext !== '') {
            $parts[] = $memoryContext;
        }

        return implode("\n\n", $parts);
    }
}
