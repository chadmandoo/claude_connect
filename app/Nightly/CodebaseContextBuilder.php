<?php

declare(strict_types=1);

namespace App\Nightly;

use Psr\Log\LoggerInterface;

/**
 * Builds a summarized codebase context string from priority project files for nightly consolidation.
 */
class CodebaseContextBuilder
{
    private const PRIORITY_FILES = [
        'CLAUDE.md',
        'README.md',
        'composer.json',
        'package.json',
        '.env.example',
        'ARCHITECTURE.md',
    ];

    private const MAX_FILE_CHARS = 1500;
    private const MAX_TOTAL_CHARS = 4000;

    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Build a codebase context string from key project files.
     */
    public function build(string $cwd): string
    {
        if ($cwd === '' || !is_dir($cwd)) {
            return '';
        }

        $parts = [];
        $totalChars = 0;

        foreach (self::PRIORITY_FILES as $filename) {
            $path = rtrim($cwd, '/') . '/' . $filename;

            if (!is_file($path) || !is_readable($path)) {
                continue;
            }

            $content = @file_get_contents($path);
            if ($content === false || $content === '') {
                continue;
            }

            // Truncate individual file
            if (mb_strlen($content) > self::MAX_FILE_CHARS) {
                $content = mb_substr($content, 0, self::MAX_FILE_CHARS) . "\n... (truncated)";
            }

            $section = "### {$filename}\n```\n{$content}\n```";
            $sectionLen = mb_strlen($section);

            if ($totalChars + $sectionLen > self::MAX_TOTAL_CHARS) {
                break;
            }

            $parts[] = $section;
            $totalChars += $sectionLen;
        }

        if (empty($parts)) {
            return '';
        }

        return "## Codebase Context\n\n" . implode("\n\n", $parts);
    }
}
