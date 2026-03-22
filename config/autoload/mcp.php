<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    'claude' => [
        'cli_path' => env('CLAUDE_CLI_PATH', '/Users/chadpeppers/.local/bin/claude'),
        'max_turns' => (int) env('CLAUDE_MAX_TURNS', 25),
        'max_budget_usd' => (float) env('CLAUDE_MAX_BUDGET_USD', 5.00),
        'default_model' => env('CLAUDE_DEFAULT_MODEL', ''),
        'process_timeout' => (int) env('CLAUDE_PROCESS_TIMEOUT', 0),
        // Tools the agent is allowed to use non-interactively.
        // Empty array = no --allowedTools flag (tools requiring approval get auto-denied).
        // Use ["Bash", "Read", "Write", "Edit", "Glob", "Grep", "WebSearch", "WebFetch"] etc.
        'allowed_tools' => array_filter(explode(',', env('CLAUDE_ALLOWED_TOOLS', ''))),
    ],

    'workflow' => [
        'default_template' => 'standard',
        'auto_detect' => true,
        'templates' => [
            'quick' => [
                'label' => 'Quick Answer',
                'max_turns' => 5,
                'max_budget_usd' => 0.50,
                'progress_interval' => 0,
                'pipeline_stages' => ['post_result', 'extract_memory'],
                'keywords' => ['what is', 'how do', 'explain', 'define', 'quick question'],
            ],
            'standard' => [
                'label' => 'Standard Task',
                'max_turns' => 35,
                'max_budget_usd' => 5.00,
                'progress_interval' => 30,
                'pipeline_stages' => ['post_result', 'upload_images', 'extract_memory', 'extract_conversation', 'project_detection', 'embed_conversation', 'embed_task_result'],
                'keywords' => [],
            ],
            'deep' => [
                'label' => 'Deep Work',
                'max_turns' => 75,
                'max_budget_usd' => 10.00,
                'progress_interval' => 60,
                'pipeline_stages' => ['post_result', 'upload_images', 'extract_memory', 'extract_conversation', 'project_detection', 'embed_conversation', 'embed_task_result'],
                'keywords' => ['build', 'implement', 'refactor', 'architect', 'redesign', 'create'],
            ],
            'browse' => [
                'label' => 'Browse',
                'max_turns' => 10,
                'max_budget_usd' => 1.00,
                'progress_interval' => 15,
                'pipeline_stages' => ['post_result', 'upload_images', 'extract_memory'],
                'keywords' => ['browse', 'screenshot', 'navigate', 'visit', 'look at'],
            ],
        ],
    ],

    'agent' => [
        'routing_model' => env('AGENT_ROUTING_MODEL', 'claude-haiku-4-5-20251001'),
        'routing_timeout' => (int) env('AGENT_ROUTING_TIMEOUT', 5),
    ],

    'web' => [
        'auth_password' => env('WEB_AUTH_PASSWORD', ''),
        'user_id' => env('WEB_USER_ID', 'web_user'),
    ],

    'project' => [
        'max_iterations' => (int) env('PROJECT_MAX_ITERATIONS', 20),
        'max_budget_usd' => (float) env('PROJECT_MAX_BUDGET_USD', 10.00),
        'checkpoint_interval' => (int) env('PROJECT_CHECKPOINT_INTERVAL', 5),
        'step_budget_usd' => (float) env('PROJECT_STEP_BUDGET_USD', 2.00),
        'orchestrator_interval' => (int) env('PROJECT_ORCHESTRATOR_INTERVAL', 5),
        'auto_detect' => (bool) env('PROJECT_AUTO_DETECT', true),
    ],

    'embedding' => [
        'provider' => 'voyage',
        'api_key' => env('VOYAGE_API_KEY', ''),
        'model' => env('VOYAGE_MODEL', 'voyage-3.5-lite'),
        'dimensions' => (int) env('VOYAGE_DIMENSIONS', 1024),
        'batch_size' => (int) env('VOYAGE_BATCH_SIZE', 64),
    ],

    'nightly' => [
        'enabled' => (bool) env('NIGHTLY_ENABLED', true),
        'run_hour' => (int) env('NIGHTLY_RUN_HOUR', 2),
        'run_minute' => (int) env('NIGHTLY_RUN_MINUTE', 0),
        'max_budget_usd' => (float) env('NIGHTLY_MAX_BUDGET_USD', 1.00),
        'haiku_call_budget_usd' => (float) env('NIGHTLY_HAIKU_CALL_BUDGET_USD', 0.05),
        'batch_size' => (int) env('NIGHTLY_BATCH_SIZE', 20),
        'summarization_threshold' => (int) env('NIGHTLY_SUMMARIZATION_THRESHOLD', 50),
        'similarity_threshold' => (float) env('NIGHTLY_SIMILARITY_THRESHOLD', 0.85),
    ],

    'item_agent' => [
        'enabled' => (bool) env('ITEM_AGENT_ENABLED', false),
        'poll_interval' => (int) env('ITEM_AGENT_POLL_INTERVAL', 10),
        'max_budget_per_item' => (float) env('ITEM_AGENT_MAX_BUDGET', 2.00),
        'auto_assign_urgent' => (bool) env('ITEM_AGENT_AUTO_ASSIGN_URGENT', false),
        'allowed_project_ids' => array_filter(explode(',', env('ITEM_AGENT_PROJECTS', ''))),
    ],

    'chat' => [
        'enabled' => (bool) env('CHAT_API_ENABLED', false),
        'api_key' => env('ANTHROPIC_API_KEY', ''),
        'model' => env('CHAT_MODEL', 'claude-sonnet-4-20250514'),
        'max_tokens' => (int) env('CHAT_MAX_TOKENS', 4096),
        'max_tool_rounds' => (int) env('CHAT_MAX_TOOL_ROUNDS', 10),
        'compaction_threshold' => (int) env('CHAT_COMPACTION_THRESHOLD', 30),
        'temperature' => (float) env('CHAT_TEMPERATURE', 0.7),
    ],

    'supervisor' => [
        'enabled' => (bool) env('SUPERVISOR_ENABLED', false),
        'tick_interval' => (int) env('SUPERVISOR_TICK_INTERVAL', 30),
        'max_parallel_agents' => (int) env('SUPERVISOR_MAX_PARALLEL', 2),
        'stall_timeout' => (int) env('SUPERVISOR_STALL_TIMEOUT', 1800),
        'max_retries' => (int) env('SUPERVISOR_MAX_RETRIES', 1),
    ],

    'backup' => [
        'enabled' => (bool) env('BACKUP_ENABLED', true),
        'run_hour' => (int) env('BACKUP_RUN_HOUR', 3),
        'run_minute' => (int) env('BACKUP_RUN_MINUTE', 0),
        'backup_dir' => env('BACKUP_DIR', dirname(__DIR__, 2) . '/backups'),
        'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),
    ],

    'cleanup' => [
        'enabled' => (bool) env('CLEANUP_ENABLED', true),
        'interval' => (int) env('CLEANUP_INTERVAL', 21600), // 6 hours
        'retention_days_tasks' => (int) env('CLEANUP_RETENTION_DAYS_TASKS', 7),
        'retention_days_conversations' => (int) env('CLEANUP_RETENTION_DAYS_CONVERSATIONS', 14),
        'batch_size' => (int) env('CLEANUP_BATCH_SIZE', 15),
        'max_budget_usd' => (float) env('CLEANUP_MAX_BUDGET_USD', 0.50),
        'haiku_call_budget_usd' => (float) env('CLEANUP_HAIKU_CALL_BUDGET_USD', 0.05),
        'max_items_per_run' => (int) env('CLEANUP_MAX_ITEMS_PER_RUN', 200),
        'stale_task_timeout' => (int) env('CLEANUP_STALE_TASK_TIMEOUT', 5400), // 90 minutes
    ],
];
