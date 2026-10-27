#!/bin/sh
# Create a committed immutable source archive; no deployment or money actions.
set -eu
umask 077
ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/../.." && pwd)
cd "$ROOT"
if [ -n "$(git status --porcelain)" ]; then
    echo 'BLOCKED: packaging requires a reviewed clean committed tree.' >&2
    exit 2
fi
if ! git rev-parse --verify HEAD >/dev/null 2>&1; then
    echo 'BLOCKED: no committed release revision.' >&2; exit 2
fi
if git ls-files | grep -E '(^|/)\.env$|(^|/)database\.sqlite$|\.(pem|p12|key|p8)$'; then
    echo 'BLOCKED: release includes private secrets or database filenames.' >&2
    exit 2
fi
OUT=$(mktemp -d "/tmp/expo_phase4_candidate.XXXXXXXX")
TAR="$OUT/boosted-export-erp-source.tar"
git archive --format=tar --prefix=boosted-export-erp/ HEAD > "$TAR"
gzip -n -9 "$TAR"
ARCHIVE="$TAR.gz"
HASH=$(shasum -a 256 "$ARCHIVE" | awk '{print $1}')
printf 'Committed revision: %s\n' "$(git rev-parse HEAD)"
printf 'Private archive: %s\n' "$ARCHIVE"
printf 'Artifact SHA-256: %s\n' "$HASH"
printf 'NOT DEPLOYED. Bind the SHA-256 to approved staging evidence.\n'
