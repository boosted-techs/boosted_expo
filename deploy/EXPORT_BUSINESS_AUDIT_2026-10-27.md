# Ugandan export business system audit

**Reviewed:** 27 October 2026, Africa/Kampala  
**Scope:** the current local working tree, including existing modified and untracked application files. Git HEAD: `501758b17c81bf92ddf0ab4cf6af4f077dc5e39d`. This is not an assertion that the deployed website runs this exact version.  
**Requested outcome:** a simple, multi-tenant system for Ugandan exporters, connecting customer demand, supplier and market sourcing, staff work, produce preparation, exports, assets, borrowing, payments and manual foreign exchange.  
**Companion:** [Implementation roadmap and acceptance scenarios](EXPORT_BUSINESS_ROADMAP_2026-10-27.md).

**Subsequent implementation:** Phase 0 now repairs F01–F03 locally, with 201 passing tests. See the roadmap's Phase 0 implementation record and current feature inventory. Findings and checks below describe the original audit state; later ERP stages remain open.

**Follow-up review:** [Owner and superadmin control requirements](EXPORT_OWNER_CONTROL_REVIEW_2026-10-27.md) audits this document and the roadmap against 48 daily owner questions, 18 superadmin questions, current route coverage and priority sticker/login-installation requirements. Its R01–R15 register supplements F01–F21 below.

## 1. Assessment

The project has a substantial working foundation. It is more developed than a collection of screen mockups: receiving, batch stock movements, packing, carton traceability, shipment allocation, financial approval and double-entry posting have real implementations and automated coverage.

It is **not yet complete for the business described**. Its main weakness is the missing connection between customer demand and the work required to fulfil it. A customer request cannot currently become a confirmed operational order without a priced quotation. Procurement requests select one supplier, and purchases and packing jobs do not identify the customer order lines they are fulfilling. Staff activity is recorded after actions, but responsibility, deadlines and buying advances are not managed as one order workflow.

The financial backend should be extended, rather than rebuilt. Manual approved FX rates, original and base-currency journals, payments, bank reconciliation, inventory valuation, credits and payroll already exist. However, quotation-level UGX preservation, business currency configuration, prepayments, staff buying advances, bank currency conversion, assets and loans remain important gaps.

The interface exposes too many separate accounting and operational destinations. The owner needs one reliable picture of orders, shortages, staff responsibility, available money and exceptions. The existing overview contains only a few counts and many links. That picture must also answer supplier/date history, transport custody, current/fixed assets, debt due dates, daily money out and upcoming funding needs, with an explicit basis and links to source evidence.

The platform also needs its own superadmin dashboard for business lifecycle, subscriptions, measured usage, health and support. Existing billing actions do not provide that complete control centre. On the exporter side, general expenses, full cost-of-goods reconciliation and the rota-to-attendance-to-payroll connection need explicit completion work. These are required ERP workstreams, not optional dashboard decorations; the revised companion roadmap specifies each and its acceptance gate.

**Recommended disposition:** retain the Laravel application and its tested services; correct the confirmed defects; complete the order-to-sourcing relationships; simplify the interface around work; then complete financial scope and PWA delivery. Do not describe the current version as a complete export ERP or as fully offline capable.

## 2. Evidence and limits

### Checks performed in this audit

| Check | Result | Meaning |
| --- | --- | --- |
| Application route inventory | 237 routes, including 12 platform routes | Routes were inspected alongside their handlers; route existence alone was not treated as proof of working behavior. |
| Existing automated suite | **172 tests, 3,138 assertions passed**, zero failures/errors/skips in JUnit output | Covers the implemented contracts, including tenant and role boundaries, finance, procurement, packing, sales, exports and integrations. |
| Default test invocation | Aborted at the local 128 MiB PHP memory limit | The first run exhausted memory during `SalesWorkflowTest`. It was not counted as a successful test run. |
| Full suite with explicit memory allowance | Passed using `php -d memory_limit=512M vendor/bin/phpunit` | Establish an explicit test memory budget in development and CI. This is not a measurement of production request memory. |
| Four temporary audit probes | **4 passed, 13 assertions** | These deliberately characterize the existing defects described below. Their passing does not mean those defects have been fixed. |
| Blade compilation | Passed | Templates compile; this does not establish browser usability or correct interactive behavior. |
| Public application JavaScript syntax | Passed for `public/assets/*.js` | Syntax checks do not establish browser behavior. |
| Composer advisory audit | No advisories or abandoned packages reported | A point-in-time PHP dependency check, not an OS/container security assessment. |
| npm advisory audit | Zero reported vulnerabilities | A point-in-time package check, including development dependencies. |

