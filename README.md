# Boosted Export ERP

A standalone, multi-company export management system under development by Boosted Technologies. This repository is not yet production-ready. Boosted+ POS remains a separate project.

## Current development pass (20 October 2026)

Implemented:
- Secure interactive bootstrap for the first Boosted platform administrator.
- Platform-controlled exporter company onboarding and invitation-only owner/staff accounts.
- Email-based one-time invitations (72-hour expiry), login throttle, sign-in and sign-out.
- Per-business membership checks, backend role enforcement and cross-company data isolation.
- A product catalogue with search, create, edit, archive and read-only user access.
- Excel/CSV product import with preview, validation and company-scoped confirmation; Excel export and template.
- Product photographs optimised into full and thumbnail WebP objects on private Cloudflare R2 (when configured).
- QR PNG generation, public product pages with opt-in visibility, and generic product/batch/carton QR identity lookup.
- A responsive business workspace with the new export-green theme and light/dark modes.

The other modules are explicitly marked as development previews and do not process real business transactions yet.

## Local setup

Requires PHP 8.4+, Composer, GD and ZIP.

    cd /opt/homebrew/var/www/boosted_expo/backend
    composer install
    php artisan migrate
    php artisan expo:bootstrap-admin
    php artisan serve --host=127.0.0.1 --port=8000

Open http://127.0.0.1:8000/login and sign in using the account created by the interactive command. There are no default credentials or public user-registration routes. The platform administrator creates a company and invites its first owner; the owner then invites employees.

The local environment currently uses MAIL_MAILER=log: invitations appear in the local Laravel log instead of being delivered. Configure a genuine SMTP or transactional email service before using invitations with real people. Set APP_URL to a reachable HTTPS address when published: QR codes and invitation links use APP_URL.

## Cloudflare R2 media

Configure these separately in backend/.env (never commit the secrets):

    R2_ACCESS_KEY_ID=
    R2_SECRET_ACCESS_KEY=
    R2_BUCKET=
    R2_ENDPOINT=https://YOUR_ACCOUNT_ID.r2.cloudflarestorage.com

Keep the bucket private. Images are restricted to JPEG/PNG/WebP under 4 MB, validated, optimised, re-encoded to WebP (removing original metadata), and stored under tenant-specific keys. No real Cloudflare R2 credentials are installed; upload is disabled until configured. Automated R2 tests currently use a simulated storage disk.

## Product spreadsheets

In Product Catalogue, choose Import from Excel. The first importer accepts common product-name, SKU, unit, description, and status headings. It reviews up to 1,000 rows and allows at most 500 distinct products per import. A missing SKU is derived from the product name and should be checked before approval. Existing SKUs within the same business are updated only after confirmation.

This is a product-catalogue importer, not a packing-list or accounting importer. The multi-format shipment importer remains to be developed.

## QR visibility

Data models support product, batch and carton QR identities. For a product, the QR PNG points to a stable public page. Public access is OFF by default. The user can turn it on or off from the product editor. Public QR pages do not disclose internal purchasing, supplier contacts, finances or staff data. A QR code is not proof of regulatory certification.

## Planned passes

1. Configurable business permissions and approval workflows, account recovery and MFA.
2. Customer and supplier records, quotation requests, purchasing, and associated approvals.
3. Receiving, batch ledger, grading, packing jobs and carton reconciliation.
4. Full sticker/document studio, Zebra ZPL integration, shipping documents and dispatch.
5. Multi-currency finance, bookkeeping, reporting, shift and payroll functions.
6. Real installable PWA offline synchronization, push notifications and comprehensive security QA.

Production requires PostgreSQL or another supported production-grade database, HTTPS, APP_DEBUG=false, configured mail transport, verified R2 connectivity, and remaining transaction/audit controls. PWA offline support is not currently implemented.

## Verification

    cd backend
    php artisan test
    php artisan view:cache
    php artisan route:list

No deployment or Git push has been performed. All application changes are in this repository.
