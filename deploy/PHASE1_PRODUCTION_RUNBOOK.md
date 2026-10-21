# Boosted Export ERP — Production Security & Infrastructure Runbook

Status: Phase 1 engineering has been implemented and locally tested. It has NOT been deployed. Do not consider the ERP production-ready until the release checks against the real hosting environment pass and an independent security acceptance is recorded. This ERP is separate from Boosted+ POS.

## Changes delivered

- Canonical HTTPS enforcement and restrictive trusted reverse-proxy allowlist. Security headers include anti-framing, nosniff, referrer and browser permissions rules, HSTS on secure production responses, no-store for authenticated pages, and a Content Security Policy without unsafe inline scripts.
- Privileged MFA in production for platform admins, business owners/admins, accountants and logistics staff; time-based authenticator enrolment, encrypted TOTP seed, hashed/encrypted single-use recovery codes, replay detection, session verification and rate limits.
- PostgreSQL-compatible schema migrations with explicit tenant-scoped composite unique constraints; verified on a disposable database and tested against PostgreSQL.
- Redis-backed cache, queue and sessions supported through Predis, including TLS certificate verification configuration; no Redis exposure to public networks.
- Independent encrypted database/private-file backups, self-authentication, tamper detection, offsite encrypted replication with read-back SHA256 verification, and non-destructive recovery staging.
- Production configuration preflight, private incident monitoring, schedules enabled only by explicit production variables, opt-in R2/mail transport tests.
- Production Nginx and queue worker Supervisor examples and pinned dependency lockfiles.

## Deployment readiness gate

Run the following in the real production backend, with secrets provisioned and correct runtime permissions:

    php artisan expo:preflight --live

It must return success. The command intentionally fails with the current local development settings: SQLite, debug mode, no real SMTP/R2, no offsite backup, and local HTTP. It never changes finance transactions or sends a test email.

Use backend/.env.production.example as the template for a new PRIVATE, server-specific environment; never replace the current local .env in development. Required external inputs include: production host and domain, working TLS certificate, PostgreSQL host/CA/role, private Redis with TLS and ACL, verified SMTP provider, two private R2 buckets and separate scoped access keys (product media and encrypted disaster recovery), secured backup key escrow, and independent incident alerts.

## 1. Hosting and TLS

1. Provision a dedicated host and real DNS with an HTTPS certificate, PHP 8.4+ and PHP-FPM, PostgreSQL 14+, Redis, PHP PDO-pgsql, sodium, ZIP, GD, intl, fileinfo and OpenSSL extensions, Composer, and Node.
2. Restrict PostgreSQL and Redis to private network routes. Configure least-privileged DB roles and protected Redis ACLs. Use PostgreSQL sslmode verify-full and DB_SSLROOTCERT issued for the hostname. Redis must use TLS and verify its own CA/hostname.
3. Use deploy/nginx/boosted-export-erp.conf.example. Only the backend/public directory is reachable through the web; deny source, .env, database, logs, vendor and Git access. Configure the real server_name, certificate paths, PHP-FPM socket, limits and host-specific security headers. Avoid duplicate/conflicting CSP and proxy response headers.
4. Set canonical APP_URL to the exact production HTTPS URL. Set APP_ENV=production, APP_DEBUG=false and a unique permanent APP_KEY. Do not rotate APP_KEY without a separately planned re-encryption and historic key-retention process.
5. TRUSTED_PROXIES must contain only real proxy server IPs/CIDRs you control. Never use a wildcard or trust a user-supplied Forwarded header. With Nginx directly to PHP-FPM, leaving TRUSTED_PROXIES empty is appropriate.
6. SESSION_SECURE_COOKIE=true, SESSION_HTTP_ONLY=true, SESSION_ENCRYPT=true and a defensible SameSite policy. Invalidate previous sessions on environment switch. Complete initial privileged-account MFA enrolment and audit a human-managed lost-MFA recovery policy.

## 2. Data cutover and Redis

Production PostgreSQL is a different database. Running migrations creates an empty schema: it does NOT migrate the existing Mac SQLite records.

Before an authorised migration, create and verify an encrypted copy of the existing SQLite records. Build a separate reviewed data-migration mapping preserving business_id, original IDs, foreign keys, receipts, financial journal entries, status histories, ciphertext and signatures. Import to a disposable PostgreSQL staging database first. Check every table row count, FK constraints, tenant isolation, sequence reset, selected hashes and key-dependent fields. Schedule a coordinated stop-writes/cutover window and compare the final source and destination. Never run migrate:fresh on the live SQLite data, and never overwrite a partially populated production database.

Production Redis uses the installed Predis client, separate Redis DB/prefix for cache and sessions and QUEUE_CONNECTION=redis. Configure a queue worker service from deploy/supervisor/boosted-export-erp.conf.example, plus a system cron or systemd scheduler running Laravel schedule:run once per minute. Monitor restarts and failed jobs.

## 3. SMTP and R2

Use a real SMTP/API transport and verified corporate sender identity. Validate domain SPF, DKIM and DMARC. Do not enable MAIL_MAILER=log for production. An explicitly authorised test mailbox can be checked with:

    php artisan expo:integration-smoke --mail=authorised@example.com --confirm-live

Transport acceptance is NOT proof of recipient inbox delivery: check inbox, bounces and provider logs.