Installed versions inspected included PHP 8.4.12, Laravel 13.37.0 and PHPUnit 12.5.40. Tests used the project's SQLite in-memory configuration. Test mail used simulated transports; no customer or supplier messages were sent by this audit.

The four additional probes ran outside the repository and exercised: disabled-business supplier-page access; quotation submission through that link; the payment-option list with 76 unpaid supplier bills; and purchase creation with currency `ZZZ`. The finance list probe used isolated, directly arranged fixtures to test record selection, not a complete accounting lifecycle.

### What this audit does not establish

- No authenticated browser walkthrough, physical Android/iPhone PWA installation, accessibility audit or exporter user-acceptance session was performed. UI findings come from templates, scripts, routes and HTTP feature tests.
- No production database, live customer records, infrastructure configuration, external bank/carrier service, physical printer or actual inbox delivery was verified in this session.
- PostgreSQL application concurrency and production-scale performance were not tested. The existing PostgreSQL smoke script tests database locking primitives, not simultaneous application workflows.
- This is a product and implementation audit, not a financial-statement audit, penetration test or determination of current Ugandan legal, tax or export compliance requirements.
- Existing edits were present before this audit. Application code was not changed, committed, pushed or deployed for this request.

The [24 October production audit](PRODUCTION_AUDIT_2026-10-24.md) records deployment evidence and open infrastructure issues. Those are historical observations, not fresh verification. Its OS-library findings must not be treated as resolved merely because Composer and npm report no advisories today.

## 3. What is implemented

“Implemented” below means a real code path exists with relevant existing automated coverage. It does not mean the entire business requirement is satisfied or independently accepted in production.

| Business capability | Current implementation | Important boundary |
| --- | --- | --- |
| Multiple businesses | Business memberships, active business selection, role checks and explicit company-scoped queries | Tenant enforcement is distributed across controllers/services; every new public, background and offline path must preserve it. |
| Authentication and team access | Invitations, password recovery, role changes, staff suspension and production privileged MFA | Owner transfer, platform recovery/support and comprehensive invitation lifecycle controls are incomplete. |
| Platform administration | Create exporter/invite owner; onboarding requests; plans, subscriptions, platform invoices and manual platform payment recording | No complete superadmin dashboard, business lifecycle UI, measured usage/allowance dashboard or subscription-change lifecycle. SaaS subscription billing is separate from the exporter's own sales and finances. |
| Product catalogue | Products, units, low-stock thresholds, spreadsheet import/export, private product media | No complete unit-conversion, packaging-material consumption or warehouse-location model. |
| Customers and suppliers | Tenant-specific directories and active/inactive records | A customer currency is stored, but not selected from business-enabled currencies. |
| Quantity-only customer demand | Staff can enter customer requests with product quantities and no selling amounts | Confirmed orders require an issued, positively priced quotation. Customers cannot submit requests themselves. |
| Sales quotations | Currency, prices, discounts/tax inputs, revisions, issued document snapshots, print/PDF, staff-recorded buyer acceptance | No customer-facing quotation link, online accept/reject, automatic quotation delivery, or quotation FX/UGX snapshot. |
| Confirmed sales orders | Snapshotted quantities/prices and partial fulfilment across shipments | No operational unpriced order, sourcing plan, task assignment, normal cancellation/amendment or fulfilled-order closure. |
| Customer tracking | Expiring, hashed, revocable private order link sent through configured mail | Read-only confirmed-order tracking; not a customer ordering portal. |
| Supplier procurement | RFQs, invited supplier links, supplier quotation revisions and owner/admin award | A single award closes the whole RFQ, even when the selected supplier offers less than requested. |
| Market buying | Market, walk-in and registered-supplier purchase records | Each purchase has one product. No market trip, assigned buyer, buying budget, advance or cash accountability. |
| Receiving | Partial receipts, accepted/rejected quantities, net-weight validation, source batches and stock movements | No ordinary receipt correction, supplier return or short-close process. Rejected quantities count toward delivered purchase quantity. |
| Produce inventory | Signed movements, batch balances, source references, reorder alerts and quality holds | No customer-order reservation, stock count/adjustment, warehouse transfer, expiry or cold-chain workflow. |
| Packing | Jobs, multiple source batches per carton, weights/grades, sorting losses and completion locks | No order-line target, planned responsible staff, material consumption, repacking or normal carton correction. |
| Traceability and labels | Product/batch/carton QR identities; explicit public visibility; ZPL snapshots/history; print-agent queue | Actual printer calibration, paper output and device reliability require field verification. No visual label designer. |
| Export shipments | Air shipment fields, completed-carton allocation, dispatch/delivery, event history and configurable dispatch approval | Air is hardcoded. A shipment links to one sales order; no consolidation across orders. Updates remain manually recorded business events. |
| Export documents | Configurable packing lists/dispatch notes and commercial invoice snapshots/PDF | Clearance register stores internally reviewed references, not issuer validation or a configurable required-document dispatch gate. |
| Accounting | Tenant chart, source-document posting requests, independent approval, balanced original/base-currency journals | Posting is a separate manual sequence; operational documents do not automatically mean the books are current. |
| Manual FX | Dated manual rate proposal, independent approval, selected historical rate per journal | No automatic market feed is needed for the requested scope. Missing piece is connecting rates to commercial pricing and actual currency conversion. |
| Receipts and supplier payments | Partial allocation to posted invoices/bills, duplicate-request safeguards and reviewed posting | One document per payment; payment currency follows the document. No customer deposits, supplier advances or multi-document remittance. |
| Banking and corrections | Named bank profiles/currencies, CSV reconciliation, evidence checks, balance proof and controlled returns/reversals | No actual bank transfer initiation or complete cross-currency bank exchange workflow. |
| Inventory accounting | Supplier-bill cost basis, reviewed stock relief for shipment COGS and sorting waste | Partial purchases cannot be billed through the current one-completed-purchase bill flow. Cost basis excludes unassigned overheads. |
| Expenses and petty cash | Manually recorded paid shipment costs linked to finance posting | No complete general expense, employee reimbursement, recurring obligation or petty-cash float/voucher/count/reconciliation workflow. |
| Reports | Trial balance, profit/loss, balance sheet and account ledger in company base currency; provisional shipment contribution | Reports depend on complete, posted source records. Shipment contribution is not full order profitability. |
| Rotas and shifts | Individual staff shift scheduling/cancellation and overlap checks | No complete weekly rota/template/publication, leave/availability, required staffing/skills coverage or swap/replacement workflow. |
| Attendance | Clock-in/out, optional shift linkage and event records | No complete breaks, attendance correction/approval, absence/late exceptions and approved payable-time workflow. |
| Wages and payroll | Manually prepared gross/deductions, reviewed posting, payments and corrections | Attendance/output does not calculate wages. Staff are tied to active login memberships; separate casual-worker records, rate contracts and payroll-to-order labour allocations are absent. Automatic statutory calculations are absent. |
| Integrations and operations | HMAC provider evidence inbox, print agents, encrypted backup tools, readiness/release checks and acceptance records | Provider evidence does not automatically change shipment status or post money. Provisioning is not evidence of a live commercial integration. |
| PWA | Manifest linked from authenticated layout; standalone display metadata | Icons, service-worker behavior, offline fallback, draft sync, install/update experience and push subscriptions are absent. |

