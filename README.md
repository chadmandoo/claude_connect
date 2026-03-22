# Claude Connect

> **WARNING: This project runs Claude CLI with `--dangerously-skip-permissions`.** That means Claude can execute arbitrary commands, read/write any file, and do pretty much whatever it wants on your machine without asking first. If you're not comfortable with that, this project is not for you. If you like to YOLO, welcome aboard.

A web-first project-based agent system built with PHP 8.3 + Swoole 6.0 + Hyperf 3.1 that orchestrates Claude CLI tasks locally. It gives you a real-time WebSocket chat interface in your browser that manages Claude CLI processes as background tasks with streaming output, persistent memory, multi-agent routing, and project workspaces.

## What It Does

- **Real-time Chat UI** — Browser-based terminal-style interface with live streaming of Claude CLI output over WebSocket
- **Multi-Agent Routing** — Haiku-based message classification routes to PM, project-specific, or general agents
- **Project Workspaces** — Isolated workspaces with epics, work items, and budget tracking
- **Persistent Memory** — Structured memory system with vector embeddings (Voyage AI) and hybrid relevance ranking
- **Autonomous Agents** — Background task execution with stall detection and retries
- **Post-Task Pipeline** — Pluggable stages for memory extraction, conversation tracking, and project detection
- **Nightly Consolidation** — Scheduled memory validation, deduplication, and summarization
- **MCP Integration** — Model Context Protocol proxy with skill registry
- **Budget Control** — Per-task, per-project, and nightly spending limits

## Requirements

- PHP 8.3+
- [Swoole 6.0+](https://openswoole.com/) PHP extension
- [Claude CLI](https://docs.anthropic.com/en/docs/claude-cli) installed and authenticated
- Docker (for Redis + PostgreSQL)
- Composer

## Quick Start

```bash
# Clone
git clone git@github.com:chadmandoo/claude_connect.git
cd claude_connect

# Install dependencies
composer install

# Configure
cp .env.example .env
# Edit .env — at minimum set CLAUDE_CLI_PATH to your `which claude` path

# Start Redis + PostgreSQL
docker compose up -d

# Start the server
php bin/hyperf.php start
```

Then open [http://localhost:9501](http://localhost:9501).

## Configuration

All config lives in `.env`. Key variables:

| Variable | Default | Description |
|----------|---------|-------------|
| `CLAUDE_CLI_PATH` | — | Path to Claude CLI binary (`which claude`) |
| `CLAUDE_MAX_TURNS` | `25` | Max agentic turns per task |
| `CLAUDE_MAX_BUDGET_USD` | `5.00` | Max budget per task |
| `SERVER_PORT` | `9501` | HTTP/WebSocket port |
| `WORKER_NUM` | `4` | Swoole worker processes |
| `REDIS_PORT` | `6380` | Redis port (Docker maps 6380 -> 6379) |
| `WEB_AUTH_PASSWORD` | (empty) | Web UI password (empty = no auth) |
| `VOYAGE_API_KEY` | (empty) | Voyage AI key for vector embeddings |
| `ANTHROPIC_API_KEY` | (empty) | Anthropic API key for direct chat mode |

See `.env.example` for the full list.

## Architecture

```
Browser ──WebSocket──▶ Swoole HTTP Server (port 9501)
                            │
                            ├── ChatManager ──▶ Router (Haiku classifier)
                            │                       │
                            │                       ├── PM Agent
                            │                       ├── Project Agent
                            │                       └── General Agent
                            │
                            ├── ProcessManager ──▶ Claude CLI (proc_open)
                            │                       └── --dangerously-skip-permissions
                            │
                            ├── PostTaskPipeline
                            │       ├── ExtractMemoryStage
                            │       ├── ExtractConversationStage
                            │       ├── EmbedTaskResultStage
                            │       ├── EmbedConversationStage
                            │       └── ProjectDetectionStage
                            │
                            └── Storage
                                    ├── Redis (persistence)
                                    ├── PostgreSQL (structured data)
                                    └── Swoole Table (in-memory cache)
```

## Task State Machine

```
PENDING ──▶ RUNNING ──▶ COMPLETED
                    └──▶ FAILED ──▶ PENDING (retry)
```

## Workflow Templates

| Template | Max Turns | Budget | Use Case |
|----------|-----------|--------|----------|
| `quick` | 5 | $0.50 | Simple questions |
| `standard` | 35 | $5.00 | Normal tasks |
| `deep` | 75 | $10.00 | Build/implement |
| `browse` | 10 | $1.00 | Screenshot/navigate |

## CLI Commands

```bash
# Server
php bin/hyperf.php start

# Memory
php bin/hyperf.php memory:list
php bin/hyperf.php memory:set <key> <value>
php bin/hyperf.php memory:backfill

# Projects
php bin/hyperf.php project:list
php bin/hyperf.php project:create <name>

# Skills/MCP
php bin/hyperf.php skill:list
php bin/hyperf.php skill:add <name> <command>
php bin/hyperf.php skill:remove <name>

# Agents
php bin/hyperf.php agent:list
php bin/hyperf.php agent:create <name>

# Maintenance
php bin/hyperf.php nightly:run
php bin/hyperf.php cleanup:run

# Tests
vendor/bin/phpunit
```

## Tech Stack

| Component | Technology |
|-----------|-----------|
| Language | PHP 8.3 |
| Framework | Hyperf 3.1 |
| Async Runtime | Swoole 6.0 |
| Storage | Redis 7 + PostgreSQL 16 |
| In-Memory Cache | Swoole Table |
| Embeddings | Voyage AI (voyage-3.5-lite) |
| AI Backend | Claude CLI (proc_open) |
| Testing | PHPUnit 10.5 + Mockery 1.6 |

## License

Do whatever you want with it. Just don't blame me when Claude decides to `rm -rf /` because you told it to "clean things up."
