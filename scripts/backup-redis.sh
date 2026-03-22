#!/usr/bin/env bash
# Daily Redis backup for Claude Connect
# Triggers BGSAVE, copies the RDB + AOF, retains last 7 days

set -euo pipefail

BACKUP_DIR="/srv/backups/redis"
CONTAINER="claude-connect-redis"
DATE=$(date +%Y%m%d-%H%M%S)
RETENTION_DAYS=7

echo "[$(date)] Starting Redis backup..."

# Trigger a background save
docker exec "$CONTAINER" redis-cli -p 6379 BGSAVE
sleep 2

# Wait for BGSAVE to complete (max 60s)
for i in $(seq 1 30); do
    STATUS=$(docker exec "$CONTAINER" redis-cli -p 6379 LASTSAVE)
    sleep 2
    NEW_STATUS=$(docker exec "$CONTAINER" redis-cli -p 6379 LASTSAVE)
    if [ "$STATUS" = "$NEW_STATUS" ] && [ "$i" -gt 1 ]; then
        break
    fi
done

# Create dated backup directory
DEST="$BACKUP_DIR/$DATE"
mkdir -p "$DEST"

# Copy RDB
docker cp "$CONTAINER:/data/dump.rdb" "$DEST/dump.rdb"

# Copy AOF files
docker cp "$CONTAINER:/data/appendonlydir/" "$DEST/appendonlydir/"

# Compress
tar -czf "$BACKUP_DIR/redis-backup-$DATE.tar.gz" -C "$BACKUP_DIR" "$DATE"
rm -rf "$DEST"

# Prune old backups
find "$BACKUP_DIR" -name "redis-backup-*.tar.gz" -mtime +$RETENTION_DAYS -delete

SIZE=$(du -h "$BACKUP_DIR/redis-backup-$DATE.tar.gz" | cut -f1)
echo "[$(date)] Backup complete: redis-backup-$DATE.tar.gz ($SIZE), retained ${RETENTION_DAYS} days"