## 4. Prioritized findings

Priorities are delivery priorities, not a security severity score. **P1:** blocks the requested operating model or an important control. **P2:** needed for reliable regular operation. **P3:** subsequent improvement. “Reproduced” means an additional isolated HTTP probe confirmed the behavior. “Source-confirmed” means the limitation is directly visible in code/schema/routes. “Unverified” means an external outcome still needs evidence.

### F01 — Supplier links continue working after business suspension — P1, reproduced

`SupplierReplyController::context()` checks link expiry, RFQ status/deadline and active product/supplier, but does not check `Business::is_active`. `SupplierQuoting::record()` does not add that check.

**Reproduction:** arrange an active supplier and sent RFQ with an unexpired link; set its business inactive; access the public link and submit a quotation. The page returns 200 and the quotation/revision persists. The normal staff workspace, buyer tracking and public QR paths have active-business checks.

**Impact:** disabling a tenant does not consistently disable external access and writes. This is an incomplete suspension boundary, not evidence of cross-tenant data theft.

**Required correction:** enforce active-tenant state on every public-link read and mutation, including a transactional recheck before writes; test suspension/reinstatement and link revocation explicitly. Keep subscription-expiry policy distinct from security suspension policy.

**Evidence:** [SupplierReplyController](../backend/app/Http/Controllers/SupplierReplyController.php), [SupplierQuoting](../backend/app/Services/SupplierQuoting.php), [BuyerOrderAccess](../backend/app/Services/BuyerOrderAccess.php).

### F02 — Older outstanding finance records disappear from action forms — P1, reproduced

The finance documents page selects the latest 75 posted invoices and bills, then filters their balances in the view. It also limits fully received purchases to 75 before filtering out billed purchases. It provides no search/pagination for those action selectors.

**Reproduction:** create 76 unpaid posted supplier bills with distinct creation timestamps; open finance documents. Only 75 appear in `payableBills`; the oldest unpaid bill is absent from the rendered page while still outstanding in the database.

**Impact:** a growing exporter cannot select some older debts for payment through the normal form. Fully paid newer invoices can also crowd older unpaid invoices out of the receipt selector. Similar fixed limits occur in posting-review and bank-match candidate lists.

