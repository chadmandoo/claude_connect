# Claude Connect - Local Setup Guide (macOS)

This guide covers setting up Claude Connect on macOS for local development/use.

## Prerequisites

- **PHP 8.3** with extensions: redis, curl, mbstring, xml, zip, bcmath
- **Swoole 6.0+** PHP extension
- **Composer** (PHP package manager)
- **Docker** (for Redis)
- **Claude CLI** installed and authenticated

## Step 1: Install PHP 8.3

```bash
brew install php@8.3
```

Verify:
```bash
php -v   # Should show PHP 8.3.x
```

## Step 2: Install Swoole Extension

```bash
pecl install swoole
```

When prompted, enable:
- OpenSSL: yes
- cURL: yes
- HTTP/2: yes

Verify:
```bash
php -m | grep swoole
```

## Step 3: Install Redis Extension

```bash
pecl install redis
```

Verify:
```bash
php -m | grep redis
```

## Step 4: Start Redis Container

```bash
cd /Users/chadpeppers/Projects/claude_connect
docker compose up -d
```

This starts Redis on port **6380** with AOF persistence.

> **Note:** The docker-compose.yml references an external `proxy` network. If you don't have it, either create it (`docker network create proxy`) or remove the `networks` section from docker-compose.yml for local use.

## Step 5: Install PHP Dependencies

```bash
composer install
```

## Step 6: Configure Environment

```bash
cp .env.example .env
```

Edit `.env` and update:
```env
# Point to your local Claude CLI path
CLAUDE_CLI_PATH=/Users/chadpeppers/.claude/local/claude
# Or wherever your claude binary is:
# which claude

# Set a web auth password (or leave empty for no auth)
WEB_AUTH_PASSWORD=your_password_here

# Optional: Voyage AI key for vector embeddings
VOYAGE_API_KEY=your_key_here

# Optional: Anthropic API key for direct chat mode
ANTHROPIC_API_KEY=your_key_here
CHAT_ENABLED=false
```

## Step 7: Create Runtime Directory

```bash
mkdir -p runtime
chmod 755 runtime
```

## Step 8: Start the Server

```bash
php bin/hyperf.php start
```

The server will be available at:
- **Web UI:** http://localhost:9501
- **WebSocket:** ws://localhost:9501
- **Health:** http://localhost:9501/health

## Configuration Reference

All configuration lives in `config/autoload/mcp.php`. Key environment variables:

### Server
| Variable | Default | Description |
|----------|---------|-------------|
| `SERVER_PORT` | 9501 | HTTP/WebSocket port |
| `WORKER_NUM` | 4 | Swoole worker processes |

### Claude CLI
| Variable | Default | Description |
|----------|---------|-------------|
| `CLAUDE_CLI_PATH` | `/home/cpeppers/.local/bin/claude` | Path to Claude CLI binary |
| `CLAUDE_MAX_TURNS` | 25 | Default max agentic turns |
| `CLAUDE_MAX_BUDGET_USD` | 5.00 | Default budget per task |
| `CLAUDE_ALLOWED_TOOLS` | `Bash,Read,Write,Edit,Glob,Grep,WebSearch,WebFetch` | Allowed CLI tools |

### Storage
| Variable | Default | Description |
|----------|---------|-------------|
| `REDIS_HOST` | 127.0.0.1 | Redis host |
| `REDIS_PORT` | 6380 | Redis port |

### Web
| Variable | Default | Description |
|----------|---------|-------------|
| `WEB_AUTH_PASSWORD` | (empty) | Auth password (empty = no auth) |
| `WEB_USER_ID` | web_user | User ID for auth |

### Embeddings
| Variable | Default | Description |
|----------|---------|-------------|
| `VOYAGE_API_KEY` | (empty) | Voyage AI API key |
| `VOYAGE_MODEL` | voyage-3.5-lite | Embedding model |
| `VOYAGE_DIMENSIONS` | 1024 | Vector dimensions |

### Chat API (Optional)
| Variable | Default | Description |
|----------|---------|-------------|
| `ANTHROPIC_API_KEY` | (empty) | Anthropic API key |
| `CHAT_ENABLED` | false | Enable direct API chat mode |
| `CHAT_MODEL` | claude-sonnet-4-20250514 | Chat model |

### Background Agents
| Variable | Default | Description |
|----------|---------|-------------|
| `SUPERVISOR_ENABLED` | false | Enable background agent supervisor |
| `SUPERVISOR_MAX_PARALLEL` | 2 | Max parallel background agents |
| `NIGHTLY_ENABLED` | true | Enable nightly consolidation |
| `NIGHTLY_RUN_HOUR` | 2 | Nightly run hour (2 AM) |
| `CLEANUP_ENABLED` | true | Enable periodic cleanup |

## Useful Commands

```bash
# Memory management
php bin/hyperf.php memory:list
php bin/hyperf.php memory:set <key> <value>
php bin/hyperf.php memory:backfill

# Project management
php bin/hyperf.php project:list
php bin/hyperf.php project:create <name>

# Skills/MCP
php bin/hyperf.php skill:list
php bin/hyperf.php skill:add <name> <command>
php bin/hyperf.php skill:remove <name>

# Maintenance
php bin/hyperf.php nightly:run
php bin/hyperf.php cleanup:run

# Run tests
vendor/bin/phpunit
```

## Troubleshooting

### "ext-swoole required"
Install Swoole: `pecl install swoole`

### Redis connection refused
Make sure Docker is running and the Redis container is up:
```bash
docker compose up -d
docker ps | grep redis
```

### Claude CLI not found
Update `CLAUDE_CLI_PATH` in `.env` to match your local installation:
```bash
which claude
```

### Port already in use
Change `SERVER_PORT` in `.env` or kill the existing process:
```bash
lsof -i :9501
```
