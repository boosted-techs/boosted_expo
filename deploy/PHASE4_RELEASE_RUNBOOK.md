# Boosted Export ERP — Phase 4 Acceptance & Controlled Release

**Prepared:** 22 October 2026  
**Status:** Locally implemented, not deployed.  
**Project:** /opt/homebrew/var/www/boosted_expo  
**Previous phases:** See deploy/PHASE1_PRODUCTION_RUNBOOK.md, PHASE2_OPERATIONS_RUNBOOK.md and PHASE3_INTEGRATIONS_RUNBOOK.md.

Phase 4 supplies the code and operating procedure for a defensible release decision. It does **not** automatically deploy a website, transfer customer money, issue government certificates, authenticate airline deliveries, or declare the platform penetration-tested. No actual production host, DNS, SMTP/R2 credentials, bank/carrier credentials, external assessment report or approved warehouse printer has been supplied to this phase.

## Delivered controls

### 1. Role-separated business acceptance

Go to **Release Acceptance** in the ERP. The company **owner** starts a campaign and enters **two approved SHA-256 fingerprints**: the immutable source archive and compiled frontend build, plus an explicit staging scope. The SHA-256 is not a Git branch name, and a synthetic 64-character test value must never be used as production proof.

There are twelve mandatory tracks:

| Track | Test owner or allowed tester | Independent approval |
| --- | --- | --- |
| Sales / customer orders and invoices | Sales lead or authorised owner/admin | Different owner/admin |
| Supplier purchasing and goods receiving | Warehouse receiving lead or authorised owner/admin | Different owner/admin |
| Packing, quality and traceability | Packing or warehouse staff or owner/admin | Different owner/admin |
| Shipment and export paperwork | Logistics lead or owner/admin | Different owner/admin |
| Finance postings and reconciliation | Accountant or owner/admin | Different owner/admin |
| Workforce, roles and MFA | HR or owner/admin | Different owner/admin |
| Provider notices and printer fallback | Integration viewer or owner/admin | Different owner/admin |
| Independent security assessment evidence | Owner/admin enters external report reference | Different owner/admin |
| Qualified accounting assessment evidence | Owner/admin enters external accountant report | Different owner/admin |
| Encrypted offsite backup and recovery rehearsal | Owner/admin enters real drill evidence | Different owner/admin |
| PostgreSQL cutover/reconciliation rehearsal | Owner/admin enters migration reconciliation report | Different owner/admin |
| Live ops, alerts, incident ownership and handover | Owner/admin enters operating acceptance | Different owner/admin |

Every staging scenario captures an evidence ticket/reference and a meaningful written account of what was actually exercised. A separate owner/admin must **approve or reject**; the original submitter cannot self-approve. Rejected cases may be resubmitted, preserving the rejection and prior hashes in an append-only acceptance activity log. Accepted cases cannot silently be changed, and a sealed campaign is immutable through the application.

Only the **company owner** may seal the run. Sealing is refused until **all twelve** cases are independently approved. Confidential external assessment narratives are hidden from non-management staff. Neither a human report reference nor a seal proves the named external organisation actually certified the product; operations must contact the issuer independently.

The latest campaign for the same artifact SHA must be **sealed**, or the release gate fails. A newly opened/rejected campaign supersedes an older seal, even if the code hash is unchanged.

### 2. Read-only data integrity audits

The new command below **reads** the selected business but does not edit financial, shipping or stock data:

    cd /opt/homebrew/var/www/boosted_expo/backend
    php artisan expo:data-audit BUSINESS_ID --json

It checks existence of an active company owner; exact balancing of source/base debit and credit on financial journals; negative unpacked source-batch balances; immutable invoice, export document and ZPL label fingerprints; dispatched/delivered shipments without cargo allocation; and unresolved external provider notifications.

If a journal is unbalanced, a source label was altered or a source batch has a negative balance, the program reports failure rather than attempting a silent correction. A trained operator must investigate and use the existing controlled correction workflows. Clearing the error is not permission to reverse or discard historic posted entries.