**Required correction:** searchable tenant-scoped selectors and queues, filter eligibility before pagination, show total counts, and provide direct source-record actions. Do not solve this merely by increasing 75 to a larger cap.

**Evidence:** [FinanceController::documents/reviews](../backend/app/Http/Controllers/FinanceController.php), [finance documents view](../backend/resources/views/finance/documents.blade.php), [FinanceBankController::show](../backend/app/Http/Controllers/FinanceBankController.php).

### F03 — Currency codes are free text without business configuration — P1, reproduced

Customer, purchase, quotation and rate inputs generally validate a currency as three alphabetic characters. There is no currency master or `business_currencies` relationship.

**Reproduction:** a market purchase with currency `ZZZ` is accepted and stored. No business currency activation or rate is needed at purchase creation.

**Impact:** typing mistakes create financial records that fail or become confusing later. There is also no enforced relationship between an exporter's accepted currencies, customer currency, quotation currency and bank currency.

**Required correction:** currency catalogue, business-enabled currencies, customer defaults, controlled currency changes and currency-aware formatting/rounding. Retain the existing bank currency checks and approved-rate selection.

**Evidence:** [PurchaseController::store](../backend/app/Http/Controllers/PurchaseController.php), [CustomerController](../backend/app/Http/Controllers/CustomerController.php), [FinanceRates](../backend/app/Services/FinanceRates.php), [FinanceBankProfiles](../backend/app/Services/FinanceBankProfiles.php).

### F04 — Customers cannot place orders, and unpriced demand cannot proceed as an order — P1, source-confirmed

There is one public buyer-order route, a GET for tracking. Staff requests are quantity-only, which is useful, but `SalesWorkflow::confirm()` creates orders only from issued quotations. Issuance requires every line to have a positive unit price.

**Impact:** the requested “customer asks for produce without amounts” workflow stops before sourcing and fulfilment as a confirmed order. There is no buyer-facing submission or quote decision.

**Required correction:** permit an approved operational order with pricing pending. Keep fulfilment status, pricing status and payment status separate. Store unknown prices as null rather than zero. Require final prices before commercial invoice issuance, with explicit approval for any earlier operational commitment.

**Evidence:** [routes](../backend/routes/web.php), [SalesWorkflow](../backend/app/Services/SalesWorkflow.php), [BuyerOrderPortalController](../backend/app/Http/Controllers/BuyerOrderPortalController.php).

### F05 — No connected order sourcing plan or split supplier awards — P1, source-confirmed

Purchases and supply requests have no sales-order-line reference. `SupplyRequestController::award()` creates one purchase and immediately marks the RFQ awarded; subsequent awards are refused. Partial supplier offers are allowed, so the remainder can be left without a sourcing workflow.

**Impact:** for an order of 1,000 kg, staff cannot formally plan 400 kg from supplier A, 350 kg from supplier B and 250 kg from their own market buyer. Notes and separate purchases cannot establish reliable fulfilled, outstanding and overcommitted quantities.

**Required correction:** order-line sourcing allocations that support supplier and market branches, partial awards, shortages, substitutions and quantity reconciliation. Allow one source purchase to serve more than one order where needed, through allocations rather than one direct foreign key alone.

**Evidence:** [PurchaseOrder](../backend/app/Models/PurchaseOrder.php), [procurement schema](../backend/database/migrations/2026_10_20_000005_create_procurement_and_receiving_tables.php), [SupplyRequestController::award](../backend/app/Http/Controllers/SupplyRequestController.php).

### F06 — Quotation pricing does not preserve the UGX view — P1, source-confirmed

Quotes/orders store one currency and their totals; they do not store an approved quotation FX reference, rate snapshot or base-currency totals. Internal order screens display the foreign totals. A quotation can also be changed to another three-letter currency without enforcing the customer's default.

**Impact:** a USD quotation does not give Ugandan users a fixed UGX pricing/cost comparison. Subsequent journal FX cannot reconstruct the exact commercial pricing assumption unless it was separately documented.

**Required correction:** issue the client document in the agreed client currency, and preserve the pricing FX assumption and UGX equivalent internally. Later invoice/settlement/revaluation rates must remain separate historical events. Never recalculate an issued quotation with today's rate.

**Evidence:** [sales schema](../backend/database/migrations/2026_10_20_000010_create_sales_order_and_invoices.php), [SalesWorkflow::updateDraft/issue/confirm](../backend/app/Services/SalesWorkflow.php), [order view](../backend/resources/views/sales/orders/show.blade.php), [FinancePosting](../backend/app/Services/FinancePosting.php).

### F07 — Market buying lacks advance and accountability controls — P1, source-confirmed

Market purchases record vendor/location, price and creator. There is no assigned market buyer, trip, approved budget, advance issued, expenses, returned cash or reconciliation to received goods.

