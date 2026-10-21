#!/usr/bin/env bash
# Real PostgreSQL roundtrip; app/process commands are isolated doubles.
set -euo pipefail
REPO=$(cd "$(dirname "$0")/../.." && pwd)
TEST_ROOT=$(mktemp -d)
export TEST_ROOT
export APP_USER="$(id -un)" APP_GROUP="$(id -gn)"
export PGHOST=${PGHOST:-127.0.0.1} PGPORT=${PGPORT:-5432} PGUSER=${PGUSER:-postgres}
export PGDATABASE="backup_roundtrip_$$"
export REAL_PG_DUMP="$(command -v pg_dump)"
cleanup() {
    result=$?
    if [ "$result" -ne 0 ] && [ -f "$TEST_ROOT/result" ]; then cat "$TEST_ROOT/result"; fi
    dropdb --if-exists "$PGDATABASE"
    rm -rf "$TEST_ROOT"
}
trap cleanup EXIT
createdb "$PGDATABASE"
psql -v ON_ERROR_STOP=1 -c 'CREATE TABLE recovery_marker (value text NOT NULL); INSERT INTO recovery_marker VALUES ('"'original'"');' >/dev/null
mkdir -p "$TEST_ROOT/bin" "$TEST_ROOT/app/storage/app/private/signatures" "$TEST_ROOT/app/storage/framework" "$TEST_ROOT/backups"
touch "$TEST_ROOT/app/artisan"
printf 'APP_KEY=base64:fixture-key\n' > "$TEST_ROOT/app/.env"
printf original-signature > "$TEST_ROOT/app/storage/app/private/signatures/user.png"
printf unchanged-document > "$TEST_ROOT/app/storage/app/private/reference.txt"
echo RUNNING > "$TEST_ROOT/state"
echo RUNNING > "$TEST_ROOT/fpm-state"
cat > "$TEST_ROOT/bin/stub" <<'STUB'
#!/usr/bin/env bash
set -eu
call="$(basename "$0") $*"
printf '%s\n' "$call" >> "$TEST_ROOT/calls"
case "$call" in
    'systemctl show --property=KillSignal --value php8.3-fpm') echo "${FPM_KILL_SIGNAL:-SIGQUIT}" ;;
    'systemctl is-active --quiet php8.3-fpm') [ "$(cat "$TEST_ROOT/fpm-state")" = RUNNING ] ;;
    'systemctl stop php8.3-fpm') echo STOPPED > "$TEST_ROOT/fpm-state" ;;
    'systemctl start php8.3-fpm') echo RUNNING > "$TEST_ROOT/fpm-state" ;;
    'php artisan down --retry=30') touch "$APP_DIR/storage/framework/down" ;;
    'php artisan up') rm "$APP_DIR/storage/framework/down" ;;
    supervisorctl\ stop*) echo STOPPED > "$TEST_ROOT/state" ;;
    supervisorctl\ start*) echo RUNNING > "$TEST_ROOT/state" ;;
    supervisorctl\ status*)
        state=$(cat "$TEST_ROOT/state")
        echo "teg-queue-worker:teg-queue-worker_00 $state"
        echo "teg-deadlines-worker:teg-deadlines-worker_00 $state"
        [ "$state" = RUNNING ] || exit 3
        ;;
    git*) echo fixture-revision ;;
esac
STUB
for cmd in php supervisorctl systemctl git chown; do cp "$TEST_ROOT/bin/stub" "$TEST_ROOT/bin/$cmd"; chmod +x "$TEST_ROOT/bin/$cmd"; done
export PATH="$TEST_ROOT/bin:$PATH" OPS_CONFIG="$TEST_ROOT/absent.env"
export APP_DIR="$TEST_ROOT/app" BACKUP_ROOT="$TEST_ROOT/backups" OPS_LOCK="$TEST_ROOT/operation.lock"
bash "$REPO/scripts/backup.sh" > "$TEST_ROOT/result" 2>&1
snapshot=$(find "$BACKUP_ROOT" -mindepth 1 -maxdepth 1 -type d ! -name '.incomplete-*')
[ -n "$snapshot" ]
[ ! -f "$APP_DIR/storage/framework/down" ]
[ "$(cat "$TEST_ROOT/state")" = RUNNING ]
[ "$(cat "$TEST_ROOT/fpm-state")" = RUNNING ]
! find "$BACKUP_ROOT" -name '.incomplete-*' | grep -q .
(cd "$snapshot"; sha256sum --check SHA256SUMS)
[ "$(stat -c %i "$snapshot/private/signatures/user.png")" != "$(stat -c %i "$APP_DIR/storage/app/private/signatures/user.png")" ]

