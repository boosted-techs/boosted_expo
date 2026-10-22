# Boosted Export ERP — Phase 2: operational completeness

**Implemented in the connected local repository on 21 October 2026.**
No production deployment, commit, push, new external bank connection or
automatic financial movement has been initiated. Phase 1's production preflight
and provider onboarding requirements remain unchanged.

## 1. Accurate operational dashboard

Open **Overview**. It uses live, tenant-scoped information from the database
with role-based visibility:

- **Confirmed customer orders** — number of confirmed orders on record; not
  labelled "unfulfilled" because completed allocations do not cancel an order.
- **Stock batches available** — count of non-quarantined batches whose net
  signed inventory movements leave a positive unpacked balance, without mixing
  kg and pieces.
- **Shipments preparing** — shipments in draft or ready status, excluding
  dispatched/delivered/cancelled records.
- **Finance reviews pending** — independent posting requests still pending.
  Restricted to roles with explicit finance-review visibility.

Accessing another tenant's workspace never reveals its counts. A viewer sees
only tools permitted by their existing role. Dashboard metrics are operational
indicators, not financial statements.

## 2. Team access and staff attendance

- Company owner/admin can invite eligible roles as before.
- They can now change eligible staff roles, suspend or reactivate staff and
  record a written reason. A team access audit event records old/new role and
  active state, actor and date. Nobody can alter their own membership here.
  Owners cannot be demoted through the tool. Company administrators cannot
  promote others to admins or edit an existing administrator.
- Suspended membership immediately loses active-company access. Other tenants
  where the same person remains active are unaffected.
- **Shifts & Attendance**: HR or a company owner/admin schedules shifts using
  the business timezone, checks overlapping shifts and sees team attendance.
  The staff member clocks themself in and out on server time, with an optional
  scheduled shift reference. Flexible unscheduled work is supported.
- Duplicate open check-ins are prevented both by tenant transactions and a
  database-level partial unique index supported by PostgreSQL/SQLite. One
  staff member cannot clock out another member's work.
- Shift cancellations require a reason and are refused after any attendance
  has been recorded against that shift. Clock-ins and clock-outs create
  append-only attendance audit events.
- Attendance is NOT a wage-calculation engine: it does not automatically
  adjust payroll, statutory PAYE, NSSF or overtime. The approved finance payroll
  and salary settlement steps remain separately controlled.

## 3. Configurable company operating approvals

Under **Company Preferences**, the company **owner** can enable the additional
**independent dispatch approval** safeguard. The previous dispatch workflow
remains available by default so existing exporters are not unexpectedly blocked.

When enabled:

1. The warehouse/logistics team completes carton allocation and marks the
   shipment ready, with AWB, route and scheduled departure recorded.
2. An authorised shipment manager requests dispatch approval.
3. A *different* authorised owner/admin independently reviews the exact ready
   shipment and records a detailed approval or rejection reason.
4. If accepted, the logistics lead can record the physical departure.
   The system records this as a MANUAL movement, never as airline-verified.
5. Reopening the shipment creates a new readiness event and invalidates
   the old approval for dispatch purposes, even if the shipment is later
   marked ready with similar details.
6. A rejected request cannot be edited into an approved one or silently
   resubmitted; the shipment must be reopened and newly made ready.

The snapshot fingerprint binds flight, AWB, route, customer, sales order,
carton list, scheduled departure and readiness-event ID. Independent
**financial** approval rules cannot be weakened through this setting.

## 4. Warehouse quality holds and stock warnings

- Catalogue product editing now supports an optional **low-stock warning
  threshold in that product's base unit**. Stock page warnings calculate
  actual unpacked and NOT quality-held stock across all its batches by summing signed movements
  precisely. No automated buying or inventory adjustment is triggered.
- Warehouse/packing staff may place a quality hold on a source stock batch
  with an inspection reason. The affected batch cannot be packed into
  cartons. Quality-held carton contents cannot be allocated to a shipment.
- A hold applied AFTER reservation also blocks marking a shipment ready or
  recording dispatch. The operation fails before cartons move to shipped.
- Only an owner/admin can release quality-held stock, with a written reason.
  Both hold and release actions are recorded in an immutable event log.
- Documented grading/sorting loss may still be recorded against quarantined
  stock so unsafe goods can be disposed of without being packed.
- Stock already physically dispatched cannot be automatically recalled by
  software. Suspected unsafe export cargo requires a real-world isolation and
  recall process, with recorded contacts and independent regulatory reporting
  when applicable.