Unreviewed provider notices are warnings in ordinary operational audit, but are **blocking** in the final release gate. Never classify a manually uploaded bank CSV or third-party event as authenticated settlement unless that proof actually exists.

### 3. Local, safe staging rehearsal

Run from the project root:

    sh deploy/scripts/phase4_staging_rehearsal.sh

The script:
- refuses a production Laravel runtime and an inherited DB_URL;
- checks Git whitespace and PHP syntax and compiles route/Blade caches;
- runs the entire SQLite in-memory regression suite;
- creates a **uniquely named PostgreSQL database on localhost /tmp** and runs the entire regression suite again;
- destroys only that temporary test database on exit, including after a test failure;
- records local, private QA logs, SHA-256 hashes and a JSON evidence manifest in a fresh /tmp/expo_phase4_evidence.* directory;
- runs ordinary non-live production preflight for diagnostic evidence, which is expected to fail in development.

**Limitations:** The disposable PostgreSQL test database contains synthetic PHPUnit fixture data, NOT a rehearsal migration of real production SQLite financial/client records. The evidence manifest deliberately says production approval, live external connectivity, physical Zebra output, regulatory service integration and actual-data cutover are **not** complete.

### 4. Immutable release candidate

Once the source has been reviewed and the user explicitly authorises an actual commit, the optional packaging command is:

    sh deploy/scripts/phase4_build_candidate.sh

It **refuses dirty or uncommitted worktrees**. It checks common secret/SQLite filenames in tracked files, then creates a private SHA-256-fingerprinted **source** archive from that committed revision with no live deployment. The archive contains the tracked source and lockfiles; it does not package live .env credentials or the application's current customer database. Perform additional secret scanning before publishing it anywhere.

Use the resulting SHA-256 and its immutable archive as the source of truth for the acceptance campaign. A changed source archive **or changed compiled frontend assets** requires new fingerprints and an independently reviewed acceptance campaign. Install Composer dependencies and build frontend assets in staging using the pinned lockfiles; compare the resulting artifacts and smoke-test the deployed application. This source bundle alone does **not** verify vendor libraries, compiled frontend contents, operating-system packages or remote infrastructure. Those require separate controlled checks.

**Current status:** The working tree has intentional, uncommitted Phase 4 changes. The package builder correctly refuses to produce a release artifact from this dirty tree. Do not force it through.

### 5. Production release gate — read-only

The designated operator provisions these variables only after obtaining an immutable approved archive:

    EXPO_RELEASE_ARTIFACT_SHA256=<actual 64-digit hexadecimal checksum>
    EXPO_RELEASE_ARTIFACT_PATH=<private absolute path to the exact archive>
    EXPO_RELEASE_ASSETS_SHA256=<independently approved hash from expo:build-fingerprint>

Do not paste them as a fake test value. The archive must be readable by the ERP runtime; it must not be a symlink. The integrity verifier validates the full archive SHA and compares application PHP, Blade, JS, CSS, tracked public scripts, migrations, routes, configs, lockfiles and deployment script source against the installed files. The compiled frontend must also match the separately approved and accepted build SHA-256. Unexpected or missing source code also fails.

Run on the real approved host, after infrastructure provisioning and review:

    cd /srv/boosted-export-erp/backend
    php artisan expo:preflight --live
    php artisan expo:data-audit BUSINESS_ID --json
    php artisan expo:release-gate BUSINESS_ID --sha=APPROVED_SHA256 --live --json

The companion shell script also performs only the read-only release-gate call:

    sh deploy/scripts/phase4_predeploy_check.sh BUSINESS_ID APPROVED_SHA256

The final gate refuses permission if:
- the runtime archive hash, installed code, compiled frontend assets or actual archive is absent or mismatched;
- the selected company is disabled or lacks an active owner;
- the latest same-source-hash acceptance campaign has any unapproved item, wrong frontend build hash or no valid seal **for any active company on the shared ERP instance**;
- any application migration is outstanding;
- journal/stock/document checks report integrity failures for any active company on the shared ERP instance;
- unreviewed provider events remain;
- APP_ENV/debug/TLS, PostgreSQL/Redis, real SMTP, private R2, session encryption, verified backup, separate offsite archive, scheduler or incident alerting are missing;
- read-only live database and Redis connectivity checks were omitted.