**Impact:** the owner cannot answer “who took UGX 2,000,000, what did they buy, what reached the packhouse, and what cash remains?” from one controlled record.

**Required correction:** market-buying assignment and accountable advance flow linked to multiple purchases, receipts, expenses, returned funds and finance posting. Purchase creation and cash disbursement must be separate events.

**Evidence:** [PurchaseController](../backend/app/Http/Controllers/PurchaseController.php), [PurchaseOrder](../backend/app/Models/PurchaseOrder.php), [FinanceDocuments::payment](../backend/app/Services/FinanceDocuments.php).

### F08 — Responsibility and owner visibility are incomplete — P1, source-confirmed

Creators, receipt posters, packers and shipment actors provide useful history. Shifts/attendance exist. There is no shared operational assignment model with person, deadline, progress, blocker and handover against an order, sourcing allocation or preparation task.

The owner overview counts confirmed orders on record, ready batches, preparing shipments and pending finance reviews. Delivered orders remain `confirmed`, so this count is not an active-order backlog.

**Required correction:** a daily exception/task dashboard, one order workspace, explicit work ownership, and derived ordered/sourced/received/packed/dispatched/delivered quantities. Calculate open backlog rather than counting all confirmed records.

**Evidence:** [WorkspaceMetrics](../backend/app/Services/WorkspaceMetrics.php), [StaffOperations](../backend/app/Services/StaffOperations.php), [PackingJob](../backend/app/Models/PackingJob.php), [SalesFulfillment](../backend/app/Services/SalesFulfillment.php).

### F09 — Assets and loan management are absent — P1 for requested full scope, source-confirmed

There are no asset or loan domain models, migrations, controllers or routes. A financial account of type `asset` is an accounting category, not an equipment register. Supplier payables are not a loan subledger.

**Required asset scope:** equipment register, acquisition cost/currency, custodian/location, maintenance, depreciation policy, disposal and journals. Reusable crates and consumable packing materials need explicit classification rather than silently treating everything as produce.

**Required borrowing scope:** lender, facility/currency, drawdowns, repayment schedule, principal/interest/fees, balances, arrears, supporting evidence, payments and approved FX handling. Customer/supplier/staff advances must be distinguished from borrowing.

**Evidence:** [current models](../backend/app/Models), [FinanceSetup::ACCOUNTS](../backend/app/Services/FinanceSetup.php), [routes](../backend/routes/web.php).

### F10 — Payment timing does not fit common sourcing and export situations — P1, source-confirmed

Supplier bills require the purchase to be completely received and support one bill per purchase. Payments require an already posted bill/invoice. Customer invoice revenue cannot enter posting review until dispatch. Named-bank payments require matching document and bank currency.

**Impact:** no controlled supplier deposit, payment for an early partial delivery, customer prepayment, or actual USD-to-UGX bank conversion. Those omissions directly affect cash buying and export deposits. They should not be bypassed using false invoices or loose journals.

**Required correction:** deposits/advances and their later allocation; partial receipt billing or a documented short-close; one remittance allocated across documents; bank/cash transfers; separate currency conversion with actual sold/bought amounts and fees.

**Evidence:** [FinanceDocuments](../backend/app/Services/FinanceDocuments.php), [FinancePosting::request](../backend/app/Services/FinancePosting.php), [FinanceBankProfiles::account](../backend/app/Services/FinanceBankProfiles.php).

### F11 — PWA support is only a manifest foundation — P1, source-confirmed

The manifest has name/start URL/scope/display/colors but no icon entries. No service worker registration, offline page, local draft store, synchronization queue, install/update flow or push subscription implementation was found in application code.

The follow-up source review also confirms that the login/public layout does not link the manifest and the login screen has no installation invitation. Desktop/mobile login installation, installed/dismissed states and browser-specific fallbacks are now explicit early requirements in R13; they were too general in the original plan.

**Required correction:** installable application metadata/icons and device testing; a versioned static shell and offline fallback; visible network/sync state; controlled draft storage and replay. Offline stock availability must never become authoritative. Dispatch, money movement, approval and final stock deduction should require server validation.

Sensitive responses currently use `private, no-store`; do not undermine that by indiscriminately caching authenticated pages. Current permissions policy denies camera access, so future in-app QR scanning needs an intentional policy and user permission flow.

Installability and offline behavior are separate acceptance checks: a service worker is not universally required for browser installation, but is the standard mechanism for offline/background application behavior. See [MDN installability](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Guides/Making_PWAs_installable) and [MDN offline/background operation](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Guides/Offline_and_background_operation).

**Evidence:** [manifest](../backend/public/manifest.webmanifest), [console layout](../backend/resources/views/layouts/console.blade.php), [ProductionSecurity](../backend/app/Http/Middleware/ProductionSecurity.php), [public scripts](../backend/public/assets).