## 5. Buyer tracking and existing supplier quotation workflow

From a **confirmed sales order**, authorised sales staff can send the buyer
a private, read-only order tracking link to the buyer email saved in the
customer directory. The link:

- Uses a cryptographically random 64-character bearer token; only its
  SHA-256 hash is stored.
- Shows that customer's order products, confirmed prices, currency,
  shipment reference and manual status/flight/AWB details, but no exporter
  internal cash movements, supplier costs, payroll or private contacts.
- Expires automatically after 1–30 days, configured by the company owner.
  Reissuing revokes the previous active link. Staff may explicitly revoke
  links. Changing the buyer email or deactivating the buyer/business makes
  existing links inaccessible.
- Returns 404 for invalid, expired, revoked or mismatched tokens and uses
  rate limiting, noindex, no-referrer and no-store response headers.
- Is sent only when a user explicitly uses **Email private tracking link**.
  In the CURRENT local mail-log configuration it is NOT sent to a live inbox;
  external mail acceptance requires SMTP activation and an authorised
  provider test from Phase 1.

Supplier RFQ response links are already implemented for each invited
supplier's quotation and revisions, with expiry, deadline checks and scoped
data. **This is not an account-based customer/supplier portal**; persistent
self-service sign-in, contracts, secure document exchange and two-way ticketing
would be separate additional work if later required.

## 6. Test and release evidence

Run:

    cd /opt/homebrew/var/www/boosted_expo/backend
    php artisan migrate:status
    php artisan view:cache
    php artisan test --compact
    php artisan expo:preflight --live

The test suites validate dashboards, staff schedules and self-attendance,
duplicate/overlapping shifts, role restrictions, team suspension, independent
dispatch approval, reopening invalidation, warehouse holds, packing/dispatch
restrictions, buyer token scoping, email changes, revocation and expiry. The
entire suite must also pass on an ISOLATED PostgreSQL test database before
deployment.

**Before release:** Provision actual production SMTP/R2/SSL/PostgreSQL/Redis,
perform a reviewed SQLite-data cutover, enable encrypted offsite backups and
monitoring, complete real staffing/quality/warehouse end-to-end acceptance,
independent security testing, financial compliance reviews and supplier/buyer
notification sign-off. No automatic statutory employment obligations are
implied by the attendance records.

## 7. Operator QA path

1. Sign in as owner; check the Overview counts against actual registered
   orders, available stock, ready shipments and finance pending requests.
2. Use Team & Access to change a non-owner role, verify its accessible tools,
   suspend and reactivate with a written reason.
3. As HR, schedule a shift in the company's local timezone. As that employee,
   clock in, try a duplicate clock-in, then clock out. HR can review the audit
   data; another employee cannot alter that attendance.
4. Set a product warning threshold above current unpacked stock. View Stock
   Batches and confirm the warning appears without posting a purchase.
5. Place a batch on quality hold; verify that it disappears from packable
   batch choices and prevents affected cartons moving into a shipment.
   Independently release it and verify the quality event history.
6. Enable dispatch sign-off in Company Preferences, prepare a shipment,
   request review and approve with another account; verify that reopened
   shipments require a new approval even if the old record remains.
7. For a buyer with a real registered email, issue a tracking link only after
   outbound email is activated and authorised, verify buyer visibility and
   manual shipment states, revoke it and verify 404 on reuse.
8. Confirm no staff action or buyer link generated a payroll, bank movement,
   cash settlement or financial journal by itself.

## Final regression confirmation (21 October 2026)

Both the local SQLite test run and a **fresh disposable PostgreSQL database**
passed **130 tests with 2,627 assertions** after the stock-control reporting
refinement. All 254 PHP source files scanned passed syntax checking; view
compilation and Git whitespace validation also succeeded. Three additive Phase 2
migrations (000005, 000006 and 000007) are applied to the existing local
SQLite database without resetting records.

The warehouse screen now displays **physical unpacked balance** separately
from **quantity available for packing**, which is zero for any quality-held
batch. Reorder warnings and overview counts exclude held stock. Mixed held
and released batches are tested separately, so the company can see what is
physically in the warehouse without treating a quarantined lot as usable.

**Development status:** all Phase 2 work remains local. There has been no
commit, Git push or production deployment, and no real customer tracking
email has been sent. The Phase 1 real-provider, database cutover and go-live
gates remain in force.

