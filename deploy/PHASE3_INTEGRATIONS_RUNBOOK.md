# Boosted Export ERP - Phase 3 Integration Handover

Status: Code-level integration foundations completed and verified locally on
22 October 2026. No bank, payment provider, airline, customs authority, or
Zebra printer has been connected to a real live account or physical device.
No transaction, payment, shipment arrival, licence issuance, email, deployment,
commit or push was performed.

This ERP is independent of Boosted+ POS and the previous Safara/Bremit systems.

## What is implemented

### Payment and carrier signed notification intake

- New route: POST /integrations/webhook/{kind}/{provider}
- Supported kinds: payment, carrier.
- Supported payment notice types: payment_settled, payment_failed,
  payment_reversed.
- Supported carrier notice types: carrier_accepted, carrier_departed,
  carrier_arrived, carrier_exception.
- The provider must be separately verified and provisioned by the platform
  operator using a protected command-line workflow, never by arbitrary
  tenant-supplied signing keys. Keys are encrypted with the ERP APP_KEY and
  must be escrowed for rotation and recovery.
- A provisioning attempt cannot silently transfer another company's external
  account to a different tenant. Every authenticated event is tied to that
  pre-provisioned account, company and provider.
- Callback authentication is HMAC-SHA256 over the EXACT received raw JSON.
  X-Expo-Timestamp is a 10-digit Unix UTC timestamp within +/- 300 seconds.
  X-Expo-Signature is lowercase/uppercase 64-digit hex HMAC of:
  timestamp, followed by an ASCII line feed, followed by raw JSON bytes.
  No whitespace normalisation or JSON re-serialization occurs.
- JSON must contain account, event_id, event_type, reference and occurred_at.
  occurred_at is a timezone-aware ISO 8601 timestamp. Payment messages also
  require an exact positive decimal amount and 3-letter ISO currency.
- Payment notices are linked only when the business has exactly one already
  posted finance payment with matching external_reference, currency and
  amount. Carrier notices are linked only if the business has exactly one
  shipment with the exact air_waybill.
- Unknown or conflicting references remain unmatched for investigation.
  Signed events are independently reviewable; duplicate event_id plus same
  exact payload is idempotent. Reusing an event_id with changed signed content
  is rejected, preserving the original record.
- Incoming body is encrypted at rest, its SHA-256 fingerprint is retained,
  and audit review decisions and notes are recorded. The original financial
  or shipment entry is NEVER changed by the webhook.
- A reviewed payment notice does NOT certify banking settlement or create
  GL journals. Independently authenticated bank statements, manual statement
  matching, finance posting controls and reconciliation remain mandatory.
  A reviewed carrier arrival is NOT verified air transport delivery and
  never automatically advances a shipment to delivered.

Operator provisioning (the genuine provider secret must be injected by
secret manager into EXPO_PROVIDER_SIGNING_SECRET, NOT placed on the command
line or committed to source):

    php artisan expo:provider-provision BUSINESS_ID payment PROVIDER_CODE PROVIDER_ACCOUNT --trust-reference=INDEPENDENT-APPROVAL-TICKET --confirm-verified-onboarding

To disable a particular account while preserving historical provider events:

    php artisan expo:provider-provision BUSINESS_ID payment PROVIDER_CODE PROVIDER_ACCOUNT --disable

The PROVIDER_CODE and PROVIDER_ACCOUNT must originate from the actual
provider onboarding agreement, not fabricated strings. Carrier has a separate
kind and trust relationship, with a separate signing secret. Record provider
legal name, verified issuer signing ownership, merchant/airline identifier,
sandbox/API spec, permitted event types and key rotation in the confidential
onboarding ticket.

IMPORTANT: This is a private ERP webhook CONTRACT, not a claim that Livepay,
Thunes, Onafriq, Emirates, another airline, or a bank currently emits this
particular JSON signature schema. A vendor-specific mapping/authentication
adapter must be developed and acceptance-tested against the real vendor docs
and test credentials if its existing callback format differs. NEVER disable
signature validation or treat a self-signed test callback as bank authenticity.

### Zebra on-site label printing

- ERP has a new Print Workstations screen.
- Owner/admin registers a local workstation and receives a 64-character
  bearer token once, stored hash-only in the database.
- An authorised staff member queues a previous immutable ZPL generation for
  one active local workstation with a UUID idempotency key.
- Workstation polls over authenticated HTTPS and receives a limited-time
  delivery lease, SHA-256 and original ZPL bytes. The exact saved label is
  revalidated against its hash and bounded to 64 KB.
- Workstation acknowledges transferred or failed status. Leases expire,
  are limited to three attempts and can be revoked. Revocation cancels
  remaining pending work. Company and agent scoping is required on every
  query; unknown and stale tokens are refused.