### F12 — Superadmin dashboard and platform business management are incomplete — P1 for multi-business rollout, source-confirmed

The observation that the super admin has little control is broadly correct for managing an existing exporter. The local version does add onboarding and SaaS subscription billing, so it is not limited strictly to business creation.

There is no complete platform landing dashboard with business/subscription totals, upcoming renewals, overdue platform invoices, usage trends/allowances and actionable tenant health. Missing controls include business profile corrections, suspend/reinstate with reason, owner invitation resend/revoke and delivery history, owner recovery/transfer, tenant health/usage and controlled support access. The company list displays active/suspended status without an action to change it. Plan/method management is also primarily creation-oriented rather than a complete lifecycle editor; plan versioning, agreement changes, explicit trial/grace/expiry management, usage measurement and quotas need a defined implementation.

**Required correction:** a dedicated superadmin workspace and business detail pages with subscription actions, reconciled usage counters, allowance warnings, health freshness and platform audit history. Make this an early delivery stage before wider exporter onboarding. A platform administrator should not gain invisible access to customer finance through a general bypass. Use explicit, time-limited, logged support access when needed. See the roadmap's [superadmin control centre](EXPORT_BUSINESS_ROADMAP_2026-10-27.md#2-superadmin-control-centre).

**Evidence:** [PlatformBusinessController](../backend/app/Http/Controllers/PlatformBusinessController.php), [platform businesses view](../backend/resources/views/platform/businesses.blade.php), [BoostedOneBillingController](../backend/app/Http/Controllers/BoostedOneBillingController.php).

### F13 — Normal correction and exception flows are incomplete — P2, source-confirmed

Issued quotations/invoices are snapshotted and completed packing is locked, which protects history. But ordinary workflows are missing for purchase cancellation/short-close, receipt reversal, rejected-stock replacement, stock counts/adjustments, unpack/repack, order amendment/cancellation and closure.

Full invoice credits exist, but require a posted invoice with no pending/posted payments. Pre-payment balance concessions and bank returns also exist; they are not a general solution to partially paid invoice corrections or a replacement invoice after a full credit. A shipment is limited to one commercial invoice and issued-invoice shipments cannot be reopened/cancelled through the current flow.

**Required correction:** explicit compensating workflows that retain original records, explain quantity/value effects and release reservations correctly. Offer the relevant correction from the source record rather than forcing staff to know the accounting module.

**Evidence:** [GoodsReceiving](../backend/app/Services/GoodsReceiving.php), [ShipmentOperations::reopen/cancel](../backend/app/Services/ShipmentOperations.php), [FinanceCreditNotes](../backend/app/Services/FinanceCreditNotes.php), [CommercialInvoiceIssuance](../backend/app/Services/CommercialInvoiceIssuance.php).

### F14 — Inventory is traceable but not a complete warehouse system — P2, source-confirmed

Batch stock has no warehouse/bin, harvest/expiry, storage condition or reservation dimensions. There are no stocktake, transfer or general adjustment workflows. Packing jobs are product-based rather than customer-order-based. A carton is reserved at shipment allocation, but loose produce is not reserved against an order beforehand.

**Required correction:** start with a default packhouse location, order reservations, counts/corrections, expiry/quality information where relevant, and consumable packaging stock. Keep source-batch identity through transfers and packing. Distinguish available loose stock, reserved loose stock, work in progress, packed, held and dispatched quantities.

**Evidence:** [StockBatch](../backend/app/Models/StockBatch.php), [StockAvailability](../backend/app/Services/StockAvailability.php), [PackingOperations](../backend/app/Services/PackingOperations.php), [ShipmentOperations::allocate](../backend/app/Services/ShipmentOperations.php).

### F15 — Order profitability and general operating finance are incomplete — P2, source-confirmed

Shipment contribution traces posted produce acquisition cost and recorded paid shipment costs against posted sales. Its explicit caveats exclude unassigned overheads. There is no complete order cost sheet, budget-versus-actual view or general operating expense/petty-cash workflow. Loan interest, asset depreciation and task labour/material costs are not integrated into a complete business cost model.

**Required correction:** complete general expense/claim, unpaid bill and petty-cash workflows; link actual costs to order/shipment/task where appropriate; allocate shared costs using a declared basis; show incomplete cost status; retain the distinction between margin estimates, posted financial results and bank cash. Extend COGS reconciliation across produce, classified acquisition/preparation inputs, waste and partial shipments, preserving the accounting policy and avoiding duplicate expense/payroll/material costs. Add receivable/payable aging, customer/supplier statements and cash forecasts appropriate to the exporter. See the roadmap's [expense and cost scope](EXPORT_BUSINESS_ROADMAP_2026-10-27.md#8-complete-financial-scope).

