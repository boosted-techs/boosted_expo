#!/bin/sh
# Read-only production checks; never deploys or applies migrations.
set -eu
ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$ROOT/backend"
if [ "$#" -ne 2 ]; then
    echo 'Usage: phase4_predeploy_check.sh BUSINESS_ID ARTIFACT_SHA256' >&2
    exit 2
fi
case "$1" in
    ''|*[!0-9]*) echo 'Business ID must be numeric.' >&2; exit 2 ;;
esac
if ! printf '%s' "$2" | grep -Eq '^[a-f0-9]{64}$'; then
    echo 'Artifact SHA-256 must be 64 lowercase hex characters.' >&2
    exit 2
fi
php artisan expo:release-gate "$1" --sha="$2" --live --json
