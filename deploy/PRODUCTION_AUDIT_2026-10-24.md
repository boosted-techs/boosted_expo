# BoostedOne hosting, security, UI and UX audit

Date: 24 October 2026 (Africa/Kampala)  
Live service: https://boostedone.online  
Application source: `/opt/homebrew/var/www/boosted_expo/backend`  
VPS deployment: `/srv/boosted-one/current`  
Administrator: `developer@boostedtechs.com`

## Outcome

BoostedOne is hosted in its own rootless Docker installation, with its own PostgreSQL, Redis, application, queue worker, scheduler and storage. HTTPS, automated renewal, encrypted media, encrypted offsite backups and a database restore rehearsal were verified. A fresh production database was created; local demo records were not imported.

The engineering review is complete for this deployment. This is not an independent penetration-test certificate, a legal compliance certification, or approval of a customer's operational workflows. The open findings below must remain visible in client-launch decisions.

## Isolation and capacity

| Boundary | Applied control | Verification |
| --- | --- | --- |
| Host account and engine | Dedicated `boosted-one` UID 1006 and rootless Docker socket | Rootless, seccomp and cgroup namespaces reported; cgroup v2/systemd active |
| Overall CPU | 150% quota, equivalent to 1.5 CPU cores | Applied to the entire user's systemd slice, including builds and engine processes |
| Overall memory | 2.5 GiB maximum; approximately 2.2 GiB pressure threshold; no swap | Slice settings inspected |
| Processes and I/O | 1,024 tasks; I/O weight 10 | Slice settings inspected |
| Disk | Dedicated 12 GiB filesystem backed by a preallocated image | Mounted filesystem capacity checked; Docker data and application volumes are inside it |
| Web container | 640 MiB RAM, 0.75 CPU, 80 processes | Container cgroup reported `671088640` memory bytes and `75000 100000` CPU quota |
| Other services | Worker 384 MiB; scheduler 256 MiB; PostgreSQL 512 MiB; Redis 192 MiB | Compose limits applied, with the overall slice ceiling above them |
| Files and privileges | Application UID 33, read-only root filesystem, all capabilities dropped, no privilege escalation | Runtime UID checked; no Docker socket mounted |
| Public entry | Nginx HTTPS proxy to `127.0.0.1:3230` | PostgreSQL and Redis have no host-published ports |
| Lateral access | Persistent UID-specific IPv4/IPv6 egress restrictions; private Docker network | Connections from inside the app to host PostgreSQL, MySQL, POS and host-loopback service addresses were blocked |
| Request pressure | Nginx request/connection limits, 16 MiB upload limit, bounded Apache workers and Docker logs | Configuration installed and validated |

The service starts at boot. Its systemd user service requires the dedicated mount and isolation service. Existing POS remained healthy in the final checks. Shared-kernel vulnerabilities, host administration, the shared reverse proxy and upstream network saturation remain shared-VPS risks; application containers cannot provide an absolute guarantee against those events.

## HTTPS and database security

- A Let's Encrypt certificate was issued for `boostedone.online`, currently expiring 22 January 2027. HTTP redirects to HTTPS. The automatic-renewal timer is enabled, and a simulated renewal including the Nginx reload hook succeeded.
- PostgreSQL and Redis use separately issued internal TLS certificates with identity verification from PHP. Internal certificates have their own daily renewal check. The CA private key is outside the service account's home, under `/root/boosted-one-pki`.
- Certificate publication runs with the service UID to avoid privileged writes through an account-controlled home directory.
- The database runtime role has no superuser, database-creation or role-creation privileges. PostgreSQL owner credentials are not in the application environment.
- Redis requires authentication, disables its plaintext port, persists queues/sessions, has a 96 MiB data ceiling and uses `noeviction`.
- Application secrets are owner-readable only and are excluded from images and release source archives. Production debug output is disabled. Session cookies are encrypted, Secure, HttpOnly and SameSite.

## R2 and recovery

The owner's requested temporary arrangement reuses the POS bucket and API credentials.

| Data | Object prefix | Protection |
| --- | --- | --- |
| BoostedOne media | `boostedone/production/media/` | Private application delivery and a unique SSE-C encryption key |
| BoostedOne backups | `boostedone/production/backups/` | Authenticated client-side encryption with a separate backup key |

The POS bucket has a public media delivery URL configured. To protect BoostedOne media in that bucket, SSE-C was enabled. A live object write/read succeeded with the encryption key; an authenticated read without that key failed, and anonymous public delivery returned HTTP 400. The temporary probe was deleted. Prefix write/delete regression tests passed.

Encrypted backups are scheduled nightly at 02:20 UTC (05:20 East Africa Time). A backup was uploaded, read back and checksum-verified. A decrypted backup was restored into a separate temporary PostgreSQL database: production and recovery each contained one administrator, zero companies and 39 migration records. The recovery database and extracted scratch files were removed. Temporary backup work uses the bounded private backup volume rather than a small memory filesystem.

App, backup and media encryption keys were escrowed in a private local file at `backend/.codex/production/recovery-keys.json`, mode 0600, as well as the protected server recovery location. Keep another controlled recovery copy outside this workstation and VPS. Encryption keys must accompany a disaster-recovery plan.

**Open high-priority exception:** a prefix and encryption do not limit the shared API credential's authority. A compromised application could access POS objects allowed by that key or delete shared-bucket objects, including backups. A separate bucket-scoped credential and independently protected backup account are needed for stronger containment. The owner explicitly requested sharing for now.