**Evidence:** [ShipmentContribution](../backend/app/Services/ShipmentContribution.php), [FinanceInventoryValuation](../backend/app/Services/FinanceInventoryValuation.php), [FinanceStatements](../backend/app/Services/FinanceStatements.php), [FinanceDocuments::shipmentCost](../backend/app/Services/FinanceDocuments.php).

### F16 — Export clearance is a reference register, not readiness enforcement — P2, source-confirmed

Clearance references and independent internal review exist. `ShipmentOperations::dispatch()` does not require a configured set of currently valid clearance records. The clearance form records text evidence, not uploaded original certificates.

**Required correction:** configurable document checklists by product/destination/business, private attachments and expiry/status checks, responsible reviewer and an explicit dispatch gate/exception reason. Obtain current requirements from the actual issuing authorities and export partners when defining each corridor; do not encode assumed statutory rules from this audit.

**Evidence:** [ExportClearanceRegister](../backend/app/Services/ExportClearanceRegister.php), [ExportClearanceController](../backend/app/Http/Controllers/ExportClearanceController.php), [ShipmentOperations::dispatch](../backend/app/Services/ShipmentOperations.php).

### F17 — Too many destinations and stale feature descriptions — P2, source-confirmed

The owner sees nine workspace sections plus more than thirty separate tool links, with many tools repeated on the overview/section pages. Finance is split across numerous standalone destinations. Release acceptance and provider evidence are prominent routine navigation items.

Some section pages still advertise planned features; settings mentions locations, warehouses, push and offline, while the actual settings endpoint edits only the QR default and two operating policies. Early README sections say journals, payments, staff workflows and buyer access are pending, while later sections describe their implementation.

**Required correction:** one current feature-status source, no non-actionable feature cards in normal daily work, six primary work areas, nested advanced tools, and a persistent next action on each order. Simplify navigation without removing permissions, snapshots or financial controls.

**Evidence:** [console layout](../backend/resources/views/layouts/console.blade.php), [workspace view](../backend/resources/views/workspace.blade.php), [BusinessSettingsController](../backend/app/Http/Controllers/BusinessSettingsController.php), [README](../README.md).

### F18 — Audit history and tenant enforcement need broader coverage — P2, source-confirmed

Finance, team membership, stock quality, attendance and shipments have useful event records. There is no unified searchable tenant/platform audit history covering all master-data, policy, invitation and support changes. Many models use ordinary unscoped Eloquent queries; individual controllers/services supply tenant filters. Composite tenant foreign keys provide valuable defense for many operational relationships.

**Required correction:** centralize tenant context and resource authorization while retaining database constraints; cover public links, queued work, imports, exports, attachment access and future PWA caches. Add an append-oriented event stream with actor, entity, action, timestamp, reason and appropriate before/after data, excluding secrets. Add separate controls for platform/support activity.

