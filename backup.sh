#!/usr/bin/env bash
# =============================================================================
# backup.sh — Dump the MySQL database to backups/<timestamp>.sql.gz
# =============================================================================
set -euo pipefail

BACKUP_DIR="./backups"
TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
BACKUP_FILE="${BACKUP_DIR}/klaklork_${TIMESTAMP}.sql.gz"

mkdir -p "$BACKUP_DIR"

echo "==> Creating database backup: ${BACKUP_FILE}"

docker compose exec -T db mysqldump \
    -u root \
    -p"${DB_ROOT_PASSWORD:-rootsecret}" \
    "${DB_DATABASE:-klaklork}" \
    | gzip > "$BACKUP_FILE"

echo "==> Backup complete: ${BACKUP_FILE} ($(du -h "$BACKUP_FILE" | cut -f1))"

# Keep only the last 10 backups
cd "$BACKUP_DIR"
ls -1t klaklork_*.sql.gz 2>/dev/null | tail -n +11 | xargs -r rm --
echo "==> Old backups pruned (keeping last 10)."
