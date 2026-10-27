#!/bin/sh
# Opt-in; creates/destroys ONLY its newly named localhost PostgreSQL test DB.
set -eu
umask 077
ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$ROOT/backend"
EVIDENCE=$(mktemp -d "/tmp/expo_phase4_evidence.XXXXXXXX")
PGNAME=""
cleanup() {
    if [ -n "$PGNAME" ]; then
        dropdb -h /tmp -p 5432 -U "$(id -un)" "$PGNAME" >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT HUP INT TERM

APP_ENV_OUT=$(php artisan env --no-ansi 2>&1)
case "$APP_ENV_OUT" in
    *production*) echo "REFUSED: no rehearsal against a production runtime." >&2; exit 2 ;;
esac
if [ -n "$(printenv DB_URL 2>/dev/null || true)" ]; then
    echo "REFUSED: unset DB_URL before isolated rehearsal." >&2; exit 2
fi
for executable in createdb dropdb php python3 git openssl; do
    if ! command -v "$executable" >/dev/null 2>&1; then
        echo "REFUSED: missing tool ($executable)." >&2; exit 2
    fi
done

printf 'Private test evidence: %s\n' "$EVIDENCE"
git status --porcelain > "$EVIDENCE/git-status.txt"
git diff --check > "$EVIDENCE/whitespace.txt"
php artisan route:cache > "$EVIDENCE/route-cache.txt" 2>&1
php artisan route:clear >> "$EVIDENCE/route-cache.txt" 2>&1
php artisan view:cache > "$EVIDENCE/blade.txt" 2>&1
php artisan expo:build-fingerprint --json > "$EVIDENCE/frontend-build.json" 2>&1 || {
    cat "$EVIDENCE/frontend-build.json"
    echo 'Frontend production build is missing or contains unsafe files.' >&2
    exit 1
}
find app config routes database/migrations tests -type f -name '*.php' -print0 |
    xargs -0 -n 1 php -l > "$EVIDENCE/php-syntax.txt"
if grep -E 'Parse error|Errors parsing' "$EVIDENCE/php-syntax.txt"; then
    echo "PHP syntax check failed." >&2; exit 1
fi

printf 'Running isolated SQLite regression.\n'
DB_CONNECTION=sqlite DB_DATABASE=':memory:' DB_URL='' \
    php artisan test --compact > "$EVIDENCE/sqlite-tests.txt" 2>&1 || {
        tail -n 30 "$EVIDENCE/sqlite-tests.txt"; exit 1
    }
printf 'Creating disposable localhost PostgreSQL database.\n'
PGNAME="expo_phase4_stage_$(openssl rand -hex 5)"
createdb -h /tmp -p 5432 -U "$(id -un)" "$PGNAME"
printf 'Running isolated PostgreSQL regression.\n'
(
    export DB_CONNECTION=pgsql DB_HOST=/tmp DB_PORT=5432
    export DB_USERNAME="$(id -un)" DB_DATABASE="$PGNAME"
    export DB_URL='' DB_SSLMODE=prefer DB_PASSWORD=''
    php artisan test --compact > "$EVIDENCE/pgsql-tests.txt" 2>&1
) || {
    tail -n 40 "$EVIDENCE/pgsql-tests.txt"; exit 1
}

# A developer test is never equivalent to a real production preflight.
php artisan expo:preflight --json > "$EVIDENCE/preflight.json" 2>&1 || true
export EXPO_REHEARSAL_EVIDENCE_DIR="$EVIDENCE"
python3 - <<'PY'
import datetime
import hashlib
import json
import os
from pathlib import Path

base = Path(os.environ["EXPO_REHEARSAL_EVIDENCE_DIR"])
names = ["git-status.txt","whitespace.txt","route-cache.txt","blade.txt",
         "frontend-build.json","php-syntax.txt","sqlite-tests.txt",
         "pgsql-tests.txt","preflight.json"]
files = {}
for name in names:
    files[name] = hashlib.sha256((base / name).read_bytes()).hexdigest()
manifest = {
    "purpose": "isolated-staging-rehearsal",
    "generated_at": datetime.datetime.now(datetime.timezone.utc).isoformat(),
    "test_suites": "SQLite + disposable localhost PostgreSQL",
    "git_worktree_clean": not (base / "git-status.txt").read_text().strip(),
    "observed_frontend_build_sha256": json.loads(
        (base / "frontend-build.json").read_text())["sha256"],
    "frontend_build_independently_approved": False,
    "production_release_approved": False,
    "production_connections_tested": False,
    "statutory_integrations_tested": False,
    "zebra_hardware_tested": False,
    "production_data_cutover_rehearsed": False,
    "sha256_by_file": files,
}
(base / "manifest.json").write_text(json.dumps(manifest, indent=2) + "\n")
print("Rehearsal evidence:", base / "manifest.json")
print("Automated tests are complete; external acceptance remains separate.")
PY
printf 'SQLite: '; grep 'Tests:' "$EVIDENCE/sqlite-tests.txt" | tail -n 1
printf 'PostgreSQL: '; grep 'Tests:' "$EVIDENCE/pgsql-tests.txt" | tail -n 1
printf 'Disposable PostgreSQL test database will now be removed.\n'