This is a maintainability/control gap, not a claim that every unscoped model is currently exploitable. [OWASP's multi-tenant guidance](https://cheatsheetseries.owasp.org/cheatsheets/Multi_Tenant_Security_Cheat_Sheet.html) supports enforcing tenant context consistently across data access and application boundaries.

**Evidence:** [ResolveActiveBusiness](../backend/app/Http/Middleware/ResolveActiveBusiness.php), [BusinessPermissions](../backend/app/Support/BusinessPermissions.php), [TeamAccessController](../backend/app/Http/Controllers/TeamAccessController.php), [OperatingPolicyController](../backend/app/Http/Controllers/OperatingPolicyController.php).

### F19 — Reliability and scale need application-level evidence — P2, source-confirmed/unverified

Emails for invitations, supplier requests and tracking are sent synchronously. Supplier request issuance records failed sends but has no complete retry/resend lifecycle. Business creation and sending its owner invitation are not one recoverable onboarding workflow. Readiness, release acceptance and encrypted backup mechanisms exist, but those do not prove this client's actual workflows have been accepted.

Packing loads every batch of a product and queries availability for each. The overview traverses stock movement history; financial reports load ledger lines into application memory; several selectors use hard limits. These are scale risks requiring measurement, not measured performance failures in this audit.

**Required correction:** durable queued/outbox delivery with visible failure/retry states; tenant-scoped searchable records; aggregate/chunked reporting as justified by measurements; PostgreSQL concurrency tests at service level; restore rehearsals and monitored incident delivery.

**Evidence:** [InviteBusinessUser](../backend/app/Services/InviteBusinessUser.php), [SupplyRequestController::issue](../backend/app/Http/Controllers/SupplyRequestController.php), [PackingJobController::show](../backend/app/Http/Controllers/PackingJobController.php), [FinanceStatements::amounts](../backend/app/Services/FinanceStatements.php), [PostgreSQL smoke test](scripts/postgres_concurrency_smoke.sh).

### F20 — Small-business onboarding does not prepare users for approvals — P2, source-confirmed

Finance rates require a second approver; posting requests require a different reviewer; the reviewer cannot also post the journal. This can operate with two appropriately authorized people, with the preparer also posting after independent review. An owner alone cannot complete it. Owner-only dispatch policy controls and mandatory finance controls have different rules.

**Required correction:** an onboarding checklist that sets up the owner, accountant/second reviewer, UGX base, enabled currencies, money accounts, opening data and a small working example. Put approvals into a single “Needs approval” inbox and show exactly which person can act next. Keep independent controls; do not solve onboarding difficulty by silently permitting self-approval.

**Evidence:** [FinanceRates::approve](../backend/app/Services/FinanceRates.php), [FinancePosting::review/post](../backend/app/Services/FinancePosting.php), [BusinessPermissions](../backend/app/Support/BusinessPermissions.php).

### F21 — Full rota and attendance-to-wages workflow is incomplete — P2, source-confirmed

Individual shifts and clock-in/out are implemented, but their presence is not a complete rota/workforce module. Shift records hold a user, times, optional location/notes and status. Attendance records hold clock-in/out, shift and work note. The current payroll prepares from manually entered gross/deductions for active business members; it does not derive wages from approved attendance or verified packing output.

**Impact:** the owner cannot plan required packhouse coverage, reconcile planned and actual work, manage leave/replacements or explain a daily/hourly/piece-rate worker's earnings and order labour cost through one connected workflow. A casual worker currently needs membership in the application's staff model to enter payroll.

**Required correction:** staff records independent of login, weekly rota templates/publication, availability/leave, staffing/skills coverage, shift changes, attendance breaks/exceptions/corrections, approved timesheets/output, effective-dated rates and approved payroll inputs. Allocate labour to orders from those same approved sources without posting wages twice. Keep employees' everyday view limited to their shift, attendance and assigned work. See the roadmap's [People workstream](EXPORT_BUSINESS_ROADMAP_2026-10-27.md#9-people-rota-shifts-attendance-and-wages).

**Evidence:** [StaffController](../backend/app/Http/Controllers/StaffController.php), [StaffShift](../backend/app/Models/StaffShift.php), [StaffAttendance](../backend/app/Models/StaffAttendance.php), [StaffOperations](../backend/app/Services/StaffOperations.php), [FinancePayroll](../backend/app/Services/FinancePayroll.php).

## 5. Readiness decision

The current implementation is suitable as a development foundation for the requested system. The existing automated suite is useful and should be retained. It cannot establish completeness against requirements that are not yet represented in the schema, routes or tests.

Before an exporter depends on it for daily operations, demonstrate one real business scenario end to end: quantity-only customer demand; multiple suppliers plus staff market sourcing; advances and receipts; accountable staff tasks; losses and packing; partial shipment; client-currency quotation with frozen internal UGX pricing; receipt/FX settlement; and reconciled books. Add assets and loans before claiming the full requested scope is complete.

The companion roadmap gives the proposed simple user experience, backend relationships, delivery sequence and acceptance criteria for that demonstration.

## 6. Owner-control re-audit and additional delivery gates

The follow-up [owner-control review](EXPORT_OWNER_CONTROL_REVIEW_2026-10-27.md) re-inventoried 237 routes and reran four relevant existing test files: **23 tests and 253 assertions passed**. It inspected supplier/receiving reports, shipment transport fields, finance statements/due-date sources, labels/print agents and the public login layout. No real printer, production system or installed-device test was performed; these checks do not constitute implementation of the additional requirements.

Additional source-confirmed control gaps include a supplier/date reporting and partner-history workflow; transport legs/vehicle/driver/custody rather than only an airline text field; export invoice/bill due-date and credit data rather than SaaS billing terms; current/fixed asset and liability-maturity classification; daily spending/cash close/funding; ongoing owner capital/drawings/loans; and a cutover that reconciles operational opening data as well as ledger totals.

Printing is now an early P1 operating gate. Existing bounded paper dimensions, ZPL snapshots and a leased agent queue are real, but do not supply printer/media profiles, configurable visual layouts, bulk-print manifests, private internal QR, required/stale-label validation, applied-label verification or confirmed physical paper output. The existing latest-60 print-generation selector also needs full searchable history. See R11/R12 and acceptance A42–A47.

The roadmap retains six primary exporter areas and a distinct superadmin workspace. The new owner question/metric contracts, simple financial commands and superadmin service-owner questions turn the existing module plan into explicit operational acceptance criteria. R01–R15 and A36–A53 identify the extra scope; none should be described as complete merely because related routes already exist.
