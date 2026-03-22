#!/bin/bash
# Keeps task-worker.php running with auto-restart on crash.
# Usage: ./bin/task-worker-daemon.sh

DIR="$(cd "$(dirname "$0")/.." && pwd)"
LOG="$DIR/runtime/task-worker.log"
PHP="${PHP_BIN:-/opt/homebrew/bin/php}"

mkdir -p "$DIR/runtime"

echo "[daemon] Task worker daemon starting in $DIR" >> "$LOG"

while true; do
    echo "[daemon] Starting worker at $(date)" >> "$LOG"
    "$PHP" "$DIR/bin/task-worker.php" >> "$LOG" 2>&1
    EXIT_CODE=$?
    echo "[daemon] Worker exited with code $EXIT_CODE at $(date)" >> "$LOG"

    if [ $EXIT_CODE -eq 0 ]; then
        echo "[daemon] Clean exit, restarting in 5s..." >> "$LOG"
    else
        echo "[daemon] Crash detected, restarting in 3s..." >> "$LOG"
    fi

    sleep 3
done
