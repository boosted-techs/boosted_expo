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
