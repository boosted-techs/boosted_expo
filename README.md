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
- Customer sales enquiries, issued/revisioned price quotations, confirmed sales
  orders, shipment quantity fulfilment, commercial invoices and an accountant's
  read-only invoice handoff.
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

Customer and supplier self-service portals, actual quotation email delivery,
accounting journals, payment matching, credit/debit notes, invoice amendments,
shipment costing/profitability, multi-currency FX accounting,
configurable business approval policies, staff shifts and payroll,
real PWA offline synchronisation, push notifications, carrier status
integrations, regulatory export certificates, and full security QA remain pending.
Financial data from purchases and commercial invoices is not yet ledger-posted.

## Verification

    cd backend
    php artisan test
    php artisan view:cache
    php artisan migrate:status
    php artisan route:list

Do not run migrate:fresh on data to be retained. All recent changes use
additive migrations. The prior development phase was pushed separately by the
project owner. New local changes still require review before committing or pushing.

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

## Customer sales, shipment fulfilment and commercial invoices — October 2026

This pass added a working business-scoped sales workflow:

1. Sales staff record a customer enquiry with one or more product quantities.
2. Staff prepare a draft price quotation using an explicit currency, agreed
   incoterm, validity date, and optional discount/tax percentages. All prices
   use decimal arithmetic and are validated against supported amount limits.
3. Once issued, a quotation is locked and its exact printable HTML is
   snapshotted. A PDF can be downloaded and shared **manually** with the buyer.
   Later revisions keep previous issued prices and documents unchanged.
   The application does **not** email quotations to customers automatically.
4. The owner or company administrator records the buyer's written acceptance
   (PO or communication reference). This creates a confirmed sales order
   with uneditable quoted quantities, unit prices and product details.
5. A shipment may be linked to one confirmed order. Multiple shipments may
   fulfil that order partially. The aggregate of all cartons across those
   shipments cannot exceed the order quantities by product. A carton can
   be allocated to only one shipment. Shipment allocation never deducts
   loose warehouse stock again.
6. After a linked shipment is ready, the owner, administrator or accountant
   may issue **one** commercial invoice for its allocated carton quantities.
   Unit prices are inherited from the accepted order; freight and insurance
   can be entered separately. Invoice HTML, PDF source, totals, currency,
   customer, product identifiers and shipment details are immutable snapshots.
   The invoice cannot be edited or reissued in this release. Shipments with
   issued commercial invoices cannot be reopened or cancelled in the draft
   workflow; a controlled amendment/credit process is planned.
7. Every issued invoice also creates a company-isolated **accounting handoff**,
   visible to accountants and company owners. This handoff is explicitly
   UNPOSTED and awaiting the company accounting/revenue-recognition policy.
   It displays documentary invoice totals separately by currency. It does not
   debit receivables, credit revenue, calculate exchange differences, recognise
   income, or record payments.

Business roles: sales staff can work on requests/quotes and view orders;
owner/admin confirms customer acceptance; logistics may link ships and
allocate stock; owner/admin/accountant issues commercial invoices.
The first release uses predefined permissions rather than a configurable
approval editor. Public customer self-service and email delivery remain pending.

Product-unit integrity: kilograms, boxes and other units cannot be changed on
an existing catalogue item that has receiving, purchase, packing or sales
records. Product names, codes and units are copied to quotation/order lines so
later catalogue edits do not alter commercial snapshots. The same unit guard
also applies to Excel catalogue imports.

Important limitations:
- Tax and discount percentages are manually supplied, not validated against
  tax authority rules or local export tax exemptions. Verify with an accountant.
- Splitting an order across shipments can create small rounding differences
  in per-shipment discounts or taxes; reconcile as part of the later accounting
  workflow.
- Commercial invoices are development-stage documents and may lack regulated
  customs, HS code, exporter registration or banking particulars required by
  a specific country/corridor.
- Buyer acceptance is manually recorded by staff; no digital buyer signature
  or provider-verified acceptance is captured.
- There is no accounting ledger posting, credit/debit note or invoice correction
  workflow, and no external payment or carrier integration.

The new additive migrations are 000010 through 000013. Do not use
php artisan migrate:fresh when preserving business records.

To test the entire project from backend:

    php artisan view:cache
    php artisan test
    php artisan migrate:status

This pass is local only; it has **not** been pushed or deployed.
