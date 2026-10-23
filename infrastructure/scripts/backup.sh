#!/usr/bin/env sh
# Nightly logical backup of the Supabase/PostgreSQL database, independent of Supabase backups.
#   DB_URL=postgresql://... BACKUP_DIR=/backups RETENTION_DAYS=30 ./backup.sh
# Ship $BACKUP_DIR to encrypted off-site storage (e.g. rclone/aws s3 cp with SSE).
set -eu

: "${DB_URL:?DB_URL is required}"
BACKUP_DIR="${BACKUP_DIR:-/backups}"
RETENTION_DAYS="${RETENTION_DAYS:-30}"
STAMP="$(date -u +%Y%m%dT%H%M%SZ)"
FILE="$BACKUP_DIR/etijahfly-$STAMP.dump"

mkdir -p "$BACKUP_DIR"
pg_dump --format=custom --no-owner --no-privileges --file="$FILE" "$DB_URL"
pg_restore --list "$FILE" > /dev/null   # sanity check: archive is readable
sha256sum "$FILE" > "$FILE.sha256"

find "$BACKUP_DIR" -name 'etijahfly-*.dump*' -mtime +"$RETENTION_DAYS" -delete
echo "Backup written: $FILE"
