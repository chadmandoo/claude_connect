# Claude Connect - Project Overview

Claude Connect is a web-first, project-based personal assistant system built with PHP 8.3 and Swoole 6.0 that orchestrates Claude CLI tasks as a state machine. It provides async task execution with streaming progress, WebSocket real-time chat, session continuity, intelligent message routing, and project workspace management.

## Tech Stack

| Component | Technology |
|-----------|-----------|
| Language | PHP 8.3 (strict mode, typed) |
| Framework | Hyperf 3.1 |
| Async Runtime | Swoole 6.0 (coroutines, 100k per worker) |
| Storage | Redis 7 (Docker, AOF persistence) |
| In-Memory Cache | Swoole Table (shared memory) |
| Server | Swoole WebSocket Server (port 9501) |
| Embeddings | Voyage AI (voyage-3.5-lite, 1024 dims) |
| AI Backend | Claude CLI (proc_open) + Anthropic API (optional) |
| Testing | PHPUnit 10.5 + Mockery 1.6 |

## Key Capabilities

- **Real-time Chat** - WebSocket-based browser interface with live streaming of Claude CLI output
- **Multi-Agent Routing** - Haiku-based smart message classification routes to PM, project-specific, or general agents
- **Project Workspaces** - Isolated workspaces with epics, work items, and budget tracking
- **Persistent Memory** - Structured memory system with vector embeddings and hybrid relevance ranking (70% vector / 30% keyword)
- **Autonomous Agents** - Background task execution via AgentSupervisor with stall detection and retries
- **Post-Task Pipeline** - Pluggable stages for memory extraction, conversation tracking, embedding, and project detection
- **Nightly Consolidation** - Scheduled memory validation, deduplication, and summarization
- **MCP Integration** - Model Context Protocol proxy with skill registry (builtin/global/user scopes)
- **Budget Control** - Per-task, per-project, and nightly spending limits
- **Work Item Automation** - ItemAgent auto-processes items assigned to 'agent'

## Directory Structure

```
claude_connect/
├── app/                        # Application source (PSR-4: App\)
│   ├── Agent/                  # Message routing & prompt composition
│   ├── Chat/                   # Anthropic API integration & tool handling
│   ├── Claude/                 # CLI process management & output parsing
│   ├── Cleanup/                # Automated data retention & cleanup
│   ├── Command/                # 14 CLI commands
│   ├── Controller/             # HTTP endpoints (health, web, auth)
│   ├── Conversation/           # Multi-turn conversation management
│   ├── Embedding/              # Voyage AI vector embeddings
│   ├── Epic/                   # Epic grouping for work items
│   ├── Item/                   # Work item state management
│   ├── Listener/               # Event-driven agent triggers (8 listeners)
│   ├── Memory/                 # Structured memory storage & analytics
│   ├── Nightly/                # Scheduled consolidation agent
│   ├── Pipeline/               # Post-task processing (5 stages)
│   ├── Project/                # Project lifecycle & orchestration
│   ├── Prompts/                # Prompt file loader
│   ├── Skills/                 # MCP skill registry & config generation
│   ├── StateMachine/           # Task state transitions
│   ├── Storage/                # Redis + Swoole Table persistence
│   ├── Web/                    # WebSocket handler, auth, chat manager
│   └── Workflow/               # Template resolver & item agents
├── bin/                        # Entry points (hyperf.php, cc-system-mcp.php)
├── config/                     # Hyperf configuration (server, redis, DI, routes)
├── prompts/                    # System prompt templates (11 categories)
├── public/                     # Web frontend (terminal-style UI)
├── runtime/                    # Logs, PID, cache
├── scripts/                    # Backup scripts
├── tests/                      # PHPUnit test suite
├── docker-compose.yml          # Redis container
├── claude-connect.service      # systemd service file
└── setup.sh                    # Automated deployment script
```

## Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/health` | Health check |
| GET | `/` | Web frontend (terminal UI) |
| GET | `/assets/{file}` | Static assets (JS, CSS) |
| POST | `/api/auth` | Password authentication |
| WS | `/` | WebSocket real-time chat |

## State Machines

### Task States
```
PENDING → RUNNING → COMPLETED
                  → FAILED → PENDING (retry)
```

### Project States
```
PLANNING → ACTIVE → PAUSED → COMPLETED
                  → STALLED
                            → CANCELLED
```

### Work Item States
```
OPEN → IN_PROGRESS → REVIEW → DONE
                   → BLOCKED → IN_PROGRESS
                             → CANCELLED
```

## Workflow Templates

| Template | Turns | Budget | Use Case |
|----------|-------|--------|----------|
| quick | 5 | $0.50 | Simple questions |
| standard | 35 | $5.00 | Normal tasks |
| deep | 75 | $10.00 | Build/implement |
| browse | 10 | $1.00 | Screenshot/navigate |
