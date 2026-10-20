# Boosted Export ERP

Independent, multi-business agricultural-export ERP developed by Boosted Technologies.
**Development project only — not ready for production or real-money transactions.**
This project does not share a codebase or database with Boosted+ POS.

## Local development

Laravel 13 / PHP 8.4 with a responsive Blade frontend and a distinct export-green
variant of Boosted+ POS's design palette. SQLite is currently used locally; a
production PostgreSQL/Redis installation and full hosting/security hardening are planned.

Run on the connected Mac:

    cd /opt/homebrew/var/www/boosted_expo/backend
    composer install
    php artisan migrate
    php artisan expo:bootstrap-admin
    php artisan serve --host=127.0.0.1 --port=8000

Open http://127.0.0.1:8000/login. The first administrator command is interactive and
refuses to create a second platform administrator. Platform admins create exporters
and invite each company's owner. Company owners invite their own staff. There are
no public self-registration routes or default passwords.

## Operational areas implemented

- Company onboarding, memberships, sign-in, invitations and predefined roles.
- Product catalogue, CSV/XLSX preview/import/export and basic media management.
- Customer and supplier directories, sourcing RFQs, invited supplier quotations,
  quotation revision history and owner/admin supplier selection.
- Market and walk-in purchasing, purchase orders, partial goods receipts,
  rejected produce, farmer codes and traceable accepted stock batches.
- Packing jobs, multi-batch cartons, optional grades, gross/net/tare measurements,
  sorting and grading loss entries, partial stock use and completed job locking.
- Tenant-isolated carton directory and traceability back to source batches.
- Product, batch and carton QR identities. QR public pages default to OFF.
- Configurable initial Zebra sticker templates with label dimensions (75–150 mm
  width, 45–100 mm height), 203/300 dpi, selectable text fields, Code 128 barcode,
  product/batch/carton QR options and barcode-only/QR-only templates.
- Downloadable ZPL files with immutable generation snapshots (template version,
  ZPL source and checksum) and generation history. Generating a file is not
  confirmation that a physical label was printed.
- Responsive authenticated application with light/dark mode.

## Warehouse-to-packing process

1. Receive a product against a market or supplier purchase. Enter accepted,
   rejected and optional weights; only accepted amounts enter usable stock.
2. Each accepted receipt creates a unique source batch, stock movement and private
   batch QR identity.
3. Start a packing job for one product. See available produce by source batch.
4. Record sorting/trim/spoilage losses separately from packed quantities.
5. Pack a carton from one or several batches of that product. For kg-based
   produce, carton net kilograms must equal allocated batch kilograms.
6. The same transaction creates a unique carton code, source lines, signed
   stock deductions and private carton QR identity. Repeat submissions are
   deduplicated using request keys.
7. The carton remains traceable even if the packing job is completed. Completed
   jobs cannot accept new carton or loss entries.
8. Use a label template and the carton page to generate a Zebra ZPL label.

Unpacked stock = accepted quantity received minus quantity packed into cartons
minus recorded grading/sorting loss. **Packed cartons are separate from loose
stock; they have not yet been dispatched or posted to the finance ledger.**

Quantity arithmetic is decimal-based. Source-batch and packing-job locks protect
against simultaneous over-allocation when running on a transactional database.
PostgreSQL concurrency, printer hardware and heavy-load testing are still required.

## QR and Zebra sticker details

QR scope is selectable per template: product, batch, carton or no QR.
A batch QR can only identify a carton containing one batch. For multi-batch
cartons, choose the carton QR so the public page identifies the carton as a whole.
The business enables each public record explicitly. Links return 404 when
disabled. Public pages contain only approved identification details; a QR code
does not certify product origin or regulatory compliance.

ZPL data is escaped to prevent embedded field contents from becoming printer
commands. Generation preserves a copy of the original ZPL and template settings
for re-download even after template changes.

**Sticker module still pending:** visual drag-and-drop Zebra designer, brand
logos on stickers, direct USB/network print queues, printer calibration and
print-confirmation status. Physical printer testing remains pending. The
export-document module now separately supports flight/AWB fields and PDF/HTML
packing lists and dispatch notes, but does not constitute regulated certification.

## Cloudflare R2 and email

Product photographs are validated, optimised and sent to a private Cloudflare
R2 bucket after the environment is configured. Add values only in backend/.env:

    R2_ACCESS_KEY_ID=
    R2_SECRET_ACCESS_KEY=
    R2_BUCKET=
    R2_ENDPOINT=https://YOUR_ACCOUNT_ID.r2.cloudflarestorage.com

The current R2 tests use a simulated disk rather than live credentials.
Email invitations currently use MAIL_MAILER=log and do not reach real inboxes.
Configure SMTP/transactional email before inviting actual suppliers or staff.
Set APP_URL to a reachable HTTPS address before scanning printed QR codes from
other devices; URLs using localhost will not work from a customer's phone.

## Current limits / next phases

Customer sales quotation revisions and allocation, commercial invoices,
costing/accounting journals, payments, multi-currency FX handling,
configurable business approval policies, staff shifts and payroll,
real PWA offline synchronisation, push notifications, carrier status
integrations, regulatory export certificates, and full security QA remain pending.
Financial data from purchase records is not yet ledger-posted.

## Verification

    cd backend
    php artisan test
    php artisan view:cache
    php artisan migrate:status
    php artisan route:list

Do not run migrate:fresh on data to be retained. This development pass used
additive migrations only. No production deployment or GitHub push was made.

## Shipment planning, carton allocation and export documents — October 2026

This development phase added:
- Company-scoped air shipment drafts with buyer/consignee, origin and destination,
  airline/carrier, flight, AWB, incoterms and departure/arrival fields.
- Assignment of fully packed cartons from completed packing jobs, preventing
  allocations to multiple shipments. Shipment assignment does **not** deduct
  loose stock a second time; packing already deducted source batches.
- Draft/ready/dispatched/delivered/cancelled statuses, controlled reopening,
  dispatch and cancellation, and recorded per-user shipment events.
- Configurable packing-list and dispatch-note templates, including selected
  columns and header details. Document issuance stores a snapshot of source
  data, template settings and HTML, with checksums and numbered revisions.
- Printable HTML and PDF document exports, with carton-level traceability,
  source-batch and farmer codes, net/gross/tare reconciliation, and product
  totals. Reopening a shipment warns that earlier documents may be outdated.
- Access controls preventing records or downloads crossing company boundaries,
  plus form-level checks for roles and status transitions.

No live air-carrier, customs, or airport tracking is connected. Dispatch and
delivery updates are **manually entered** by authorised staff, not automatically
verified. Packing lists and dispatch notes do not replace commercial invoices,
origin/health certificates, customs clearance or other mandatory export
documentation. No general-ledger entries or customer payments are created.

Readiness checks enforce flight, AWB, airport fields and carton allocation before
dispatch. Existing document snapshots are retained when a shipment is revised.
This module is still a development release requiring business-user acceptance
testing and validation with actual export operations.