A zero exit code from the release gate is **not** a remote deployment, a penetration-test certificate, a bank reconciliation or a regulatory approval. It says the electronically recorded prerequisites and checked local assertions satisfy the release policy. The authorised release committee still owns the go/no-go decision.

**Local baseline:** The Mac runs Laravel in local mode, with SQLite and without the real production providers. Its production preflight is expected to fail; that is the correct safeguard. There are currently no live businesses in the local SQLite development database, so no company-level real-world acceptance campaign has been completed.

## Actual data migration: separate controlled ceremony

Do not import the Mac's SQLite directly over a running production database. A correct rehearsal must first be approved and must use an independently encrypted snapshot of **actual source data**, not PHPUnit's synthetic test fixtures.

1. Obtain legal authorisation, complete a verified encrypted archive and deposit a recovery copy and encryption key escrow in separate protected systems.
2. Build a field-by-field SQLite-to-PostgreSQL mapping covering every tenant and user ID, invoice/journal/document fingerprint, signed stock movement, country and timezone, MFA ciphertext dependent on APP_KEY, key vendor references and audit event, unique code and sequence.
3. Import into a newly provisioned, private, disposable PostgreSQL staging database, never into an existing populated production database. Preserve exact source IDs and foreign key references, then reset PostgreSQL sequences beyond imported identifiers.
4. Verify record counts for **each table and tenant**, foreign keys, unique keys, transaction checksums, source/base journal totals, invoice/export-document hashes and critical sample customer/supplier/procurement workflows. Verify previously encrypted application fields remain decryptable with the correct protected APP_KEY.
5. Compare SQLite inventory movements and carton/shipment allocation, test new bank reconciliation reports and restore/rollback with the same snapshot. Use accountants and operations staff independent of the migration author.
6. Agree a freeze-writes maintenance window, perform a fresh final encrypted backup, repeat the final incremental data transfer and reconcile source vs destination counts and hashes **before** opening production traffic.
7. Record the migration mapping, restoration test, verification output and signed final acceptance under **Cutover Rehearsal**. A failed comparison blocks release; do not silently repair finance differences with an ad hoc SQL UPDATE.

There is **no automatic real-data import performed in this phase**. The full dataset-reconciliation/retention procedure is an external sign-off gate until an actual authorised migration is rehearsed.

## Release-day sequence (operator, not automated)

1. **Approve change:** lock scope, independently review source, dependency audit and third-party penetration report; obtain finance/compliance and business-acceptance sign-offs for the exact SHA.
2. **Prepare host:** DNS, TLS and restricted reverse proxies; private PostgreSQL and Redis; real authenticated SMTP and R2; backup key escrow; offsite immutable retention and recovery drill; queues, logs and alerts.
3. **Staging:** install immutable source archive into a new release directory, install Composer --no-dev, npm ci/build using lockfiles, run migrations only against disposable staging, run integrations/hardware tests only with authorised sandbox credentials, verify MFA/roles/tenants and sample end-to-end dispatch/finance cases.
4. **Cutover:** stop writes at an agreed time, independently verify backups, execute only approved additive migrations against the intended production database, review row counts, source/base journals and new code smoke tests with managers present.
5. **Check gate:** archive SHA, source-to-runtime hashes, acceptance seal, database integrity and expo:preflight --live must all pass. Record the JSON audit output and change ticket.
6. **Switch traffic:** manually and atomically switch the web server's intended release after sign-off, restart PHP-FPM/queue workers, check health, MFA, browser security headers, notification delivery and per-business financial isolation.
7. **Pilot monitoring:** initially limit onboarding and processing scope; track exceptions, queues, failed emails, backups, audit events and provider webhooks; document which external payment/carrier/hardware features remain intentionally disabled.
8. **Rollback:** on failure stop writes, retain audit events and recorded external side effects; restore the previous code version only under review. Do not run down migrations or overwrite an actively used financial database blindly. Data repair requires an independently approved reconciliation/recovery plan.