For product photographs, create a private Cloudflare R2 bucket with only the required object permissions and scoped credentials. Do not make the whole bucket public. Tenant-authorised Laravel endpoints control image access. Test ONLY after credentials are configured and a live probe is authorised:

    php artisan expo:integration-smoke --r2 --confirm-live

Do not reuse the product-media account/token for disaster-recovery archives.

## 4. Encrypted backups, offsite copies and restore drills

Use a **separate** 32-byte libsodium backup key generated by:

    php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'

Provision EXPO_BACKUP_KEY_BASE64 outside Git and retain recovery copies in a separate secret vault with restricted human access. Never use APP_KEY as the backup key. Protect old keys for historic archives. Set a non-public backup directory outside the source tree with restricted user permissions and disk-capacity monitoring.

Enable EXPO_BACKUP_OFFSITE_ENABLED=true only after provisioning a dedicated, separate PRIVATE R2/S3 bucket and EXPO_BACKUP_OFFSITE_* credentials. Configure retention, lifecycle, versioning and provider-side deletion protection. A copied object is not immutable just because it is private. The backup command encrypts before upload, independently reads back the remote ciphertext and checks SHA256. Failure to verify offsite storage yields an error and retains the local encrypted archive for follow-up.

    php artisan expo:backup
    php artisan expo:backup --verify=/absolute/path/to/archive.expoenc
    php artisan expo:backup --verify=/absolute/path/to/archive.expoenc --extract=/new/empty/absolute/recovery/directory

The recovery extract refuses an existing path and cannot overwrite the ERP project tree. For PostgreSQL, create an empty **separate** recovery database and run:

    pg_restore --exit-on-error --no-owner --no-privileges --dbname=recovery_test /recovery/directory/database.pgcustom

For SQLite, inspect the recovered database using SQLite PRAGMA integrity_check. Restore a test instance, verify user/tenant records, essential reports and attachments, and validate reconciliation hashes. DO NOT restore over production until an explicit incident recovery plan and business reconciliation has been approved.

Important: the database/private-files backup does NOT include external Cloudflare R2 product objects. Configure object versioning/replication and independently rehearse product-image recovery. A failed production node or compromised R2 account must not eliminate both live data and all backups.

When the backup scheduler is explicitly enabled, nightly encrypted backups run at 02:20 UTC. Enable monitoring every five minutes with EXPO_MONITOR_SCHEDULE_ENABLED=true and configure an EXPO_OPS_ALERT_WEBHOOK_URL controlled by the operations team. On one scheduler host:

    * * * * * cd /srv/boosted-export-erp/backend && /usr/bin/php artisan schedule:run >> /var/log/boosted-export-erp/scheduler.log 2>&1

Set documented RPO, RTO, retention, recovery-owner and key-rotation procedures. Rehearse recovery at least quarterly. Preserve appropriate audit records and legal retention.

## 5. Verification, vulnerability testing and finance safety

Before production acceptance:
- Verify PHP/Composer lock, npm lock, composer audit and npm audit; build and checksum deployed assets.
- Run all application tests on both SQLite and PostgreSQL (against a disposable database); additionally exercise concurrent stock allocation, packing, financial posting, separate approvers, bank reconciliation, failed/retried network submissions and locked financial periods on production-like PostgreSQL/Redis infrastructure.
- Test public supplier invitation tokens, private QR scopes, upload validation, cross-business tenant access, privileged roles, password attacks, session fixation, CSP/CSRF, email deliverability and R2 permissions.
- Require an independent penetration test and a qualified accountant to approve the workflows before processing real company finances. An automated test suite does not imply a security certification.
- Ensure app health /up is used for simple liveness, while private expo:monitor and expo:preflight --live cover readiness, scheduled backups, Redis and database reachability.
- Confirm authenticated admin MFA, secure email transport, working backups and offsite object restore before enabling traffic.
- Bank verification integrations and statutory payments remain separately authorised external dependencies; no live funds movement should occur during infrastructure acceptance.

## 6. Release and rollback

1. Review and commit only the agreed source. This Phase 1 work is still local, uncommitted and undeployed.
2. Obtain a formally approved production host configuration and complete all preflight gates.
3. Verify an encrypted, offsite, decryptable backup and perform an independent staging restore before modifying any existing production records.
4. Under an approved maintenance window, install the release with Composer --no-dev, npm ci and npm run build. Run app migrations only against the intended DB with php artisan migrate --force; use config:cache, route:cache, view:cache, queue:restart.
5. Verify HTTPS, MFA and permissions, customer/supplier workflows, media reads, email and operations monitoring in staging then production; open traffic only after success.
6. If deployment fails, stop new writes, preserve financial audit evidence, restore the preceding code and apply a separately reviewed data recovery plan if database changes cannot be safely rolled back. Application code rollback never automatically reverses real ledger transactions.

## Tests and caveats

Phase 1 has passed disposable PostgreSQL migration and full regression runs, encrypted SQLite and PostgreSQL backup/staging restore, and offsite replication tests with a fake R2 disk. Actual production R2/SMTP connectivity, TLS infrastructure, DNS, offsite retention, provider-side disaster recovery, physical penetration testing and large-scale concurrency testing cannot be certified on a developer Mac. Live credentials and release authorisation remain outstanding. No Git push, commit, prod deployment, customer refund or transfer was performed.
