#!/usr/bin/env bash
# Temporary PostgreSQL lock and uniqueness smoke test.
# Requires a local/test PostgreSQL cluster where createdb/dropdb are allowed.
# NEVER points at or modifies the existing ERP database.
set -euo pipefail
DBNAME="expo_phase1_lock_$(openssl rand -hex 5)"
WORK=$(mktemp -d /tmp/expo_pg_smoke.XXXXXX)
cleanup() {
  dropdb "$DBNAME" >/dev/null 2>&1 || true
  rm -rf "$WORK"
}
trap cleanup EXIT
createdb "$DBNAME"
psql -d "$DBNAME" -q -v ON_ERROR_STOP=1 <<'SQL'
CREATE TABLE claim_gate (
  business_id bigint NOT NULL,
  id bigint NOT NULL,
  request_key text NOT NULL,
  quantity numeric(14,3) NOT NULL,
  PRIMARY KEY (business_id,id),
  UNIQUE (business_id,request_key)
);
INSERT INTO claim_gate VALUES (1,1,'carton-test',100.000);
SQL
# An operation holding the row lock must prevent a simultaneous allocator
# from silently writing the same business/stock source row.
psql -d "$DBNAME" -q -v ON_ERROR_STOP=1 >"$WORK/holder.log" <<'SQL' &
BEGIN;
SELECT id FROM claim_gate WHERE business_id=1 AND id=1 FOR UPDATE;
SELECT pg_sleep(2);
COMMIT;
SQL
HOLDER=$!
sleep 0.5
if psql -d "$DBNAME" -q -v ON_ERROR_STOP=1 >"$WORK/contender.log" 2>&1 <<'SQL'
BEGIN;
SET LOCAL lock_timeout = '400ms';
UPDATE claim_gate SET quantity=quantity-10 WHERE business_id=1 AND id=1;
COMMIT;
SQL
then
  echo "FAIL: concurrent update bypassed held row lock."
  wait "$HOLDER" || true
  exit 1
fi
if ! grep -q "lock timeout" "$WORK/contender.log"; then
  echo "FAIL: concurrency rejection was not caused by PostgreSQL lock enforcement."
  wait "$HOLDER" || true
  exit 1
fi
wait "$HOLDER"
VALUE=$(psql -d "$DBNAME" -Atc "SELECT quantity FROM claim_gate WHERE business_id=1 AND id=1")
if [ "$VALUE" != "100.000" ]; then
  echo "FAIL: protected quantity changed during rejected concurrent transaction."
  exit 1
fi
# A database-level composite unique constraint stops repeated request keys.
if psql -d "$DBNAME" -q -v ON_ERROR_STOP=1 \
  -c "INSERT INTO claim_gate VALUES (1,2,'carton-test',100.000)" \
  >"$WORK/duplicate.log" 2>&1; then
  echo "FAIL: duplicate request key was accepted."
  exit 1
fi
psql -d "$DBNAME" -q -v ON_ERROR_STOP=1 \
  -c "INSERT INTO claim_gate VALUES (2,2,'carton-test',100.000)"
ROWS=$(psql -d "$DBNAME" -Atc "SELECT count(*) FROM claim_gate")
if [ "$ROWS" != "2" ]; then
  echo "FAIL: tenant-scoped uniqueness isolation was not retained."
  exit 1
fi
echo "PASS: independent PostgreSQL transactions enforce row locks, duplicate keys and tenant-scoped uniqueness."
echo "LIMITATION: this is a database primitive smoke test, not business-scale simultaneous checkout/packing load certification."
