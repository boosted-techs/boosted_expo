# Boosted Export ERP

Independent, multi-business agricultural-export ERP developed by Boosted Technologies.
**Hosting and public website are live at https://boostedone.online. Client financial/export operations still require business acceptance and review of the open findings in [the deployment audit](deploy/PRODUCTION_AUDIT_2026-10-24.md).**
This project does not share a codebase or database with Boosted+ POS.

## Local development

Laravel 13 / PHP 8.4 with a responsive Blade frontend and a distinct export-green
variant of Boosted+ POS's design palette. SQLite is used locally. Production uses dedicated PostgreSQL and Redis containers
inside an isolated rootless Docker service; hosting controls and remaining findings
are documented in the deployment audit.

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

## Current feature inventory

The [export-business roadmap](deploy/EXPORT_BUSINESS_ROADMAP_2026-10-27.md#14-delivery-sequence-and-exit-criteria) records current working/partial/missing scope and delivery gates. Phase 0 is implemented locally: suspended supplier links are blocked, older finance candidates are searchable and paginated, and owners control permitted business currencies. The suite passes 201 tests with 3,230 assertions. Later stages remain open, beginning with priority sticker printing and installation at login.

### Existing operational modules

These modules have working functionality; their presence does not imply completion of every ERP workflow in the roadmap.

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

## Financial control, ledger and shipment contribution — October 2026

This phase adds a conservative, business-isolated first-release accounting
workflow. It is **not** a fully audited accounting, bank-feed or tax compliance
system, and it is not safe to use as the only financial book until acceptance
testing, production database concurrency tests and policy review are complete.

### Finance setup and rates

- Business owners/admins activate a company-specific standard chart. Accounts:
  cash, bank/electronic, trade receivables, inventory, payables, sales tax,
  export sales, COGS (reviewed dispatch write-offs), shipment expenses
  and realised foreign-exchange gains/losses.
- The company's existing `base_currency` determines reporting currency.
- Accountants record dated manual FX rates with a source note; a different
  authorised owner/admin must approve each rate. Approved rates are immutable.
  There is **no live market rate feed** and no arbitrary default for foreign
  currencies. Each journal preserves its approved historical conversion rate.
- Chart balances are summed from immutable, double-entry journal lines using
  decimal arithmetic. Journal amounts balance in **both source currency and
  company base currency**.

### Financial source documents

- Supplier bills: prepare after a purchase order is fully received. One bill
  per order is calculated from actual *accepted* produce quantity times that
  PO's unit price, not from rejected produce or the estimated original total.
  Supplier-document reference and date are recorded. Partial supplier-billing,
  bill amendments, taxes, advance payments and supplier returns are future work.
- Customer receipts: allocate partial or full manual receipts in the original
  posted commercial invoice currency; pending payments reserve the remaining
  balance, preventing duplicate allocation. External reference is mandatory.
- Supplier payments: the same controls apply to approved, posted supplier bills.
  Cash and bank/electronic accounts are selectable.
- Shipment expenses: record freight, packing material, ground transport,
  handling, airline, inspection or miscellaneous *paid* costs with a document
  reference and selected cash/bank account. Expense evidence is **not** verified
  by a bank/payment provider.

All new source records stay pending/unposted until independent approval.

### Three-step posting and audit

1. Accountant or authorised staff proposes the source transaction, journal
   date, exact approved FX rate and explanatory evidence.
2. A **different** authorised reviewer approves or rejects it, with reasons.
3. An authorised poster **other than the approver** posts balanced debit/credit
   lines transactionally and locks the source against reposting. All actions
   are recorded in an immutable-sequence approval event log. There are no
   arbitrary direct journal-edit endpoints in this release.

A revenue-recognition proposal requires the shipment to have a manually
recorded dispatched/delivered status. The reviewer must still independently
check the Incoterm, transfer evidence and company's accounting policy; the
shipment status is **not** independently verified by the carrier. Invoices
are **not** automatically recognised on issuance. Income is posted as trade
receivables vs export sales and separately records manually calculated invoice
tax as a liability. Freight/insurance billed to customers are included in the
initial export-sales account; category-specific revenue separation is planned.

Supplier bills post inventory vs trade payables. A customer receipt settles
receivables against cash/bank; a supplier payment settles payables against
cash/bank. When invoice/bill and payment posting rates differ, realised FX
gain/loss is recorded as base-currency-only balancing journal lines. Exact
decimal amounts are used, and the final partial payment consumes any remaining
base-currency rounding difference. Posted entries cannot be edited/deleted
through the application. **Reversals and controlled correction workflows are
still pending**, so this is not yet a production accounting system.

### Shipment contribution

Under an individual shipment, **Shipment contribution estimate** shows:
- Posted export-sales revenue converted to base currency.
- Packed carton contents traced to the accepted goods receipt and supplier
  purchase order. Cost is estimated from consumed quantities and the unit
  price of posted supplier bills at their locked FX rates.
- Separately posted direct shipment expenses at locked rates.
- A provisional contribution only if all known inputs are posted and traceable.

Missing invoices, unposted costs, unmatched source batches or unpaid/billed
produce are flagged rather than silently assigning zero cost. The estimate
excludes sorting waste reallocation, statutory stock valuation differences,
payroll, overheads and other costs. **It is not an audited P&L, tax result,
cash-flow result or recognised margin.** Recording expenses and receipts
does not verify payment settlement. Manual bank-CSV matching is available,
but live bank validation and opening-balance reconciliation remain pending.

### Known next steps

Live bank/mobile-money feeds, verified bank opening balances,
partial supplier bills, partial credits, refunds, historical inventory and
COGS adjustments, indirect wastage/overhead costing, manual journal
adjustments, company-defined recognition policies, approval matrix
configuration, tax compliance, fiscal periods/closing, budget control,
foreign account revaluation, cash advances, expenses reimbursement and
audited/statutory P&L and balance sheet reports.

Migration `2026_10_20_000014_create_finance_ledgers` is additive only.
The current pass runs locally and was **not** pushed or deployed. After review:

    cd /opt/homebrew/var/www/boosted_expo/backend
    php artisan migrate
    php artisan view:cache
    php artisan test

No migrations were reset and no real-money transactions were initiated.

## Bank reconciliation, batch valuation, full credits and management reporting — October 2026

The next finance phase was implemented locally using additive migrations:

- `2026_10_20_000015_create_finance_reconciliation_inventory_and_credits`
- `2026_10_20_000016_index_packing_waste_tenant_key`

No earlier migration was reset or business data deleted. The existing
Boosted+ POS system was not accessed or changed.

### Bank statement reconciliation (manual, reviewed)

A business accountant may import a CSV of up to 2,000 lines (1 MB). The exact
UTF-8 header is:

    date,direction,amount,reference,description

Example:

    2026-10-20,credit,250.00,BANK-123,Customer transfer received
    2026-10-20,debit,50.00,BANK-124,Airline handling charge

Dates must be valid YYYY-MM-DD values within the supplied statement period.
Amounts are positive with up to two decimals. The form supplies statement
currency, opening balance and closing balance. An import only succeeds if:

    opening + credits - debits = closing

The same file cannot be imported twice for the company's bank account.
Transaction lines are stored for audit; no bank account API or feed is connected.
An accountant can propose a match to a **posted** supplier/customer payment or
posted shipment expense with the same currency, bank account, direction and
exact amount. A separate authorised owner/admin reviews or rejects it.
Rejected suggestions can be corrected; audit events retain the original source.
Once every imported line is independently confirmed, a person other than the
importer can close the statement.

**Important:** Matching does not create or change journal entries or bank
balances. The CSV is not authenticated by a bank. The standard first-release
chart has one generic electronic/bank account. Multi-account reconciliation,
bank-specific routing numbers, import provenance/signature verification,
automated matching, bank charges without ERP source records, unmatched-item
write-offs and cross-currency bank conversions are not yet supported.
The statement's bank balance is not automatically reconciled to a validated
financial opening balance; report them separately until that workflow exists.

### Source-batch inventory valuation and COGS

Finance can prepare an immutable costing document for a **dispatched** shipment
from each carton content's originating stock batch, or a separately recorded
sorting/waste entry. Each batch requires a goods receipt and a **posted**
supplier bill. The initial cost method is the purchase order's unit price
multiplied by the documented quantity, converted at the posted supplier bill's
locked exchange rate, with a final-unit rounding reconciliation. Costing
snapshots preserve batch, supplier bill, quantity and rate references.

Every quantity is reserved across earlier valuation documents (pending or
posted). It is not possible to cost more financial units than were received
and accepted for that batch. After review, COGS posts:

    Debit Cost of Goods Sold
    Credit Produce Inventory

Packing losses post:

    Debit Inventory Sorting & Spoilage Loss
    Credit Produce Inventory

Existing companies can use **Finance & Ledger → Add missing finance accounts
safely** to install the new waste-loss account without replacing past entries.

**Important:** Stock costing supports the current source-batch PO-unit-price
method. It does not yet include actual warehouse-to-COGS adjustments for
historic shipments that predate traceability, weighted-average/FIFO policies,
cost revaluation, subsequent supplier debit notes, indirect packing wages,
production overhead, shrinkage audits or statutory inventory accounting
certification. Real operational packing consumption remains separate from
financial inventory relief. Confirm these amounts before recognising margin.

### Full-value commercial credit notes

Finance can prepare a full credit against an existing **posted** commercial
invoice only if there are no pending or posted customer receipts allocated
to that invoice. The original document is retained unchanged. The correction
requires a separate proposal, independent approval and an authorised third
posting step. Only once posted does it generate the issued credit-note
HTML/PDF, reverse each original journal line with the exact source amounts and
original exchange-rate snapshots, and label the original invoice as credited.

A credit note does **not** return packed cartons, reverse shipment delivery,
create a replacement invoice, refund customers or certify tax and customs
credits. Partial credits, invoice corrections before first posting, refunds,
supplier credits, returns to inventory and credit-note replacement workflows
remain pending. Do not issue credits externally without checking applicable
accounting, VAT and customs requirements.

### Financial management reports

**Finance → Financial Reports** offers period-filtered income and expense
accounts (profit and loss), cumulative assets/liabilities and unclosed net
income (a draft financial-position overview), a debit/credit trial-balance
check, and dated account ledgers with running balances. All numbers come
from **posted** journal lines in the business's base currency, not issued
invoices or proposed payments. Date filtering includes both period endpoints.

A zero difference is an arithmetic check, **not** proof that books are
complete. Opening balances, retained earnings closure, historical COGS,
payroll accruals, depreciation, FX revaluation, regulatory tax adjustment,
bank-controlled proof, and fully audited P&L/balance sheets are still pending.
Staff must avoid presenting these provisional management reports as official
financial statements or tax returns.

### Roles and verification

- Accountant: import statements, propose matches, prepare valuation and full
  credit notes, request accounting postings, view the financial reports.
- Owner/admin: independently confirm bank matches, approve source journals,
  and maintain standard chart-of-accounts settings.
- The original ledger posting separation rule still applies: requester
  differs from approver; journal poster differs from approver.
- All business records are scoped to the active company. Customers, external
  partners and general viewers do not gain accounting access.

Verification from the `backend` directory:

    php artisan migrate
    php artisan view:cache
    php artisan test
    php artisan route:list --path=finance

This local pass does not push to GitHub, deploy to production, connect to a
real bank or initiate any financial transfer.


## Finance controls: opening equity, bank ledgers, period locks, concessions, payroll — October 2026

This phase adds migration \`2026_10_20_000017_create_finance_controls_payroll_and_adjustments\`.
It preserves prior migrations, business records, procurement/shipment history,
the earlier financial posting controls and original Boosted+ POS code. It was
developed **locally** and has not been committed, pushed, or deployed.

### Opening balances and equity

- An authorised finance employee can prepare one **balanced** opening-balance
  document per business with a specified effective date, debit/credit account
  lines, a frozen, hashed audit snapshot and independent journal approval.
- The date cannot be after the company's first posted journal. Asset,
  liability and opening-equity accounts are supported in company base currency.
  Journal entries remain immutable; they appear in account statements and
  the financial-position overview.
- Approval and posting follow the established independent reviewer workflow.
- Opening amounts in foreign-currency bank accounts are deliberately blocked:
  translating a foreign bank opening requires both source-currency balance and
  approved FX evidence. Do not manually enter a foreign-currency amount as if
  it were the base currency.
- A mistaken opening draft currently **cannot be replaced or corrected** in
  the UI. Check source evidence carefully before submitting; production
  onboarding still requires controlled void/revision capabilities.

### Per-business bank accounts

- Authorised business owner/admin can create named bank-ledger profiles
  with institution, currency, an **optional masked** reference (never full
  sensitive bank credentials), and a separate asset account in the chart.
- Newly recorded bank/electronic receipts and shipment-cost disbursements must
  select an actual named bank ledger when profiles exist. The document currency
  must match that bank profile's currency; currency exchange/transfers are not
  silently inferred.
- Imported bank statements can select the same account. Existing bank
  transactions remain on the original generic bank ledger and may be
  reconciled using an explicit **legacy** selection; no history is migrated or
  combined silently.
- No bank account API is linked. Statements are manual evidence only and must
  be independently matched to previously posted source transactions.
- Bank statement currency, multi-account opening balances, bank fees, FX
  transfers, named-currency revaluation and statement-vs-ledger opening
  reconciliation still require further production work.

### Accounting period controls

- A business can create **non-overlapping periods within one calendar month**.
  The end date cannot be in the future.
- Closing is blocked while financial source documents, approval requests or
  eligible imported statement reconciliations remain unfinished through the
  closing date.
- An accountant submits a reason for closing. A different authorised owner or
  admin approves or rejects. The request records a trial-balance digest and
  journal count to ensure nothing changed between submission and approval.
- Pending and closed financial periods reject new backdated journal postings
  at the **central FinanceLedger** level, including proposals/approved requests
  from other modules. A rejected close becomes open again with audit history.
- A closed period has **no automatic reopen**; controlled adjustments and
  formally reviewed exceptional reopening would be a later feature. The
  period-control service also prevents creating earlier periods after a
  later one is closed.
- Period closing is not statutory closing or a closing/retained-earnings
  transfer journal.

### Customer and supplier balance concessions

- A **partial** customer concession can adjust a posted *zero-tax* commercial
  invoice **before** any payment is pending or posted. It debits export
  revenue and credits the open receivable, using the original approved FX rate.
- A partial supplier price concession can reduce a posted supplier bill before
  any supplier payment, carton packing, or recorded packing loss. It debits
  payable and credits inventory at the bill's original approved FX rate.
- Each adjustment is frozen with source reference, amount, original rate,
  explanation and SHA hash. It requires the normal independent journal
  proposal/review/posting sequence. Repeated adjustments to one invoice/bill
  are blocked in this first-release path.
- While an adjustment is still unposted, related new payments and source-stock
  costing are held. Once posted, document outstanding balances and supplier
  source-batch unit costs reflect the reduced value. Double collection,
  overpayment and full-credit conflicts are blocked.
- Adjustments do **not** create a new tax invoice/credit note or refund the
  buyer, do not settle bank records, and do not permit adjustments of
  consumed packed stock. They are internal reviewed financial concessions.
- Controlled draft voids, tax/VAT allocation, post-payment adjustments,
  partial returns, multiple credits and supplier claims are pending.

### Staff payroll accruals

- HR and finance can prepare a monthly company payroll for **active business
  team members**. Gross and deductions are manually entered in base currency;
  repeated employees and overlapping periods are rejected.
- Finance approval posts balanced journals:
  * **Debit Payroll Expense** — gross salary.
  * **Credit Net Payroll Payable** — net wages due.
  * **Credit Payroll Withholding Payable** — manually entered deductions.
- Payroll does **not** initiate salary payments, produce payslips, settle
  withholding, compute Uganda PAYE/NSSF, link automatically to clock-ins,
  calculate overtime/piece-rate, or report to tax/regulatory services.
  Approvals and journal integrity are retained, but these are **accruals only**.
- Payroll employee-line amounts and original run summaries are checked against
  the frozen snapshot again at posting time; a person cannot silently modify
  an approved payroll.

### User interface and controls

- Finance & Ledger → **Accounting Controls**: business banks, opening
  balances, monthly periods, partial customer/supplier concessions.
- Finance & Ledger → **Staff Payroll**, and Staff workspace → Payroll.
- Finance posting reviews show the new opening, concession and payroll
  source documents along with the earlier invoice/expense/COGS records.
- Finance reports include opening equity when calculating assets vs
  liabilities plus equity and cumulative, unclosed income.
- Role grants protect company finances: owner/admin manage bank accounts and
  independent closing decisions; accountant prepares finance documents and
  journals; HR only sees/prepares payroll, not the general finance ledger.

### Verification and limitations

Run from the Laravel backend:

    cd /opt/homebrew/var/www/boosted_expo/backend
    php artisan migrate
    php artisan view:cache
    php artisan test --compact

This is **not yet a production-ready accounting or payroll system**.
Especially before real money use, complete: controlled corrections/voids and
credit-note tax rules; verified bank opening balance and bank-feed matching;
payroll payment and withholding remittance; regulated PAYE/NSSF computations;
accountant-approved policy configuration; inventory historical onboarding,
comparative opening and closing accounts; proper VAT/customs review; external
security and concurrency reviews; statutory financial-period close/reopen
governance. No transactions with bank/payment providers were performed by
this phase.


## Reviewed payroll settlements and controlled corrections — October 2026

This local finance phase adds migrations numbered 000018 to 000021: settlement records, immutable evidence snapshots, payroll revisions after reversal, and correction audit events. Earlier business records and accounting journals are preserved; no migrations were reset.

### Payroll settlements: externally evidenced, not initiated by the ERP

From Staff Payroll, finance records a staff salary payment or a remittance of manual deductions that already has a bank/cash voucher. Each proposal is tied to the company, the posted payroll accrual, a specific employee payroll line (salary) or the deduction total (remittance), the actual bank/cash account, date, external reference, recipient and evidence note. Unposted proposals reserve amounts. Any excess above the employee's net salary or the available withholding total is rejected. Duplicate idempotency keys may be replayed only with identical source details.

An independent reviewer authorises the transaction; a different authorised accountant posts the balanced journal. Salary payments debit Net Payroll Payable and credit Bank/Cash. Recorded remittances debit Payroll Withholding Payable and credit Bank/Cash. Original amounts, bank account, currency, date and evidence are protected by a SHA-256 snapshot revalidated before posting. Bank statement debits can be manually matched against posted payroll settlement entries with independent confirmation and exact currency, amount, direction and ledger-account checks.

**The ERP never sends a payment through this flow.** Manual evidence is not bank-verified confirmation; statutory deduction computations, PAYE/NSSF reporting, automatic payment initiation and payslips remain outstanding. Only the company's base currency is currently allowed for these settlement postings, with named bank account currency matching mandatory.

### Payroll accrual reversals and revisions

An accountant may prepare a full corrective reversal of a posted payroll only when no staff payment or withholding remittance is pending or posted. The original approved journal is snapshotted, then a new reversing journal is submitted for independent review. Once approved and posted, the original payroll is marked reversed but its history is never deleted. A subsequent payroll for the exact same period can be prepared as revision 2 (or later) and must go through the normal independent posting process. Replacement is subject to financial-period locks; formally closed periods cannot be backdated.

A proposed, unposted reversal may be withdrawn only before independent review begins or after the reviewer rejects it. Withdrawal and reactivation are audited with actors and reasons; reactivation preserves the original signed journal snapshot. While a reversal is active, no further payroll settlement proposals are allowed. Once withdrawn, settlement actions become available again.

This correction flow does not reverse previously paid salaries, refund employees, undo a bank transaction, amend statutory filings, or reopen a closed financial period. Those cases require a separate future settlement-correction and compliance process.

### Screens and verification

The Staff Payroll detail screen shows employee-level available net pay, withheld balance, manually recorded payment status, correction actions and audit events. Finance Posting Reviews includes pending payroll settlements and accrual reversals. Bank Statement Review supports matching posted payroll bank debits.

Local verification commands:

    cd /opt/homebrew/var/www/boosted_expo/backend
    php artisan migrate
    php artisan view:cache
    php artisan test --compact
    php artisan route:list --path=finance

This is local development work. No Git commit or push, production deployment, live salary transfer, PAYE/NSSF calculation or remittance was performed.


## October 2026 finance controls: posted-payment corrections, foreign bank opening and ledger proof

This local phase adds migration 2026_10_20_000022_create_finance_transaction_reversals_foreign_openings_bank_proofs.php. It is additive: historic financial documents, snapshots, journal lines, chart configurations and earlier migrations remain intact. Changes are local and uncommitted.

### Reviewed correction of a previously posted payment

Finance & Ledger > Payment Corrections permits a reviewed full-journal reversal of customer receipts, supplier payments, shipment-cost disbursements, and employee salary/withholding settlements. Finance must document the accounting error or an independently evidenced return. This action is **not** a bank refund, money transfer, salary recovery or external supplier credit.

The source payment must remain posted, company-owned, and free of pending or confirmed bank-statement matches. For customer and supplier instalments, staff must reverse newer posted instalments first and resolve other pending payments. The original journal, date, source account, original and base-currency values, historical exchange rate, external reference, correction explanation and evidence are hashed. Another authorised person approves the new reversing journal. The original journal remains untouched. Only after posting is the original source marked reversed; document outstanding balances, inventory-independent shipment cost reporting and ledger figures then reflect the reversal.

When an actual source transfer and separately evidenced counter-transfer occur, the original source, though later reversed, can still be matched to the original bank movement; the approved correction can be matched to the opposite bank movement. This retains both sides of the bank transaction history, not a deletion. Previously bank-matched source payments cannot use the ordinary correction flow: a dedicated controlled bank correction must be implemented first.

### Named foreign-currency bank opening

Accounting Controls > Foreign-currency bank opening permits one positive historical balance per company-owned named foreign-currency bank account before that account has any posted entries. The accountant must supply the original foreign amount, rate-backed base-currency valuation, historical bank evidence and an independently approved rate effective by the opening date. An approved journal records the bank asset in original currency and its translated base value, with a matching opening-equity entry. It neither exchanges nor transfers real money.

Do not include the same bank equity twice in base opening balances and foreign opening. Existing active bank ledgers, overdrafts, currency revaluations, corrections to posted openings, FX transfers and mixed-currency legacy accounts require separate controlled workflows.

### Independent bank-to-ledger proof

A bank statement marked **reconciled** has had its imported rows matched to source vouchers; it is NOT yet proof of book balances. The named-bank statement screen adds a separate independent accounting check that compares original-currency opening and closing balances with the dated posted ledger, and verifies that every ledger bank movement in that period corresponds to a confirmed bank line of equal amount, direction and source.

A frozen snapshot and journal fingerprint are reviewed by a different authorised staff member. If balances, currency or movement coverage disagree, the action is blocked without a silent balancing journal. A proof becomes stale if later bank journal movements change the supported figures. A named-bank statement without current independent verification blocks monthly financial closing. Legacy mixed bank ledgers cannot be certified through this workflow without historical reconstruction.

The uploaded CSV is STILL unverified bank evidence. Passing this internal bookkeeping check is not authentication by any bank, proof of available funds or audited settlement confirmation.

### How to review

- Finance & Ledger > Payment Corrections: original payments, correction reasons, original journals and approval status.
- Finance & Ledger > Accounting Controls: foreign-currency bank opening preparation and historical register.
- Finance & Ledger > Bank Reconciliation > Statement: book vs CSV opening/closing variances, unmatched movements and independently approved proof.
- Finance & Ledger > Posting Approvals: new source types reuse the existing segregation of request, independent approval and posting.

To verify locally from the backend:

    cd /opt/homebrew/var/www/boosted_expo/backend
    php artisan migrate
    php artisan view:cache
    php artisan test --compact

Remaining: real bank-feed authentication and ownership validation, refunds/returns of bank-matched transfers, statutory tax/VAT corrections, year-end retained earnings, periodic foreign-currency revaluation, independently verified bank adjustments, supplier credit/refund accounting, payroll tax and bank API settlement, and production-specific security and concurrency review.

No Git commit, push, production deployment, customer refund, salary disbursement, real bank transfer or tax payment was initiated in this phase.


## October 2026: reviewed reconciled-bank returns and non-cash FX revaluation

This phase adds additive migration `2026_10_20_000023_create_bank_returns_and_fx_revaluations.php`, new finance approval source `fx_revaluation`, and new company chart accounts `7130 Unrealised Foreign Exchange Gain` and `7140 Unrealised Foreign Exchange Loss`.

Changes are **local only**. No previous accounting journals, imported bank statements, financial documents, payroll records, labels, user data or earlier migrations were replaced or deleted. The existing finance setup process safely adds the two new chart accounts when an authorised owner runs **Finance Dashboard > Add missing finance accounts** for a previously onboarded business.

### Correction of a previously reconciled bank transaction

A posted customer/supplier payment, shipment cost or staff settlement that has an active confirmed bank match cannot be casually reversed or removed. To prepare a correction involving a genuine bank return:

1. Finish independent matching and reconcile the **original bank statement**. The original account must be a company-owned, single-currency **named bank**, not the generic mixed-currency legacy ledger.
2. Import the **actual, separate returned transaction** from a bank statement for that same named account using Bank Reconciliation. The return must be of opposite direction, the exact original amount, at least as recent as the original bank transaction, and not matched or reserved elsewhere. The return statement stays open until the correction can be posted and matched.
3. Open **Finance & Ledger > Payment Corrections**, select the original posted transaction, select the eligible separately imported return statement line, and document the real-world correction and bank evidence. The original bank match, the return line and its parent statement file hash are frozen into the correction's audit snapshot. The return line is reserved to prevent it being applied to another payment.
4. Submit the correction to the same independent requester/reviewer/poster finance queue. The journal must be dated on the **actual imported bank-return transaction date**. It cannot be backdated into a closed or pending period. The prepared source, matching status and return evidence are rechecked at every stage.
5. Post the reversal only after approval. The original source, matching history, and journal remain intact. A new journal swaps every original debit/credit and preserves original FX settlement effects. Staff can now match the reserved bank-return line to the *posted correction*, obtain a second independent bank-match review, mark the return statement reconciled, and run the independent book-balance proof. Both original and returned bank movements remain visible.

A pending original bank match, an unfinished original statement, another account or currency, mismatched direction or amount, changed return evidence, previously reserved/matched return, and cross-business IDs are rejected. Without an imported return line, the existing direct correction route continues to operate only for posted transactions with **no original active bank match**.

**This is internal bookkeeping supported by staff-imported CSV evidence, not a bank-authenticated refund or provider transfer.** It does not return funds, recover salary, disburse money, verify beneficiary ownership, contact payment providers or certify banking statements. Partial returns, fees netted out of refunds, a refund dated inside a closed period, legacy mixed-bank migration, and recovery of funds require separate reviewed accounting rules and implementation.

### Foreign bank periodic revaluation

**Finance & Ledger > FX Revaluation** allows an accountant to prepare an independent review of one *positive foreign-currency bank asset* at a supported date and an already approved exchange rate effective no later than that date. The system recomputes the original foreign bank units and the previous *base-currency carrying amount* from all posted transactions, including earlier FX revaluation journals; it calculates the new translated base value and any resulting unrealised gain or loss. It freezes the source journal fingerprint, bank profile, date, approved rate and documented valuation evidence.

After independent finance approval, the journal adjusts the bank's base-currency asset valuation against **Unrealised FX Gain** or **Unrealised FX Loss**, with **zero movement in the original bank currency** on both journal lines. Ordinary journal rules still require nonzero balanced original and base amounts; only this narrow reviewed revaluation category permits a two-line base-only journal. No bank feed or payment is invoked. Financial statements separate unrealised from realised gains/losses. The original currency balance remains untouched.

Only one valuation per named bank per date is allowed; it must occur no earlier than that bank's most recent posted journal, in an open accounting period. The valuation is rejected if another bank journal changes the approved snapshot before posting. Any previously independently verified bank-to-ledger proof whose ledger fingerprint changes must be reviewed again, but zero-unit revaluation entries **never appear as fake imported bank movements**.

**Unsupported:** zero/negative foreign balances and overdrafts, historical revaluations before newer transactions, FX trading/conversions, exchange-rate fair value hedges, multi-currency bank ledgers, receivables and payables revaluation, subsequent reversal/amendment of a posted revaluation, automated daily mark-to-market or statutory financial statements. These require separate policies and workflows.

### Review and QA

- Finance Dashboard: business owners ensure unrealised gain/loss chart accounts are activated.
- FX Revaluation: prepare company-bank source amount/previous valuation/approved rate.
- Posting Approvals: the new FX journal follows independent approval as with opening balances and payment corrections.
- Payment Corrections: an already reconciled bank receipt requires an eligible imported opposite-side return line before correction preparation.
- Bank Reconciliation: independently match and verify both preserved bank movements; book-only FX revaluation entries are excluded from transaction matching but retained in balance-evidence hashes.

For local verification:

    cd /opt/homebrew/var/www/boosted_expo/backend
    php artisan migrate
    php artisan view:cache
    php artisan test --compact

No Git commit or push, production deployment, bank transfer, customer refund, payroll payment, currency exchange or tax remittance was initiated in this phase.


## Finance phase — partial bank returns, document FX, bank signed evidence and period reopening (21 October 2026)

Local additive migrations:
- 2026_10_21_000001_create_finance_partial_returns_and_monetary_revaluations
- 2026_10_21_000002_create_bank_statement_attestations
- 2026_10_21_000003_create_finance_period_reopen_requests

No previous journal, original bank statement, original approved accounting close, account balances or earlier migrations were reset, deleted or rewritten.

### Multiple partial refunds / bank returns

Finance & Ledger > Payment Corrections now lets a finance accountant register separately evidenced, incremental refunds against a previously posted customer receipt, supplier payment or shipment cost. The original transaction must have a confirmed bank match on a reconciled named bank statement. Each partial refund is supported by its own separately imported open statement line with the exact actual returned amount, the opposite payment direction, the same original bank and currency, and a return transaction date no earlier than the original bank event. The ERP enforces unique return-line reservations, at most one return awaiting approval per source, and total returns no larger than the original payment. Each proposal preserves signed source evidence and gets its own independently reviewed and posted reversing journal, in the actual bank-return period. Original approved bank transactions and documents remain available. Subsequent repayments may satisfy a reopened customer receivable or supplier payable; partially returned shipment costs adjust expense reporting. Payroll returns remain deliberately outside this flow pending legal and statutory repayment policies.

A return dated in an earlier already-closed period is NOT silently backdated; a formal period reopening must be approved first. An original transaction from a closed period can be referenced by a legitimate later-dated return in an open period without modifying original historical figures.

### Foreign-currency customer invoices and supplier bills

Finance & Ledger > Receivables & Payables FX lets an authorised accountant value individually selected posted, outstanding foreign-currency commercial invoices or supplier bills with an already approved historical FX rate. The ERP reconstructs foreign units and current base-currency carrying values from original approved journals, settlements, full and partial corrections, concessions and earlier document revaluations. A hashed source snapshot is compared again at independent approval/posting. The resulting two-line journal adjusts only the document receivable/payable and the appropriate unrealised FX account in base currency, with zero original currency movement. Later actual partial or final payments consume the latest carrying value and proportionally reclassify previously unrealised FX into realised FX. Fully paid balances must return to zero without a phantom receivable/payable. The original invoice or supplier agreement is not rewritten.

Not covered without additional separately controlled accounting policy: hedging, forwards, overdrafts, FX market execution, statutory exchange-rate certification, nonmonetary inventory fair value, payroll recoveries and multi-currency banks.

### Conditional bank-issued cryptographic statement proof

Under Accounting Controls > Named Banks an owner can register the official full account identifier; the ERP stores only a keyed hash-based identity commitment, not its plaintext account reference. Bank Reconciliation > Statement has a bank-issued detached digital signature verification panel. A bank-issued JSON attestation must bind an official issuer/key, bank account identifier, original uploaded CSV SHA-256, currency, dates, opening/closing balances, debit/credit totals, bank reference and issuer signing time. Cryptographic validation requires a platform-provisioned signing public key whose real bank ownership was independently verified out-of-band. A user-supplied signing key can NEVER establish their own bank authenticity. The signed attestation and signature are encrypted at rest. A missing or revoked trust anchor, modified statement or invalid signature is rejected; unauthenticated uploads remain clearly labelled. Bank-to-ledger agreement still requires separate independent proof.

Live dependency: NO actual bank signer, real bank API credentials, regulated account consent or official statement signing documentation has been supplied for this project. The implementation supports a bank-issued signed evidence protocol and has offline cryptographic tests, but cannot authenticate a live bank statement unless an actual institution supports and onboards this protocol. If the institution uses read-only authenticated APIs instead, a bank-specific connector with its documented OAuth/mTLS/signing, key rotation, restricted credentials and bank sandbox acceptance is required. Do not call a manually uploaded bank CSV bank-authenticated.

### Formal audited reopening and re-close of closed periods

Accounting Controls > Accounting Periods provides a separately approved reopening request for a period already closed. The accountant states the specific error and legal accounting evidence, materiality assessment and bank/auditor references. The request freezes the original approved close snapshot, its SHA-256 and close timestamp. A different authorised owner/admin reviews and accepts/rejects the request; latest closed periods must be reopened first if later months are already locked. An approved request reopens the original period for dated adjustments, but does not rewrite, erase or disguise the original closing evidence. After posting, all affected bank statements must again have independent matching and book-balance proof; the normal independent period-closing approval then creates the revised close. All original and revised approvals remain auditable. An accountant or auditor still needs to decide whether previously filed financial statements or tax filings must be formally restated or amended; the ERP does not automatically submit amendments.

### Verification

    cd /opt/homebrew/var/www/boosted_expo/backend
    php artisan migrate
    php artisan view:cache
    php artisan test --compact
    php artisan route:list --path=finance

Everything in this phase is local only. No commit or push, production deployment, actual bank transfer, customer refund, payroll disbursement, forex order or tax remittance was initiated.


## Phase 1 — Security and production infrastructure engineering (21 October 2026)

Phase 1 implementation is complete for the features that can be developed locally. The runbook is at deploy/PHASE1_PRODUCTION_RUNBOOK.md and Nginx/Supervisor example configuration is under deploy/nginx and deploy/supervisor.

- Production HTTPS, strict trusted reverse-proxy allowlist, restrictive script CSP, HSTS and defensive browser/session headers.
- Privileged authenticator-based MFA in production (platform administration, business owners, admins, accountants, logistics), encrypted TOTP seed and single-use recovery codes. Local development sign-in remains unchanged.
- PostgreSQL migration-order fix; all application migrations and the entire suite verified on a disposable PostgreSQL database, with the existing local SQLite data preserved.
- Predis Redis client installed; private TLS PostgreSQL and Redis options, Redis cache/queue/session production templates.
- Authenticated encrypted PostgreSQL/SQLite backups with integrity-checked staging restores, separate private offsite encrypted replication/read-back, operator runbook and recovery-only command. Live R2 object versions require separate provider configuration.
- Opt-in CLI commands for production readiness, incident monitoring, encrypted backup and authorised email/R2 smoke tests; example queue worker and scheduled maintenance.
- Composer/npm dependency audits and reproducible frontend dependency lockfile.

**Release remains gated.** The current Mac environment is local/debug/SQLite with log-only mail and no real R2/offsite/provider credentials. Provision production DNS/HTTPS host, dedicated database, private Redis, real SMTP, private R2 and offsite disaster-recovery bucket, incident monitoring and backup-key escrow. Complete a rehearsal of existing SQLite-to-PostgreSQL DATA migration, real provider smoke tests, load/concurrency testing, independent penetration testing and financial acceptance. The production preflight deliberately refuses the local environment. No secret, live bank integration, real transfer, Git commit/push or production deployment was performed.

## Phase 2 — operational completeness (21 October 2026)

Implemented and locally tested in the Boosted Export ERP repository:
tenant/role-scoped real overview metrics; staff shift scheduling, self-service
check-in/out and attendance events; audited staff role/suspension changes;
business-owner-configurable independent dispatch sign-off, with readiness
snapshot verification and rejection/reopen lifecycle; warehouse quarantine and
independent quality release, inventory-aware reorder warnings; and private,
expiring/revocable buyer order tracking links. The existing secure supplier
quotation links remain in use. No payroll, money or inventory changes occur
automatically through attendance, buyer links or dispatch approvals.

Full Phase 2 operating procedures, authorisation rules, real-world QA checklist
and release limitations are in deploy/PHASE2_OPERATIONS_RUNBOOK.md. Frontend
static-script asset paths were also corrected from *.js.js to *.js following
the previous CSP migration.

Development data and SQLite have been preserved. Migrations
2026_10_21_000005–000007 are additive; no commit, push, live mail,
production deployment or external money movement was performed.

Phase 2 final regression: **130 passing tests, 2,627 assertions on both SQLite and disposable PostgreSQL**. The warehouse view distinguishes physical unpacked stock from usable/quality-cleared quantity; held lots are excluded from the dashboard and reorder threshold calculations. See deploy/PHASE2_OPERATIONS_RUNBOOK.md for the full handover and remaining external gates.


## Phase 3 — Provider notifications, print agents and export clearance (22 October 2026)

The ERP now has tenant-isolated, authenticated external provider event intake
for payment and carrier messages, with strict signed-callback verification,
idempotency and evidence review that never posts a financial journal or
changes an export shipment's physical status. Platform operators must provision
real provider accounts and signing secrets after independently validated
onboarding; no accounts or fake credentials have been enabled.

A local Zebra print agent and authenticated print-job queue support previously
generated, hash-verified original ZPL files. Workstation credentials are shown
once and stored hash-only, leases and statuses are audited, and a revoked
workstation cannot receive new work. Socket delivery is not physical print
verification. See deploy/zebra_agent/README.md.

Shipments now include references for phytosanitary, origin, customs, airwaybill
and other export documents, with expiry and an independent internal review.
No actual statutory issuance or government authority verification is implied.
The complete operational and activation requirements are documented in
deploy/PHASE3_INTEGRATIONS_RUNBOOK.md.

Phase 3 migration: 2026_10_22_000001_create_phase_three_integrations.
The full SQLite and disposable PostgreSQL regression both passed **138
tests / 2,713 assertions**. Everything remains local; no commit, push,
live provider payment, document filing or production deployment was done.


## Phase 4 — staging acceptance and controlled release safeguards (22 October 2026)

The local project now includes role-separated per-company release acceptance
campaigns (12 mandatory checks), independent management review, an immutable
acceptance event history, read-only per-company finance/stock/document integrity
audits and a fail-closed expo:release-gate production diagnostic. The release
is bound to BOTH a reviewed immutable source archive and a separately approved
frontend build SHA-256. Every active company on a shared ERP deployment must
accept those fingerprints, and all active-company integrity checks must pass.

Read-only or local opt-in commands:
php artisan expo:data-audit BUSINESS_ID --json,
php artisan expo:build-fingerprint --json,
php artisan expo:release-gate BUSINESS_ID --sha=SHA256 --live --json.
Run sh deploy/scripts/phase4_staging_rehearsal.sh for disposable SQLite and
PostgreSQL regressions. phase4_build_candidate.sh intentionally refuses
dirty/uncommitted source. Full release/runbook details and real provider,
security, finance and data cutover prerequisites are in
deploy/PHASE4_RELEASE_RUNBOOK.md.

**Not deployed:** Live hosting, authorised external audits, real-data cutover,
bank/carrier providers, physical printers and official government integrations
remain independent prerequisites before any production release.

Phase 4 final locally verified regression: **149 passing tests, 2,811 assertions** on both SQLite and a fresh, disposed PostgreSQL test database. The production preflight and dirty-tree packaging safety checks remain deliberately blocking on this Mac. No deployment or commit has occurred.
