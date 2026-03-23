<?php

declare(strict_types=1);

namespace App\Chat;

/**
 * Defines tool schemas for the Anthropic Messages API including task creation and project management.
 */
class ToolDefinitions
{
    /**
     * Return tool schemas for the Anthropic Messages API.
     *
     * @return array<int, array>
     */
    public function getTools(): array
    {
        return [
            [
                'name' => 'create_task',
                'description' => 'Queue a task for the background agent to execute. Use this for any work that requires running Claude CLI — code generation, file editing, debugging, research within a project codebase, etc.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'prompt' => [
                            'type' => 'string',
                            'description' => 'Detailed instructions for the agent. Be specific about what to do, which files to touch, and what the expected outcome is.',
                        ],
                        'project_id' => [
                            'type' => 'string',
                            'description' => 'Project/workspace ID to scope the task to. Use "general" if not project-specific.',
                        ],
                        'priority' => [
                            'type' => 'string',
                            'enum' => ['low', 'normal', 'high', 'urgent'],
                            'description' => 'Task priority. Defaults to "normal".',
                        ],
                        'max_turns' => [
                            'type' => 'integer',
                            'description' => 'Max Claude CLI turns. Defaults to 25.',
                        ],
                        'max_budget_usd' => [
                            'type' => 'number',
                            'description' => 'Max budget in USD. Defaults to 5.00.',
                        ],
                    ],
                    'required' => ['prompt'],
                ],
            ],
            [
                'name' => 'check_task_status',
                'description' => 'Check the current status of a task (pending, running, completed, failed).',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => [
                            'type' => 'string',
                            'description' => 'The task ID to check.',
                        ],
                    ],
                    'required' => ['task_id'],
                ],
            ],
            [
                'name' => 'get_task_output',
                'description' => 'Get the full output/result of a completed task.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => [
                            'type' => 'string',
                            'description' => 'The task ID to get output for.',
                        ],
                    ],
                    'required' => ['task_id'],
                ],
            ],
            [
                'name' => 'cancel_task',
                'description' => 'Cancel a pending task or kill a running task.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'task_id' => [
                            'type' => 'string',
                            'description' => 'The task ID to cancel.',
                        ],
                    ],
                    'required' => ['task_id'],
                ],
            ],
            [
                'name' => 'list_tasks',
                'description' => 'List tasks, optionally filtered by state.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'state' => [
                            'type' => 'string',
                            'enum' => ['pending', 'running', 'completed', 'failed'],
                            'description' => 'Filter by task state. Omit for all tasks.',
                        ],
                        'limit' => [
                            'type' => 'integer',
                            'description' => 'Max number of tasks to return. Defaults to 10.',
                        ],
                    ],
                ],
            ],
            [
                'name' => 'list_projects',
                'description' => 'List all project workspaces.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => (object) [],
                ],
            ],
            [
                'name' => 'create_project',
                'description' => 'Create a new project workspace.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => [
                            'type' => 'string',
                            'description' => 'Project name.',
                        ],
                        'description' => [
                            'type' => 'string',
                            'description' => 'Project description.',
                        ],
                        'cwd' => [
                            'type' => 'string',
                            'description' => 'Working directory for the project.',
                        ],
                    ],
                    'required' => ['name'],
                ],
            ],
            [
                'name' => 'search_memory',
                'description' => 'Search stored memories semantically or by keyword.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search query.',
                        ],
                        'project_id' => [
                            'type' => 'string',
                            'description' => 'Optional project ID to scope search.',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
            [
                'name' => 'store_memory',
                'description' => 'Store a new memory for the user.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'content' => [
                            'type' => 'string',
                            'description' => 'Memory content to store.',
                        ],
                        'category' => [
                            'type' => 'string',
                            'enum' => ['preference', 'project', 'fact', 'context', 'rule', 'conversation'],
                            'description' => 'Memory category. Defaults to "fact".',
                        ],
                        'importance' => [
                            'type' => 'string',
                            'enum' => ['low', 'normal', 'high'],
                            'description' => 'Memory importance. Defaults to "normal".',
                        ],
                        'project_id' => [
                            'type' => 'string',
                            'description' => 'Optional project ID for project-scoped memory.',
                        ],
                    ],
                    'required' => ['content'],
                ],
            ],
            [
                'name' => 'list_items',
                'description' => 'List work items for a project.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => [
                            'type' => 'string',
                            'description' => 'Project ID to list items for.',
                        ],
                        'state' => [
                            'type' => 'string',
                            'enum' => ['open', 'in_progress', 'review', 'blocked', 'done', 'cancelled'],
                            'description' => 'Filter by item state. Omit for all.',
                        ],
                    ],
                    'required' => ['project_id'],
                ],
            ],
            [
                'name' => 'create_item',
                'description' => 'Create a new work item in a project.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'project_id' => [
                            'type' => 'string',
                            'description' => 'Project ID.',
                        ],
                        'title' => [
                            'type' => 'string',
                            'description' => 'Item title.',
                        ],
                        'description' => [
                            'type' => 'string',
                            'description' => 'Item description.',
                        ],
                        'priority' => [
                            'type' => 'string',
                            'enum' => ['low', 'normal', 'high', 'urgent'],
                            'description' => 'Item priority. Defaults to "normal".',
                        ],
                        'epic_id' => [
                            'type' => 'string',
                            'description' => 'Optional epic ID to add item to.',
                        ],
                    ],
                    'required' => ['project_id', 'title'],
                ],
            ],
            [
                'name' => 'update_item',
                'description' => 'Update a work item\'s state, priority, or details.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'item_id' => [
                            'type' => 'string',
                            'description' => 'Item ID to update.',
                        ],
                        'state' => [
                            'type' => 'string',
                            'enum' => ['open', 'in_progress', 'review', 'blocked', 'done', 'cancelled'],
                            'description' => 'New state for the item.',
                        ],
                        'priority' => [
                            'type' => 'string',
                            'enum' => ['low', 'normal', 'high', 'urgent'],
                            'description' => 'New priority.',
                        ],
                        'title' => [
                            'type' => 'string',
                            'description' => 'New title.',
                        ],
                        'description' => [
                            'type' => 'string',
                            'description' => 'New description.',
                        ],
                    ],
                    'required' => ['item_id'],
                ],
            ],
            [
                'name' => 'handoff_agent',
                'description' => 'Suggest switching to a different agent that is better suited for the user\'s request. Use this when the request falls outside your expertise.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [
                        'agent_slug' => [
                            'type' => 'string',
                            'description' => 'The slug of the agent to suggest (e.g. "pm", "project", "architect").',
                        ],
                        'reason' => [
                            'type' => 'string',
                            'description' => 'Why this agent is better suited for the request.',
                        ],
                    ],
                    'required' => ['agent_slug'],
                ],
            ],
        ];
    }
}
