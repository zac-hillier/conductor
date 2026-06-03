#!/usr/bin/env bash
#
# Conductor database backup — gzipped pg_dump of the Conductor Postgres DB.
# Runs hourly via conductor-backup.timer, and can be run ad-hoc before any risky
# operation:   deploy/backup/conductor-db-backup.sh pre-op
#
# Restore:     gunzip -c <file>.sql.gz | docker exec -i conductor-postgres \
#                  psql -U conductor -d conductor
#
set -euo pipefail

APP_DIR="${CONDUCTOR_DIR:-/home/zac/conductor}"
BACKUP_DIR="${CONDUCTOR_BACKUP_DIR:-/home/zac/backups/conductor}"
RETENTION_DAYS="${CONDUCTOR_BACKUP_RETENTION_DAYS:-14}"
CONTAINER="${CONDUCTOR_PG_CONTAINER:-conductor-postgres}"
LABEL="${1:-auto}"

env_get() { grep -E "^$1=" "$APP_DIR/.env" | head -1 | cut -d= -f2-; }
DB_USER="$(env_get DB_USERNAME)"
DB_NAME="$(env_get DB_DATABASE)"
DB_PASS="$(env_get DB_PASSWORD)"

if [ -z "$DB_USER" ] || [ -z "$DB_NAME" ]; then
    echo "conductor-db-backup: could not read DB creds from $APP_DIR/.env" >&2
    exit 1
fi

mkdir -p "$BACKUP_DIR"
TS="$(date +%Y%m%d-%H%M%S)"
OUT="$BACKUP_DIR/conductor-${TS}-${LABEL}.sql.gz"

docker exec -e PGPASSWORD="$DB_PASS" "$CONTAINER" \
    pg_dump -U "$DB_USER" -d "$DB_NAME" --clean --if-exists \
    | gzip >"$OUT"

if [ ! -s "$OUT" ]; then
    echo "conductor-db-backup: dump was empty, removing $OUT" >&2
    rm -f "$OUT"
    exit 1
fi

# Prune backups older than the retention window.
find "$BACKUP_DIR" -name 'conductor-*.sql.gz' -mtime +"$RETENTION_DAYS" -delete 2>/dev/null || true

echo "conductor-db-backup: wrote $OUT ($(du -h "$OUT" | cut -f1)); $(find "$BACKUP_DIR" -name 'conductor-*.sql.gz' | wc -l) kept"