No step above has been executed against a production server. This file is an operations runbook, not proof of an external security audit or actual provider certification.

## Production acceptance questions still open

- Which real domain and approved VPS/cloud host will serve this ERP?
- Who will administer the verified PostgreSQL and TLS Redis endpoints?
- Which approved email provider and private R2 buckets will be used?
- Who owns and has actually rehearsed encrypted offsite backups and recovery keys?
- Which independent security tester and qualified accountant sign release acceptance?
- Which pilot export business, QA staff, warehouse location and external regulator/provider test accounts will be included?
- Which real bank and carrier systems provide signed settlement/status data, if any?
- Which real Zebra printer has been measured and tested for DPI/media/barcode reliability?

Until these are answered and independently tested, **production release remains blocked**. A successful local test suite is a development milestone, not permission to accept financial or regulated export operations.


## Additional Phase 4 release-hardening safeguards (22 October 2026)

**Shared multi-tenant deployment:** A production source rollout affects every
active customer company. The release gate therefore scans ALL active companies
and requires each to have the latest sealed, independently accepted campaign
for the same source-archive SHA-256 AND compiled frontend SHA-256. It also
performs read-only financial, stock, invoice and label integrity checks for
other active tenants. A newly opened campaign supersedes an older seal. A
pilot release limited to one customer must run on an appropriately isolated
pilot environment; entering just that company's ID on a shared live server
does not bypass other companies' acceptance.

**Compiled static assets:** The release also binds an independently approved
fingerprint of the exact public/build Vite output. The verifier checks JS,
CSS, fonts, the Vite manifest and asset references and refuses unexpected
PHP/symlinks, missing JS/CSS entrypoints or modified files. Static scripts
under public/assets, Laravel's public front controller and public security
configuration are additionally checked against the approved source archive.
Use php artisan expo:build-fingerprint --json only on a built staging/release
instance; it neither signs nor deploys the build.

**Secrets and backups:** The preflight requires .env to be inaccessible to
group/other users. A recent encrypted backup file alone is not a verified
restore; the restore-drill acceptance category remains mandatory. A valid
release requires independent source and asset fingerprints, independent
business and security acceptance, safe live infrastructure and an actual
reviewed production go/no-go decision.

**Rehearsal evidence:** The isolated staging script now records the observed
compiled build fingerprint alongside SQLite/PostgreSQL regression outputs.
It labels that observed fingerprint as NOT independently approved. Current
development source remains uncommitted and the release packaging command
must continue to refuse a candidate while the tree is dirty.


## Final local engineering verification

The final isolated rehearsal passed **149 tests and 2,811 assertions** on
SQLite and on a freshly created PostgreSQL test database. The disposable
PostgreSQL database was dropped after the run. The staging evidence manifest
for this pass is stored privately on the authorised Mac under:

    /tmp/expo_phase4_evidence.TxaU1aDO/manifest.json

The manifest includes per-file SHA-256 fingerprints for full regression logs,
PHP syntax results, compiled frontend fingerprint and local preflight
diagnostics. Staging was explicitly marked not approved for production.

The final test suite includes a positive source archive verification test
(using a real gzip-compressed source fixture), plus rejection checks for
tampered public scripts and unexpected PHP files. The build verifier
independently rejects missing or modified frontend assets. Multi-tenant
release tests confirm every active company must accept the exact source and
frontend versions. This verifies the local release-gate logic, **not** real
provider, penetration-testing or financial/regulatory acceptance.

The Mac still reports production preflight blocked as expected (local,
SQLite and unconfigured external infrastructure). The source tree remains
intentionally uncommitted and the packager must refuse a dirty tree. No live
email, payment, bank transfer, customs filing, print job or production
deployment occurred.