## PHP, dependencies and vulnerability findings

Runtime PHP is **8.4.27** with `session.upload_progress.enabled=0`. The live settings were verified against the [PHP security advisory](https://www.php.net/archive/2026.php#2026-10-21-5). Debian security updates were applied during image construction. Unused compilers/development headers were removed. The PostgreSQL image's unused `gosu` helper was removed, eliminating its fixable Go-runtime findings; PostgreSQL runs as its unprivileged database user.

Composer's production dependency audit reported no advisories or abandoned packages. Trivy scanned application, PostgreSQL and Redis images. After hardening:

| Image | High scanner findings | Critical scanner findings | Findings with an available fix reported for the installed distribution |
| --- | ---: | ---: | ---: |
| Application | 109 | 3 | 0 |
| PostgreSQL | 71 | 3 | 0 |
| Redis | 57 | 1 | 0 |

Counts are package findings, not a count of demonstrated remotely exploitable paths. **Zero available fixes is not a clean vulnerability result.** The policy-only release retains the same dependency versions. Raw reports are retained privately on the server and copied to `backend/.codex/production/audit-evidence.json`.

Critical findings require the following distinction:

- **CVE-2026-6653, libxml2:** remains open in Debian Bookworm. Debian describes a crafted-XML denial-of-service issue and defers the fix. Imported XML-based files require care; this needs continuing vendor review or a tested runtime with a patched library. Do not represent the platform as vulnerability-free. [Debian tracker](https://security-tracker.debian.org/tracker/CVE-2026-6653)
- **CVE-2025-7458, SQLite:** the library is present, while production data uses PostgreSQL. Debian describes exploitation as requiring the ability to execute crafted arbitrary SQLite SQL. That prerequisite was not found in the reviewed production paths; the installed-library finding remains recorded. [Debian tracker](https://security-tracker.debian.org/tracker/CVE-2025-7458)
- **CVE-2023-45853, zlib:** Debian states the vulnerable contributed MiniZip code is not built into the affected Bookworm binary packages. Treat this scanner entry according to that vendor assessment, rather than claiming the scanner report is empty. [Debian tracker](https://security-tracker.debian.org/tracker/CVE-2023-45853)

The existing host's externally bound database ports were observed during inventory. BoostedOne is blocked from accessing them. Existing services and their firewall requirements were not changed during this application deployment. A separate host-wide review should assess those bindings, host patch/reboot status and ingress DDoS protection.

## Application, UI and UX verification

- **172 tests, 3,138 assertions passed.** Coverage includes tenant boundaries, role permissions, privileged MFA, authentication/password recovery, financial controls, public pages, policy publication and R2 prefixes/encryption.
- Authentication uses throttling, CSRF protection, session regeneration and additional authentication for privileged production accounts. Private tenant resources have cross-company refusal coverage.
- Live PostgreSQL TLS, Redis TLS, restricted database permissions, media privacy and SMTP authentication passed. SMTP was authenticated without sending an unsolicited message; inbox delivery has not been independently confirmed.
- Reviewed desktop marketing, small-screen navigation, onboarding and sign-in. Onboarding checks at 320, 360, 390, 768 and 1365 CSS pixels showed no horizontal document overflow. The mobile menu worked; form controls had labels; sign-in inputs used 16 px text and password-manager autocomplete.
- Added mobile sign-in branding and a route home. Added screen-reader status/error announcements to authentication forms.
- Privacy Policy, Data Retention Policy and Terms of Service were published at the owner's instruction, effective 24 October 2026. Draft notices were removed, the pages were made indexable and added to the sitemap. Policy wording avoids invented fixed deletion periods, uptime guarantees and unagreed commercial terms.

## Remaining client-readiness work

1. **Incident alerts:** application health monitoring runs every five minutes, but no external alert destination is configured. Operational preflight reports zero errors and one alerting warning. Agree a monitored incident channel.
2. **Vendor findings and shared credentials:** keep the OS-library findings and shared-R2 exception in the risk register. Separate credentials and independent immutable backups remain necessary for stronger client isolation.
3. **Retention operations and privacy governance:** agree a category-specific retention schedule, backup expiry, deletion handling, processor/transfer arrangements and responsible privacy contact. Verify applicable PDPO registration and contractual requirements. Publishing policies does not establish those operational facts.
4. **Customer acceptance:** run the existing independent business-acceptance process for actual client workflows and release evidence before representing the ERP as independently approved for real financial/export operations. No acceptance records or professional certifications were fabricated.
5. **Physical and external systems:** validate actual printers, bank/carrier integrations, customer device/accessibility needs, scale and service-level expectations with each client. Browser review and automated tests do not prove those external outcomes.

## Operator handover

The production definition is `deploy/compose.production.yaml`; Nginx configuration is `deploy/nginx/boostedone.conf`. Provisioning, initial activation and private TLS-renewal scripts are under `deploy/scripts/production-*`. Initial activation intentionally refuses to create another administrator; do not rerun it blindly against an established installation.

Use the `boosted-one` account with Docker socket `/run/user/1006/docker.sock` and the current Compose file for subsequent operations. Run commands from an accessible working directory. Take a verified backup before schema changes, retain a known-good image, and check application health plus adjacent-service health after updates. The container images and source should be rebuilt through reviewed releases when new security patches are available.
