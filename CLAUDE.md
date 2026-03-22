# Claude Connect

## Project Overview
Web-first project-based agent system built with PHP 8.3 + Swoole 6.0 + Hyperf 3.1 that orchestrates Claude CLI tasks locally.

## Architecture
- **Runtime**: Swoole coroutine HTTP server on port 9501
- **Framework**: Hyperf 3.1
- **Storage**: Redis (port 6380, Docker) + Swoole Table (in-memory cache)
- **Execution**: Claude CLI runs locally via proc_open
- **Frontend**: WebSocket-based real-time chat interface

## Key Directories
- `app/Controller/` - HTTP request handlers (health, web frontend)
- `app/StateMachine/` - Task state management (PENDING -> RUNNING -> COMPLETED/FAILED)
- `app/Claude/` - Claude CLI process management
- `app/Storage/` - Redis and Swoole Table storage
- `app/Web/` - WebSocket handler, chat manager, auth
- `app/Agent/` - Smart routing and prompt composition
- `app/Conversation/` - Multi-turn conversation management
- `app/Project/` - Project workspaces and orchestration
- `app/Epic/` - Epic management
- `app/Item/` - Work item tracking
- `app/Memory/` - Structured memory system
- `app/Pipeline/` - Post-task processing pipeline
- `config/` - Hyperf configuration files

## Commands
- `php bin/hyperf.php start` - Start the server
- `composer install` - Install dependencies
- `sudo systemctl restart claude-connect` - Restart the service

## Endpoints
- GET `http://localhost:9501/health` - Health check
- GET `http://localhost:9501/` - Web frontend
- WebSocket `ws://localhost:9501/` - Real-time chat

## Important Notes
- Claude CLI executes locally via proc_open with stream_select for concurrent pipe reading
- Process timeout configurable via CLAUDE_PROCESS_TIMEOUT env var (default 0 = no timeout)
- Redis runs in Docker on port 6380 (not 6379, to avoid conflicts with forge DDEV)
- Swoole Tables must be created before server forks workers (in SwooleTableCache constructor)
- proc_open is hooked by SWOOLE_HOOK_ALL for async I/O
