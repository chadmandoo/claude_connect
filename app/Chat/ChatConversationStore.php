<?php

declare(strict_types=1);

namespace App\Chat;

use App\Storage\RedisStore;
use Hyperf\Di\Annotation\Inject;
use Psr\Log\LoggerInterface;

/**
 * Redis-backed ephemeral chat history store for Anthropic API message context.
 *
 * Handles message append, retrieval, trimming, compaction with summaries,
 * and sanitization of tool_use/tool_result message pairs.
 */
class ChatConversationStore
{
    #[Inject]
    private RedisStore $redis;

    #[Inject]
    private LoggerInterface $logger;

    private int $compactionThreshold = 30;

    public function setCompactionThreshold(int $threshold): void
    {
        $this->compactionThreshold = $threshold;
    }

    /**
     * Append a message to the chat history.
     *
     * @param array $message Anthropic-format message {role, content}
     */
    public function appendMessage(string $conversationId, array $message): void
    {
        $this->redis->appendChatHistory($conversationId, $message);
    }

    /**
     * Get the full message history for a conversation.
     *
     * @return array<int, array> Anthropic-format messages
     */
    public function getHistory(string $conversationId, int $limit = 50): array
    {
        return $this->redis->getChatHistory($conversationId, $limit);
    }

    /**
     * Trim history to keep only the most recent N messages.
     */
    public function trimHistory(string $conversationId, int $keep): void
    {
        $this->redis->trimChatHistory($conversationId, $keep);
    }

    /**
     * Delete all history for a conversation.
     */
    public function deleteHistory(string $conversationId): void
    {
        $this->redis->deleteChatHistory($conversationId);
    }

    /**
     * Check if history needs compaction and return whether it does.
     */
    public function needsCompaction(string $conversationId): bool
    {
        $history = $this->getHistory($conversationId, $this->compactionThreshold + 1);

        return count($history) > $this->compactionThreshold;
    }

    /**
     * Compact older messages by replacing them with a summary.
     * Keeps the most recent $keep messages and replaces older ones with a summary.
     * Adjusts the cut point to avoid splitting tool_use/tool_result pairs.
     */
    public function compactHistory(string $conversationId, string $summary, int $keep = 10): void
    {
        $history = $this->getHistory($conversationId);
        if (count($history) <= $keep) {
            return;
        }

        // Find a safe cut point that doesn't split tool_use/tool_result pairs
        $cutIndex = count($history) - $keep;
        $cutIndex = $this->findSafeCutPoint($history, $cutIndex);

        $recentMessages = array_slice($history, $cutIndex);

        // Delete existing history
        $this->deleteHistory($conversationId);

        // Add summary as first message
        $this->appendMessage($conversationId, [
            'role' => 'user',
            'content' => "[Previous conversation summary: {$summary}]",
        ]);
        $this->appendMessage($conversationId, [
            'role' => 'assistant',
            'content' => 'Understood, I have the context from our previous conversation.',
        ]);

        // Re-add recent messages
        foreach ($recentMessages as $msg) {
            $this->appendMessage($conversationId, $msg);
        }

        $this->logger->info("Compacted chat history for conversation {$conversationId}");
    }

    /**
     * Sanitize message history to fix tool_use/tool_result mismatches.
     * Removes orphaned tool_result blocks and ensures valid message alternation.
     *
     * @param array $messages Anthropic-format messages
     *
     * @return array Sanitized messages
     */
    public function sanitizeHistory(array $messages): array
    {
        if (empty($messages)) {
            return $messages;
        }

        $sanitized = [];

        for ($i = 0; $i < count($messages); $i++) {
            $msg = $messages[$i];
            $role = $msg['role'] ?? '';
            $content = $msg['content'] ?? '';

            // Check if this is a user message containing tool_result blocks
            if ($role === 'user' && is_array($content)) {
                $hasToolResults = false;
                foreach ($content as $block) {
                    if (($block['type'] ?? '') === 'tool_result') {
                        $hasToolResults = true;
                        break;
                    }
                }

                if ($hasToolResults) {
                    // Verify the previous message is an assistant message with matching tool_use blocks
                    $prevMsg = end($sanitized);
                    if (!$prevMsg || ($prevMsg['role'] ?? '') !== 'assistant') {
                        // No preceding assistant message — skip this orphaned tool_result
                        $this->logger->warning("Sanitize: dropping orphaned tool_result message at index {$i}");
                        continue;
                    }

                    $prevContent = $prevMsg['content'] ?? [];
                    if (!is_array($prevContent)) {
                        // Previous assistant message has no tool_use blocks
                        $this->logger->warning("Sanitize: dropping tool_result with no matching tool_use at index {$i}");
                        continue;
                    }

                    // Collect tool_use IDs from the previous assistant message
                    $toolUseIds = [];
                    foreach ($prevContent as $block) {
                        if (($block['type'] ?? '') === 'tool_use' && isset($block['id'])) {
                            $toolUseIds[$block['id']] = true;
                        }
                    }

                    // Filter tool_result blocks to only those with matching tool_use IDs
                    $filteredContent = [];
                    foreach ($content as $block) {
                        if (($block['type'] ?? '') === 'tool_result') {
                            $toolUseId = $block['tool_use_id'] ?? '';
                            if (!isset($toolUseIds[$toolUseId])) {
                                $this->logger->warning("Sanitize: dropping tool_result for unknown tool_use_id {$toolUseId}");
                                continue;
                            }
                        }
                        $filteredContent[] = $block;
                    }

                    if (empty($filteredContent)) {
                        // All tool_results were orphaned — skip the entire message
                        $this->logger->warning("Sanitize: dropping entirely orphaned tool_result message at index {$i}");
                        continue;
                    }

                    $msg['content'] = $filteredContent;
                }
            }

            $sanitized[] = $msg;
        }

        // Ensure messages start with a user message
        while (!empty($sanitized) && ($sanitized[0]['role'] ?? '') !== 'user') {
            array_shift($sanitized);
        }

        // Ensure proper user/assistant alternation (no consecutive same-role messages)
        $final = [];
        $lastRole = null;
        foreach ($sanitized as $msg) {
            $role = $msg['role'] ?? '';
            if ($role === $lastRole) {
                // Merge or skip consecutive same-role messages
                if ($role === 'assistant' && is_string($msg['content'] ?? null) && is_string(end($final)['content'] ?? null)) {
                    $lastIdx = count($final) - 1;
                    $final[$lastIdx]['content'] .= "\n" . $msg['content'];
                    continue;
                }
            }
            $final[] = $msg;
            $lastRole = $role;
        }

        return $final;
    }

    /**
     * Find a safe cut point that doesn't split tool_use/tool_result pairs.
     * Moves the cut point earlier if it would land between a tool_use and tool_result.
     */
    private function findSafeCutPoint(array $history, int $desiredCut): int
    {
        if ($desiredCut <= 0 || $desiredCut >= count($history)) {
            return $desiredCut;
        }

        // Check if the message at the cut point is a tool_result (user message with tool_result blocks)
        $msgAtCut = $history[$desiredCut] ?? null;
        if ($msgAtCut && ($msgAtCut['role'] ?? '') === 'user' && is_array($msgAtCut['content'] ?? null)) {
            foreach ($msgAtCut['content'] as $block) {
                if (($block['type'] ?? '') === 'tool_result') {
                    // This is a tool_result — move cut back to include the tool_use message
                    return max(0, $desiredCut - 1);
                }
            }
        }

        return $desiredCut;
    }
}