- The optional agent at deploy/zebra_agent/zebra_agent.py uses Python stdlib,
  HTTPS certificate validation, explicit --enable-physical-print, and only
  the printer's RFC1918 private LAN numeric IPv4 provided on that machine.
  It does NOT accept printer destinations from the ERP.
- An acknowledged socket write is not a successful physical sticker print.
  Duplicate physical stickers can occur if acknowledgement is lost. A
  workstation-side job hash journal reduces but cannot eliminate duplication.
- Hardware QA, media calibration, real barcode/QR scan, printer offline
  handling and physical label application require actual Zebra hardware.
  Detailed instructions: deploy/zebra_agent/README.md.
- Manual download of prior .zpl files remains available without any print
  workstation or device token.

### Export regulatory and certificate references

- A per-shipment export-clearance register now captures the purported
  issuing authority, document kind, external reference, optional expiry,
  source evidence and submitting staff member.
- Kinds supported: phytosanitary / plant-health, certificate of origin,
  export declaration/customs filing, airwaybill/carrier document,
  other permit.
- Another owner/admin may mark a record internally checked or reject it,
  with a written review explanation. Staff cannot independently approve
  their own submission; an expired document cannot be internally accepted.
- This is a DOCUMENT REGISTER only. The ERP neither creates official
  certificates nor contacts an issuing authority, and must not present
  an internally checked record as "government verified".
- Actual statutory integrations require the responsible authorities'
  permission, official APIs/sandbox access, local and destination-market
  legal requirements and third-party identity verification before development
  and certification can be claimed.

## Operational UI

- Provider Updates: company-specific onboarded accounts, signed event intake,
  unmatched notices and authorised independent reviewer decisions.
- Print Workstations: register/revoke device access, queue original ZPL,
  inspect delivery attempts and distinguish socket handoff from physical print.
- Export Shipments: linked carrier notifications and independent references
  for plant-health/customs/origin paperwork with audit and expiry review.
- No user-facing screens disclose webhook signing secrets, agent token hashes,
  raw decrypted provider payloads or internal money movement credentials.

## Verification

Phase 3 adds migration 2026_10_22_000001_create_phase_three_integrations.
It is additive; it was applied to local SQLite without reset or deletion.
The existing migrations and ledger, invoice, shipment and stock event history
are preserved.

The automated tests cover:
- Valid and invalid signed notifications, signature timestamp window,
  strict JSON event shapes, replay idempotency and mismatched event ID reuse;
- Wrong provider account, wrong tenant, wrong currency/amount, unmatched
  payment references, and the impossibility of auto-posting money;
- Carrier messages never auto-dispatch or deliver goods;
- Print workstation token hash, queue idempotency, cross-company isolation,
  lease handling, acknowledgement, revocation and absence of any physical
  printing claim;
- Independent certificate review, expiry checks and cross-company scoping.

Commands:

    cd /opt/homebrew/var/www/boosted_expo/backend
    php artisan migrate:status
    php artisan view:cache
    php artisan test --compact
    php artisan expo:preflight --live

A full SQLite regression suite and full disposable PostgreSQL suite both
passed 138 tests with 2,713 assertions on 22 October 2026. PostgreSQL test
database was destroyed after completion. These are offline transport
contract tests, not proof of live provider delivery or hardware output.

## Outstanding external activation requirements

1. REAL verified payment/bank provider relationship: contractual approval,
   official webhook/API format, merchant identifiers, key ownership, sandbox
   callbacks, production credentials, settlement/reconciliation mapping,
   reversals and dispute workflows. Bank account statement authentication
   and ledger posting always remain independently governed.
2. REAL airline/freight forwarder partnership: official airwaybill status
   API, event authentication, account access, event code mapping, sandbox
   verification, time zone and operational exception procedures.
3. REAL Zebra model and workstation: private LAN printer IP, supported ZPL
   model/DPI and physical output tests, controlled agent deployment and device
   ownership/rotation.
4. REAL issuing-authority interface: phytosanitary, origin and customs
   documentation verification/issuance via the official agency processes,
   country- and destination-specific requirements, authorisations and
   approved service endpoints. No regulatory API access is presently assumed.
5. Phase 1 release gates remain open: live SMTP, R2, HTTPS, production
   PostgreSQL and Redis, offsite verified restore, monitoring, independent
   penetration testing, real concurrency and a reviewed SQLite data migration.

Until all external onboarding conditions are fulfilled, the provider
connections page may legitimately say "No registered provider connections",
and the print agent remains disabled by default. No made-up credentials,
merchant payments or official endorsements have been introduced.

## Release boundaries

All work is local only. DO NOT push/commit/deploy or turn on actual provider
callbacks/printing without operator authorisation, a verified provider or
device, a staging rehearsal and a rollback plan. Phase 4 should concentrate
on real-world business acceptance, security/financial sign-off, deployment
preflight and release, rather than treating Phase 3's offline adapters as
connected production partners.