# Retention keeps seven verified daily copies, leaves unrelated/corrupt paths
# alone, and never removes the latest successful recovery point.
for day in $(seq 1 10); do
    old="$BACKUP_ROOT/$(printf '202601%02dT000000Z-seed' "$day")"
    mkdir "$old"
    cp -a "$snapshot/." "$old/"
done
mkdir "$BACKUP_ROOT/unrelated"
printf preserve > "$BACKUP_ROOT/unrelated/marker"
corrupt="$BACKUP_ROOT/20261231T000000Z-corrupt"
mkdir "$corrupt"
cp -a "$snapshot/." "$corrupt/"
printf corrupt > "$corrupt/private/signatures/user.png"
bash "$REPO/scripts/backup.sh" > "$TEST_ROOT/result" 2>&1
latest=$(sed -n 's/^Backup completed: //p' "$TEST_ROOT/result")
[ "$(stat -c %i "$snapshot/private/reference.txt")" = "$(stat -c %i "$latest/private/reference.txt")" ]
count=0
for candidate in "$BACKUP_ROOT"/*; do
    [ -f "$candidate/backup-kind.txt" ] || continue
    if (cd "$candidate"; sha256sum --check --status SHA256SUMS); then count=$((count+1)); fi
done
[ "$count" = 7 ]
[ "$(cat "$BACKUP_ROOT/unrelated/marker")" = preserve ]
[ -d "$corrupt" ]

# Same-size, same-timestamp edits still get a new backup inode and preserve history.
printf modified-signature > "$APP_DIR/storage/app/private/signatures/user.png"
touch -r "$snapshot/private/signatures/user.png" "$APP_DIR/storage/app/private/signatures/user.png"
bash "$REPO/scripts/backup.sh" > "$TEST_ROOT/result" 2>&1
changed_snapshot=$(sed -n 's/^Backup completed: //p' "$TEST_ROOT/result")
[ "$(cat "$snapshot/private/signatures/user.png")" = original-signature ]
[ "$(cat "$changed_snapshot/private/signatures/user.png")" = modified-signature ]
[ "$(stat -c %i "$snapshot/private/signatures/user.png")" != "$(stat -c %i "$changed_snapshot/private/signatures/user.png")" ]

ln -s "$TEST_ROOT/result" "$APP_DIR/storage/app/private/unsupported-link"
if bash "$REPO/scripts/backup.sh" > "$TEST_ROOT/result" 2>&1; then exit 1; fi
[ ! -f "$APP_DIR/storage/framework/down" ]
rm "$APP_DIR/storage/app/private/unsupported-link"

# A failed read-only dump must not leave the unchanged application offline.
printf '#!/usr/bin/env bash\nexit 7\n' > "$TEST_ROOT/bin/pg_dump"
chmod +x "$TEST_ROOT/bin/pg_dump"
if bash "$REPO/scripts/backup.sh" > "$TEST_ROOT/result" 2>&1; then exit 1; fi
[ ! -f "$APP_DIR/storage/framework/down" ]
[ "$(cat "$TEST_ROOT/state")" = RUNNING ]
[ "$(cat "$TEST_ROOT/fpm-state")" = RUNNING ]
grep -q 'site was not paused' "$TEST_ROOT/result"
rm "$TEST_ROOT/bin/pg_dump"
# Even filesystem mutations that restore the original bytes invalidate a dump.
cat > "$TEST_ROOT/bin/pg_dump" <<'DUMP'
#!/usr/bin/env bash
set -eu
if [ ! -f "$TEST_ROOT/mutated-once" ] || [ "${ALWAYS_MUTATE:-0}" = 1 ]; then
    touch "$TEST_ROOT/mutated-once"
    original=$(cat "$APP_DIR/storage/app/private/reference.txt")
    printf changed > "$APP_DIR/storage/app/private/reference.txt"
    printf '%s' "$original" > "$APP_DIR/storage/app/private/reference.txt"
fi
exec "$REAL_PG_DUMP" "$@"
DUMP
chmod +x "$TEST_ROOT/bin/pg_dump"
bash "$REPO/scripts/backup.sh" > "$TEST_ROOT/result" 2>&1
grep -q 'retrying without interrupting' "$TEST_ROOT/result"
[ -f "$(sed -n 's/^Backup completed: //p' "$TEST_ROOT/result")/SHA256SUMS" ]
export ALWAYS_MUTATE=1
before=$(find "$BACKUP_ROOT" -name SHA256SUMS | wc -l)
if bash "$REPO/scripts/backup.sh" > "$TEST_ROOT/result" 2>&1; then echo 'Mutating file tree accepted' >&2; exit 1; fi
[ "$(find "$BACKUP_ROOT" -name SHA256SUMS | wc -l)" = "$before" ]
grep -q 'No consistent snapshot was published' "$TEST_ROOT/result"
[ ! -f "$APP_DIR/storage/framework/down" ]
[ "$(cat "$TEST_ROOT/state")" = RUNNING ]
! grep -Eq '^php artisan down |^systemctl (stop|start) |^supervisorctl (stop|start)' "$TEST_ROOT/calls"
unset ALWAYS_MUTATE
rm "$TEST_ROOT/bin/pg_dump"
psql -v ON_ERROR_STOP=1 -c "UPDATE recovery_marker SET value='changed';" >/dev/null
printf changed > "$APP_DIR/storage/app/private/signatures/user.png"
printf later > "$APP_DIR/storage/app/private/later.txt"
# Reject destructive execution without explicit confirmation, wrong key or checksum.
if bash "$REPO/scripts/restore.sh" "$snapshot" > "$TEST_ROOT/result" 2>&1; then exit 1; fi
printf 'APP_KEY=wrong-key\n' > "$APP_DIR/.env"
if bash "$REPO/scripts/restore.sh" "$snapshot" --confirm-replace > "$TEST_ROOT/result" 2>&1; then exit 1; fi
[ ! -f "$APP_DIR/storage/framework/down" ]
cp "$snapshot/environment.env" "$APP_DIR/.env"
cp "$snapshot/revision.txt" "$TEST_ROOT/revision.txt"
printf corrupted > "$snapshot/revision.txt"
if bash "$REPO/scripts/restore.sh" "$snapshot" --confirm-replace > "$TEST_ROOT/result" 2>&1; then exit 1; fi
[ ! -f "$APP_DIR/storage/framework/down" ]
cp "$TEST_ROOT/revision.txt" "$snapshot/revision.txt"
bash "$REPO/scripts/restore.sh" "$snapshot" --confirm-replace > "$TEST_ROOT/result" 2>&1
[ "$(psql -At -c 'SELECT value FROM recovery_marker')" = original ]
[ "$(cat "$APP_DIR/storage/app/private/signatures/user.png")" = original-signature ]
[ "$(stat -c %i "$snapshot/private/signatures/user.png")" != "$(stat -c %i "$APP_DIR/storage/app/private/signatures/user.png")" ]
[ ! -f "$APP_DIR/storage/app/private/later.txt" ]
find "$APP_DIR/storage/app" -name later.txt | grep -q private.pre-restore
[ ! -f "$APP_DIR/storage/framework/down" ]
[ "$(cat "$TEST_ROOT/state")" = RUNNING ]

# Existing tar snapshots remain independently restorable.
legacy="$BACKUP_ROOT/20260201T000000Z-legacy"
mkdir "$legacy"
cp "$snapshot/database.dump" "$snapshot/environment.env" "$snapshot/revision.txt" "$snapshot/backup-kind.txt" "$legacy/"
tar -czf "$legacy/private.tar.gz" -C "$snapshot" private
(cd "$legacy"; sha256sum database.dump private.tar.gz environment.env revision.txt backup-kind.txt > SHA256SUMS)
printf modified-again > "$APP_DIR/storage/app/private/signatures/user.png"
bash "$REPO/scripts/restore.sh" "$legacy" --confirm-replace > "$TEST_ROOT/result" 2>&1
[ "$(cat "$APP_DIR/storage/app/private/signatures/user.png")" = original-signature ]
# A failing DB restore never reopens the app or starts workers.
printf '#!/usr/bin/env bash\nexit 7\n' > "$TEST_ROOT/bin/pg_restore"
chmod +x "$TEST_ROOT/bin/pg_restore"
if bash "$REPO/scripts/restore.sh" "$snapshot" --confirm-replace > "$TEST_ROOT/result" 2>&1; then exit 1; fi
[ -f "$APP_DIR/storage/framework/down" ]
[ "$(cat "$TEST_ROOT/state")" = STOPPED ]
[ "$(cat "$TEST_ROOT/fpm-state")" = STOPPED ]
[ "$(psql -At -c 'SELECT value FROM recovery_marker')" = original ]
echo 'Backup/restore: PostgreSQL + private files restored; invalid inputs and failure recovery verified.'
