# Export business completion roadmap

**Date:** 27 October 2026, Africa/Kampala  
**Basis:** [Current-system audit](EXPORT_BUSINESS_AUDIT_2026-10-27.md). Phase 0 is implemented locally. Phase 0A software is also implemented locally, with physical printer and real-device installation acceptance still open. Phase 1 control-centre software is now implemented locally, with production mail/scheduler, operational evidence and PostgreSQL acceptance still open. Stages 2–5 remain planned and must meet their exit criteria before being called complete.

**Owner-control acceptance contract:** [Owner and superadmin control review](EXPORT_OWNER_CONTROL_REVIEW_2026-10-27.md). It defines 48 owner questions, 18 superadmin questions, 15 additional pending requirements (R01–R15), and the detailed priority printing/login-installation requirements. Use those questions to judge completeness alongside this implementation plan.

## 1. Product direction

Build around the customer's order and the people fulfilling it. Give the owner one place to see what was requested, what is committed, what must be bought, what arrived, what is being prepared, who owns each action and where money is waiting.

Keep the current application and working service layer. Extend relationships and workflows through additive migrations, with existing records retained. Avoid a framework rewrite or introducing microservices merely to make the backend appear stronger.

The initial business defaults should be Uganda, Africa/Kampala and UGX reporting, with the actual currencies used by each exporter enabled during setup. Client-facing prices use the agreed customer currency. Quantity-only customer orders remain useful before pricing is agreed.

### Owner business position and daily control

The owner must be able to ask what is happening today, what suppliers have promised/delivered, what staff are doing, who supplied an item on a chosen date, who transported an export, what assets/debts the business has and where money is going. The owner-control catalogue O01–O48 gives each question a required answer and identifies current coverage.

Today is the operations/exception view. A linked Money position shows cash/bank/mobile money; customers owing us; suppliers/payroll/loans/other amounts we owe; stock/work in progress; current and fixed assets; daily spending; period sales/COGS/result; and 7/30-day cash needs. Every figure has a date/period, actual currency and UGX basis, evidence/drill-down, responsible action and completeness/posting/reconciliation state. Keep commitments and forecasts separate from posted balances and avoid counting a commitment again after it becomes a bill.

Provide reusable searches/reports by business date, supplier/customer, product, staff, order and shipment, including printable/exportable results that retain filters and currency/unit context. Current and fixed assets, liability maturity, owner contributions/drawings/loans, trade due dates, daily cash reconciliation and an agreed opening-data cutover are required relationships, not just dashboard labels.

Printing and installation at login are early usability requirements. They must not wait for the last ERP module to be completed.

### Required scope and current position

The target is a complete ERP for this export business line. The following are required workstreams, with their current limitations made explicit. A mention in this plan is not a claim that the feature already works.

| Workstream | Current position | Completion requirement |
| --- | --- | --- |
| Superadmin dashboard and business control | Dedicated dashboard, business tabs, owner recovery, versioned agreements, renewals/corrections, usage limits, support grants and audit implemented locally | Production delivery/scheduler and concurrency acceptance; verified infrastructure evidence and fuller cost instrumentation. |
| Exporter orders, buying and preparation | Working modules, with missing demand/sourcing/task relationships | One order links customers, supplier splits, staff market buying, receiving, preparation and delivery. |
| Expenses and petty cash | Paid shipment costs exist; general operating expenses are incomplete | Expense claims/bills, approval, payment, petty cash, recurring obligations and cost allocation. |
| Cost of goods sold (COGS) | Produce cost valuation and reviewed shipment/waste relief exist | Complete, reconcilable costing through purchase, loss, preparation and sale, with policy-controlled cost classification. |
| Rotas and shifts | Individual shift scheduling exists | Weekly rota, templates, availability/leave, coverage, changes, conflict handling and publication. |
| Attendance and timesheets | Clock-in/out and event records exist | Breaks, exceptions, corrections/approval, planned-versus-actual reporting and approved payroll inputs. |
| Wages and payroll | Manually entered gross/deductions with approval/posting and settlement exist | Employee/casual records, agreed rates, attendance/output calculations, approved adjustments and labour cost allocation. |
| Finance and reporting | Ledger, manual FX, payments, bank reconciliation and statements exist | Deposits/advances, allocations/transfers, complete expenses/costing and reliable operational/financial reports. |
| Assets and borrowing | Domain modules absent | Equipment lifecycle, maintenance, loan facilities, schedules and financial posting. |
| PWA and usability | Login/post-login invitation, native/guided installation, icons and an offline connection screen implemented locally | Actual device installation acceptance, selected offline drafts, safe synchronization and role-specific daily workflows. |
| Stickers and printing | Profiles, shared artwork/preview/PDF/ZPL, bulk selection, required/stale fields, recovery and scan verification implemented locally | Real GK888t/Windows/Mac/Android acceptance; consumables stock control remains later scope. |

## 2. Superadmin control centre

The superadmin manages the ERP service across exporting businesses. This requires its own workspace, landing dashboard and actionable business detail pages. It is a first-class delivery workstream, not a small settings addition. The exporter's daily ERP remains a separate workspace, with explicit, audited support access between them.

### Platform dashboard

Make this the superadmin's landing page after sign-in. Show a selected reporting period, comparison period and the freshness of each metric. Every figure links to its underlying filtered records.

| Dashboard group | Required information | Main action |
| --- | --- | --- |
| Businesses | Total onboarded; onboarding pending; trial; active; billing-restricted; security-suspended; closed; recent activity | Search/filter businesses, review onboarding and open a business record. |
| Subscriptions and collections | Plans in use, trials ending, upcoming renewals, overdue platform invoices, collected payments and cancellations | Renew/change agreements, review payment evidence and resolve overdue accounts. |
| Usage | Staff seats against plan allowance, active users by period, order/shipment activity, storage consumption and approaching limits | Inspect trends and notify the business or approve a documented limit change. |
| Service health | Failed jobs/deliveries, application errors, storage/queue pressure, backup age and last verified restore evidence | Open the affected business/service issue with an assigned responsible operator. |
| Support and control | Owner invitations awaiting acceptance, failed invitations, support requests, active support sessions and sensitive admin changes | Retry delivery, complete authorized recovery, resolve support and inspect audit history. |

Define recurring subscription value separately from cash collected, outstanding invoices and one-time fees. Do not count trial customers as paid subscribers or add tenant export turnover into platform subscription revenue. Any infrastructure cost-per-business estimate must show its measurement/allocation basis and completeness.

### Manage an exporting business

Use a searchable business directory with status, plan, renewal date, usage summary, owner/contact and last activity. A business detail page has Overview, Subscription, Usage, Access, Health and History tabs.

Required actions:

- Create/approve an exporter, correct profile details and track onboarding progress.
- Invite the first owner; resend/revoke invitations with delivery history; carry out authorized owner recovery/transfer without exposing credentials.
- Start/extend a trial, activate a subscription, schedule a plan change, grant a dated exception and close an agreement.
- Apply or lift a security suspension with reason and review; use separate billing restrictions when an invoice is overdue.
- Review tenant health, export requests, agreed data-retention/offboarding steps and recoverable archive status. Subscription cancellation must not silently delete business records.
- Open an explicitly authorized support session with visible business identity, limited permissions, expiry and a complete activity trail.

Business lifecycle, subscription state and service health are separate dimensions. For example, a paid exporter can be security-suspended; a business can be within a grace period while a backup failure needs attention. Avoid a single overloaded “active” flag.

### Manage subscriptions and platform billing

Provide plan creation/editing/versioning, included modules, staff/storage/usage allowances, billing interval, price, discounts, agreement-specific overrides and effective dates. Preserve historical invoice terms when prices/plans change.

Handle trial conversion, renewal, upgrade/downgrade, pause/cancellation, billing contacts, invoices, partial/full payment evidence, credit/refund corrections and reminder delivery/retry. Agree how mid-cycle changes are charged before implementing proration. Payment evidence is recorded and verified through the configured process; do not label it as an automatic bank collection.

Make expiry behavior explicit: warnings before expiry, a defined grace period, and a restricted mode with appropriate access to existing records/export and renewal instructions. Staff who cannot manage billing should receive a clear explanation and owner contact route. Plan changes and limits must be enforced on the server, not only hidden in the menu. Any legacy business without a subscription needs an explicit migration/exemption state rather than a permanent invisible bypass.

Platform subscription invoices and payments are separate from each exporter's customer invoices, staff expenses and books. Platform team members should have distinct business-management, billing and support permissions, with the superadmin retaining oversight.

### Monitor usage with defined counters

| Counter | Required definition/control |
| --- | --- |
| Staff seats | Define which active internal memberships count; customer/supplier portal identities are classified separately. Count per business, including users who belong to several businesses. |
| Active users | Distinct authenticated users who perform qualifying actions during a stated period; show separately from allocated seats and current sessions. |
| Operational volume | Accepted orders, receipts, cartons/shipments or other specified actions by period. Draft saves and duplicate retries must not inflate billable counts. |
| Storage | Measured private files and generated artifacts per business, with a declared treatment of retained versions/backups. Show measured timestamp and quota. |
| Email/integrations | Deliveries, failures/retries and provider events by business; distinguish attempt count from successful delivery. |
| Health | Source and freshness of backup, worker, error and integration indicators; an absent measurement means unknown, not healthy or zero. |

Add a defined allowance/threshold policy, warning notifications, documented temporary overrides and a usage-event/snapshot history. Make counter updates idempotent and reconcile aggregates to source records. Capacity limits must not prevent correcting historical records, viewing balances or recovering/exporting data. Collect only the operational metadata needed for administration; detailed tenant financial access follows the support-access authorization rules.

### Keep the platform interface simple

Use six primary areas: **Overview, Businesses, Billing, Usage & Health, Support & Audit, Settings**. Plans, subscription agreements, invoices and collections sit under Billing; they do not each need a permanent top-level item. Let the superadmin resolve common cases from a business record: find exporter → inspect status/usage → take authorized action → see its recorded result.

Include the service owner's questions S01–S18 from the owner-control review: adoption/setup progress, per-business service-cost estimates, printer incidents, usage accuracy, renewal collections, support/incident ownership and release/restore readiness. Give every actionable incident/renewal a responsible person, evidence timestamp, due action and escalation; unknown or unmeasured metrics must not look healthy.

## 3. Six primary exporter work areas

| Area | What ordinary users do here | Detail available when needed |
| --- | --- | --- |
| **Today** | See assigned work, due orders, shortages, delayed receipts, quality holds, unpaid balances and approvals | Owner cash/exception summary; role-specific task lists; audit/history drill-down. |
| **Orders** | Enter or review demand; quote; plan supply; follow preparation and delivery | A single order workspace with Overview, Supply, Preparation, Delivery, Money and History. |
| **Stock & Buying** | Supplier orders, market trips, receive produce, count stock and prepare cartons | Batch traceability, holds, adjustments, packaging materials and suppliers. |
| **Money** | Money in/out, expenses, deposits, buying advances, bills, costs, balances and approvals | COGS, bank reconciliation, ledgers, period controls, FX, assets and loans under secondary navigation. |
| **People** | Plan the weekly rota, assign work, record attendance and manage staff/access | Shift templates, leave/availability, wage arrangements, timesheet approval, payroll and staff accountability. |
| **Business** | Company details, products, customers, enabled currencies, bank/mobile-money accounts and templates | Approval policies, integrations, subscription, audit access and owner settings. |

Dispatch work can appear as a queue inside Orders/Delivery for logistics staff. Assets and loans belong inside Money for financial users and can also be linked from relevant People/Business records. They do not need to become additional top-level screens.

On phones, show four frequent destinations plus “More”; preserve the same information structure. Role permissions determine which tools appear. Advanced finance and release controls should not be daily navigation for packers or market buyers.

Every work item should answer: **what is needed, how much, by when, who is responsible, what is blocking it, and what action comes next?**

Example owner view:

> Order: 1,000 kg avocado for the buyer. Supplier A: 400 kg committed; supplier B: 350 kg committed; Sarah: 250 kg assigned for market purchase. Received: 600 kg accepted; 20 kg rejected. Packing: Joseph, due today. Buying advance: UGX 1,200,000, with UGX 170,000 still to account for. Pricing pending. Shipment booked for Friday.

These values must be calculated from connected records, with links to evidence. Do not build another dashboard of independent manual totals.

## 4. Customer order and quotation workflow

1. Customer opens a business-specific private invitation/login, chooses permitted products, enters quantities/units, destination, required date and instructions, and submits. No prices or amounts are required.
2. Authorized sales staff can enter the same demand on behalf of a customer. Record the source, buyer's reference and entering user. A customer identity must be distinct from an internal staff membership.
3. Staff review the demand and approve an operational order. The business can plan supply while its pricing is pending, subject to its commitment policy.
4. Owner/authorized commercial staff prepare an optional quotation. Use the customer's agreed currency and a selected approved manual pricing FX rate; display the UGX equivalent to authorized internal users.
5. Issue a frozen quotation revision and share a revocable, expiring link. The customer can view/download and accept or reject that revision. Record recipient, decision, time, terms and revision. A recorded staff acceptance must identify its supporting evidence and channel.
6. Changing an issued quotation creates a revision. Withdrawn, expired or superseded links cannot accept an obsolete offer. Client pages expose their own commercial information, not supplier prices, staff pay, margins or internal UGX costing.
7. Pricing acceptance updates the order's commercial agreement without erasing its earlier sourcing/preparation history. Operational and commercial changes must be versioned and reconciled.
8. Show customer-visible progress through submitted, reviewed, sourcing, preparing, ready, dispatched and delivered milestones. Publish only approved customer-facing notes.
9. Derive fulfilment from shipment lines. Close completed orders; support partial fulfilment and controlled cancellation/remainder closure. Final selling prices must be present before issuing a commercial invoice.

Separate state dimensions:

| Dimension | Proposed examples | Purpose |
| --- | --- | --- |
| Order approval | Submitted, reviewed, approved, cancelled | Who has authorized the business commitment. |
| Fulfilment | Not started, sourcing, preparing, partly shipped, fulfilled, closed | Operational progress, supported by quantities. |
| Pricing | Unpriced, draft quote, sent, accepted, rejected, expired | A quantity-only order does not pretend to be a zero-value sale. |
| Finance | Deposit held, uninvoiced, partly invoiced, outstanding, partly paid, settled | Delivery and payment need not happen at the same time. |

Use a small, validated transition model per dimension; do not implement one giant status list or allow arbitrary edits. Derive statuses where possible instead of duplicating them.

## 5. Sourcing from suppliers and the market

For each order line, calculate required quantity, free/reserved stock, sourcing commitments, accepted receipts, preparation loss, packed output and shortage. Use a consistent product base unit and explicit conversions where needed.

Support the following branches within the same supply plan:

- **Existing stock:** reserve available cleared batches; prevent another order from reserving the same units.
- **Supplier:** request quotations or create an approved direct purchase; split awards by quantity among multiple suppliers; record dates and remaining demand. Do not close the whole demand because one supplier was awarded part of it.
- **Own market buyer:** assign a staff member, market/location, products/quantities, budget, due time and approved advance. Permit several vendor purchases on one trip and receipts at different times.

Receive against the source commitment, record accepted/rejected quantities and assign accepted stock to the intended orders. Support bulk buying that later serves multiple orders through a purchase/receipt allocation table.

Rejects and shortages must create a clear next step: replacement, re-source, return, reduce outstanding commitment or approve a variance. Keep gross purchased quantity separate from saleable output after grading.

The market advance flow is: request → approve → issue cash/bank/mobile-money disbursement → record purchases/expenses → receive produce → return unused funds or claim approved excess → reconcile and close. Advance balances must be visible per employee and trip, and connected to posted money events.

### Partner history and source-date reporting

Add a customer/supplier account workspace with commitments, promised dates, accepted/rejected receipts, source batches, invoices/bills, advances, payments/credits and follow-up history. Registered suppliers and market vendors need a deliberate identity/deduplication policy while retaining original vendor snapshots. Provide a supplier/product/date-range receiving report so the owner can answer who supplied what on a selected business date, including receiver and actual accepted/rejected units.

Calculate supplier delivery/quality performance from declared expected dates/quantities and actual outcomes, with period/sample count. Add collection/delivery follow-up tasks with owner/deadline. A directory and transaction list alone do not satisfy these controls.

## 6. Preparation, stock and export delivery

Retain batch movements and carton traceability. Add order targets and work responsibility to preparation jobs, with grades, expected yield, actual waste, material use and completion/quality sign-off.

Provide one receiving/packing workflow with progressive fields. Typical work should not require visiting separate receiving, batch, carton, template and label-history screens just to complete one delivery. Advanced traceability stays available in a detail panel.

Distinguish loose available/reserved stock from packed cartons and dispatched goods. Packing consumes batch stock; dispatch must not consume it a second time. Financial inventory recognition and COGS posting remain linked but separate from physical movements.

Add stocktake/adjustment, location transfers and correction/repacking operations that preserve original history. Use an initial default packhouse so a small exporter need not configure bins before receiving produce. Track expiry/harvest and temperature evidence where applicable to its products.

Reuse shipment/document services. Add configurable shipment readiness requirements, original document attachments, task responsibility and exceptions. Air freight is the existing initial scope; add road/sea fields only when the business uses those modes. Decide consolidation requirements before redesigning shipment/order relationships; one order can already use several shipments, while a shipment currently accepts one order.

Even when the international shipment stays air freight, model the domestic transport legs that take produce from market/farm to packhouse and airport. Add transporter/forwarder master data, booking/contact, driver/vehicle where applicable, pickup/cargo cutoff, cartons/weights handed over, releasing/receiving parties, timestamps, evidence and agreed/actual costs. Snapshot partner details so later contact changes do not rewrite the handover history. One airline/carrier text field cannot explain the complete transport chain.

Connect quality/delivery complaints to an assigned case, deadline, affected batches/cartons/customers, evidence and replacement/return/credit outcome. A recall/hold investigation should identify forward exposure as well as the original source; retain physical and financial resolution history.

## 7. Manual multi-currency design

### Relationships and rules

| Record | Relationship/rule |
| --- | --- |
| Currency catalogue | Valid code, display name, amount precision and cash-rounding policy; values sourced from a maintained authoritative catalogue. |
| Business currencies | Business, currency, enabled state and permitted use; UGX is the initial reporting base for the target exporters. Disable future use without altering history. |
| Customer | Default agreed quoting currency selected from the business currencies; a deliberate authorized override is recorded. |
| Money account | Cash, bank or mobile-money account with one currency and an actual account identity. Model different currency balances separately. |
| Approved manual rate | Business, foreign currency, base currency, direction, effective time/date, purpose/source, preparer, approver and version. Rate means “base units for one foreign unit.” |
| Quote snapshot | Original currency, original amounts, selected pricing rate ID/value, base currency, base amounts and issued revision. |
| Invoice/journal | Original amounts and its own historical posting-rate reference/snapshot. Keep the original quote pricing assumption for comparison. |
| Settlement | Actual paid/received amount, payment account/currency, event date, allocation and approved conversion evidence. |
| Currency exchange | Amount/currency sold, amount/currency bought, both accounts, actual effective rate, fees, evidence and independent posting approval. |

Retain decimal arithmetic. Do not replace it with floating-point calculations. Keep quantity precision, unit-price precision, ledger precision and cash rounding explicit. An accountant should approve the business's rounding and recognition policy before live financial acceptance.

The current rate table permits one currency/effective-date row per business. Extend it if intraday rates or corrected proposals are needed: preserve approved history through versioning/supersession rather than editing rates referenced by issued documents. Show whether an older approved rate is being used; never silently choose a foreign rate of one or assume a missing rate.

Treat the business base currency as locked after financial activity, with any future change requiring a controlled migration. Allow staff to correct descriptive company details without casually changing its reporting basis.

### Example acceptance calculation

These are illustrative numbers, not market rates or accounting advice.

- Customer quotation: USD 1,000. Pricing rate: UGX 3,700 per USD. Client sees USD 1,000; internal quote preserves UGX 3,700,000.
- A later approved quote-pricing rate of 3,750 does not change the issued quotation.
- If the invoice is recognized at an independently selected rate of 3,720, preserve the journal's UGX 3,720,000 and the earlier UGX 3,700,000 pricing assumption as separate facts.
- If an allowed settlement event is translated at 3,760, its UGX equivalent is 3,760,000. The system must account for the UGX 40,000 difference relative to that invoice carrying amount through its approved settlement policy, not overwrite the invoice's old rate.
- Receiving USD into a USD bank account is separate from subsequently exchanging that USD into a UGX bank/mobile-money account. Only a recorded conversion establishes the actual purchased UGX amount and conversion fee.

## 8. Complete financial scope

Reuse current financial requests, independent approvals, immutable journals, period locks, historical FX, bank matching and controlled corrections.

Add these linked subledgers/workflows:

| Scope | Minimum operational record | Required finance connection |
| --- | --- | --- |
| Customer deposits | Customer/order, original currency, receipt evidence, unallocated/allocated balance, refund | Record money before invoice and allocate later without double-counting revenue. |
| Supplier advances | Supplier/purchase commitment, payment evidence, remaining balance and recovery | Settle against later bills/receipts, with separate treatment of refunds. |
| Market/staff advances | Employee/trip, authorized budget, disbursement, purchases, expenses and cash returned | Accountable advance balance reconciled to cash/bank and accepted costs. |
| General expenses | Category, counterparty, date, currency, proof, approver and optional order/shipment | Cash/petty-cash or accrued expense and later settlement. |
| Payment allocations | One payment and one or more document allocations | Reconcile payment total, allocations, fees and unallocated amounts. |
| Transfers and FX | Actual source/destination money accounts and amounts | Balanced approved transfer/conversion journals and bank matching. |
| Assets | Asset code/category, purchase reference, serial number, custodian/location, status and useful-life policy | Acquisition, depreciation and disposal with approval and historical currency basis. |
| Loans | Lender/facility, currency, drawdowns, terms, schedule and principal/interest/fee components | Drawdown, accrual/expense where policy requires, repayment allocation, balance and FX effects. |
| Full order costs | Produce, grading loss, labour, materials, logistics, inspection and allocated shared costs | Trace costs to their posted sources; show missing/unposted costs rather than false final profit. |

Start assets and borrowing with the exporter's own equipment and lender obligations. Staff lending or lending to customers is a separate scope that needs explicit business rules. Store agreed terms rather than inventing a standard interest or depreciation calculation for every business.

Show four money states separately: recorded evidence, approved, posted, and reconciled. Label management estimates as estimates. A payment record must not imply that a bank transfer was executed or independently confirmed.

### Debt, credit, assets and daily position

Add agreed customer/supplier payment terms, document due dates and credit controls, snapshotted when issued. Export commercial invoice/supplier debt terms are separate from platform subscription billing. Customer/supplier statements need due/overdue aging, disputed balances, deposits/advances and a responsible collection/payment follow-up; issue date alone is not a payment promise.

Add current/non-current account/maturity classification and equipment register reconciliation under the approved reporting policy. Show owned versus hired/third-party assets. Add ongoing owner contributions, withdrawals, reimbursements and loans to/from owners as explicit transactions, rather than forcing them into sales, operating expenses or the one-time opening balance.

A daily money-out/cashbook view includes stock purchases, expenses, assets, wages, advances, transfers and loan repayments with their proper meaning. Daily cash/petty-cash counts, bank/mobile-money reconciliation freshness, unposted records and approved commitments feed a 7/30-day funding view; commitment and collection assumptions remain visible. All actual balances and their UGX equivalents need dated valuation/reconciliation context.

### Simple actions and backend preparation

Offer “Receive produce,” “Record expense/money out,” “Receive money,” “Approve wages” and “Review/close” from the relevant business record. Capture evidence once; derive eligible bills, allocations, rates, balancing and linked posting tasks through configured rules. Required independent preparer/reviewer/poster separation remains enforced. Do not make ordinary users visit chart, journal, rate and review screens separately for every transaction. The owner-control review specifies these action-to-backend responsibilities and their acceptance criteria.

### Expenses, bills and petty cash

Cover rent, utilities, transport, packing materials, freight, inspection, maintenance, communications and other configured categories. One “Record expense” action should accept counterparty, date, currency/amount, receipt or other evidence, payment method or unpaid status, and optional order/shipment/asset/trip allocation. Reveal advanced accounting fields only to authorized financial users.

Support draft → submit → approve/reject → post → pay/reconcile, with payment evidence allowed at initial entry for expenses already paid. Record the actual payment event separately so an expense is not paid twice when approved later. Unpaid bills and staff reimbursements need their own outstanding balances. Recurring obligations can suggest the next bill/reminder; they must not fabricate a payment or duplicate a supplier invoice.

Petty cash needs a custodian, currency, authorized float/top-ups, vouchers, disbursements, actual counted cash, variances and an approved reconciliation. Staff expense claims may settle against a buying advance or become a reimbursement, with no second reimbursement for the same receipt. Shared expenses can be split across orders/shipments or retained as business overhead using an explicit approved allocation basis.

Owner views should show this month's expenses by category, unpaid obligations, outstanding staff claims/advances, budget-versus-actual costs and missing evidence. All amounts must be traceable in original currency and UGX; issued/posted history retains its approved rate.

### COGS, preparation cost and profitability

Extend the existing inventory valuation and COGS services. COGS means the recognized cost of produce sold, while an order cost sheet also includes applicable preparation/export costs for management decisions. Preserve these distinctions and the business's approved accounting policy.

| Source | What must be captured | Connection/control |
| --- | --- | --- |
| Produce purchases | Actual accepted quantities, purchase price, approved historical FX and authorized purchase adjustments | Link received batch valuation to posted supplier bills; distinguish ordered, rejected and accepted produce. |
| Acquisition/preparation inputs | Relevant transport, handling, direct labour and packaging material consumption | Trace source expenses/payroll/material issues; classify eligible inventory costs versus period/shipment expenses using the approved policy. |
| Grading/spoilage | Actual lost units, reason, responsible record and value | Reconcile accepted input to usable loose stock, output and loss; classify losses explicitly and preserve source-batch traceability. |
| Packed output | Batch quantities, labour/material allocations and packed units | Maintain loose/work-in-progress/finished views without counting the same stock value twice. |
| Shipment and sale | Actual dispatched quantities, applicable inventory cost layers and invoice links | Release eligible inventory value to COGS at the approved recognition point; the current dispatched-shipment flow is the starting implementation. |
| Corrections/returns | Reversed quantities/values, replacements and revised source allocations | Preserve original cost history and recalculate affected balances through controlled entries, respecting closed periods. |

Prevent double counting: allocating approved payroll to an order does not create a second wage payment or an extra payroll expense. Material use must reference its stock issue; a recorded shipment cost must not also enter inventory value unless the approved classification explicitly requires the corresponding transfer. Explain rounding remainders and the valuation method; never silently substitute selling price for missing acquisition cost.

Provide an order cost sheet with estimated/committed/actual amounts, a stock valuation report, COGS by shipment/product/period, waste cost, gross margin and contribution after identified export expenses. Show allocated overheads separately and flag unposted/unallocated source costs. Reconcile the financial portions to the ledger; do not call a provisional shipment contribution complete business profit.

## 9. People: rota, shifts, attendance and wages

This is a connected workstream. Existing shift/clocking/payroll code provides a starting point; it does not yet provide a complete workforce planning and wage calculation system.

| Capability | Required business behavior | Daily interface |
| --- | --- | --- |
| Staff register | Permanent, temporary/casual and contractor records; role/skills, location, active dates and agreed pay basis | One staff profile. A worker need not have a login merely to be scheduled or paid; only authorized supervisors can record on their behalf. |
| Rota | Weekly view, reusable shift patterns, availability/leave, required headcount/skills and publication | One weekly board; copy last week, adjust coverage and publish. |
| Shifts | Start/end, breaks, location, supervisor, expected work, required staff and change history | Shift detail in the rota, with conflict checks and authorized replacements/swaps. Handle overnight times using Africa/Kampala. |
| Work allocation | Assign people/teams to buying, receiving, grading, packing and dispatch tasks/orders | A person's Today list and the order workspace; tasks and shifts are linked but not treated as the same record. |
| Attendance | Clock-in/out, break periods, missed punches, lateness, absence, approved time and reasoned corrections | Quick clock button or authorized supervisor roll call; planned-versus-actual exceptions in one queue. |
| Timesheets/output | Approved worked hours/days and verified cartons/kg/pieces for an agreed piece-rate arrangement | Supervisor checks exceptions and output; avoid counting one output event for multiple wage claims. |
| Payroll | Effective-dated daily/hourly/monthly/piece rates, approved adjustments/overtime rules, deductions, pay run review and payslips | Prepare payroll from approved inputs, review differences, approve and settle. Existing manual input remains a reasoned adjustment, not a second payroll source. |
| Labour costing | Allocate approved labour to order/preparation work or general overhead using a declared basis | Order cost sheet and People cost summaries draw from the same approved payroll/time sources. |

Leave approval and shift changes must update availability/coverage. Overlapping assignments and shifts need actionable warnings or rejection as appropriate; overtime and rest rules must come from approved current employment arrangements and applicable requirements. Do not invent a standard statutory payroll formula in this roadmap. Any PAYE/NSSF or other statutory calculation/remittance functionality requires a separately verified specification before being labeled complete.

Keep scheduled time, actual attendance, productive output, approved payable time and money paid distinct. Missing clock-out should create an exception, not silently pay an entire planned shift. Corrections retain original observations, editor, approval and reason. Approved payroll inputs are snapshotted so later timesheet edits require a controlled adjustment rather than silently changing a posted pay run.

Provide reports for rota coverage, planned-versus-worked hours, attendance exceptions, leave balances where configured, payroll outstanding, wage cost per order/job and staff advances. Employees see their own work/time/pay information; supervisors see their assigned teams; HR/payroll permissions control wider access.

The normal employee flow remains: **see today's shift/tasks → clock or confirm attendance → record work → finish**. Supervisors plan one weekly rota and review one exception queue. Finance consumes approved inputs instead of retyping attendance into a separate spreadsheet.

## 10. Tenant, platform and audit controls

### Business users

Preserve the existing roles as an initial permission baseline. Clarify in the UI that the current internal `buyer` role means a procurement employee; it is not a customer account. Add limited action/amount policies only where the business needs them, with safe defaults and reasons for changes.

Each critical action must resolve its business and authorize the actor before accessing a record. Tenant IDs from forms, URLs or offline payloads are not sufficient proof of access. Retain composite tenant keys; require tenant context in background jobs, notification delivery, file operations, reports and cache keys.

Audit task reassignments, monetary changes, source corrections, policy changes, public-link grants/revocation, master-data changes and access changes. Provide a filterable order timeline and an owner audit view. Avoid logging passwords, tokens, private bank identifiers or entire sensitive documents as plain event data.

### Platform administrator

Implement the dedicated control centre in section 2, including its dashboard, business lifecycle, subscription/billing actions, usage measurement and service-health views. Log suspend/reinstate, limit overrides, billing changes and owner recovery reasons. This is a required first-class workstream with its own delivery gate.

Define security suspension separately from billing restrictions. Suspension must block all applicable staff, public-link, device and integration paths. Decide what an expired subscription can still read/export and let staff see an appropriate renewal message; do not send users to a billing page their role cannot access.

Support access should be specifically granted, time-limited and visible in audit history, with the business/role being accessed clearly displayed. Keep platform billing separate from tenant export accounting.

## 11. PWA acceptance scope

### First delivery: login installation invitation and reliable online use

- Complete manifest with stable identity, appropriate icons including maskable assets, install guidance and HTTPS delivery.
- Show an “Install Boosted One” invitation on desktop/mobile web login, with an appropriate post-login reminder. Include manifest/icon metadata in the public/login layout, retain normal sign-in/MFA, respect Not now and hide the invitation while running installed.
- On compatible Chromium browsers, expose a working Install action when the browser supplies an install event and trigger its native prompt from the user's click. On iPhone/iPad and macOS Safari, provide the relevant browser installation instructions; use a clear fallback elsewhere. Do not promise a forced native download popup at every login. See the [browser-specific login contract and sources](EXPORT_OWNER_CONTROL_REVIEW_2026-10-27.md#7-pwa-invitation-at-login-on-desktop-and-mobile-web).
- Versioned application shell/static resources, safe offline fallback and clear connection indicator.
- Update notice with a safe reload path that protects unsaved work; appropriate server/cache headers for updated assets and service worker.
- Test installed use on representative Android Chrome and iPhone Safari devices as well as desktop, including session expiry and sign-out.

### Second delivery: limited offline capture

- Permit selected drafts: customer request, market purchase notes, receiving observations and packing observations. Show “saved on this device,” “waiting to send,” “needs review” and “confirmed by server” distinctly.
- Partition local storage and queued records by business, user and device/session. Purge inaccessible tenant data when access is revoked or users sign out/change business. Define shared-device and inactivity expiry behavior.
- Use stable command IDs plus payload digests. Repeat delivery of the same command must not create duplicate receipts, cartons, purchases or payments. A reused command ID with different data must be rejected.
- Revalidate identity, membership, business state, stock, prices, periods and permissions on reconnection. Show conflicts for user resolution; do not silently apply last-write-wins to inventory or finance.
- Keep final allocation, stock deduction, dispatch, financial posting, exchange-rate approval and other critical approval online and server-authoritative.
- Provide manual retry/recovery even where background synchronization APIs are unavailable. Push notifications are an optional enhancement, not the only way users discover assigned work.

An installed shortcut is not evidence that offline work is safe. Conversely, lack of a service worker should not be described as proof that no browser can install the current site. The current audit found no implementation of the required offline behaviors.

## 12. Priority stickers, templates and printing

Deliver and physically validate printing early. Reuse the current ZPL renderer/history and authenticated queue; extend their functionality rather than presenting them as complete printer support. Current template dimensions are whole-mm width 75–150 and height 45–100, with 203/300 DPI. Actual paper size must be validated against the supported printer/media profile, including supported fractional dimensions.

Required scope: printer/media profiles and calibration; business/customer/product template defaults; visual configuration and actual-size preview; required/optional fields and overflow checks; logos/text/code layout; selected-carton/job/order/shipment bulk printing with correct unique identities/copies; direct print actions; searchable full history; controlled reprints/recovery; source-version/stale-label detection; private internal QR; and scan verification of the applied label before dispatch.

Support the actual business printer path: current network Zebra agent/ZPL, plus accurate PDF/browser sheet/label fallback where required. Define page dimensions, sheet rows/columns, spacing/margins and scale. Do not assume installing the PWA enables silent Bluetooth/USB printing on every phone/browser. Preserve the distinction between generated, queued, transferred, uncertain physical output and verified label application.

Required labels must not silently omit unavailable AWB/weight/identity or other required fields. Historical output stays unchanged after a source/template change, while the operational screen warns when the applied/generated sticker is outdated and guides controlled replacement. Internal warehouse scanning must work without requiring public QR publication.

The [printing acceptance contract](EXPORT_OWNER_CONTROL_REVIEW_2026-10-27.md#6-priority-sticker-and-printing-requirement) defines media/layout, batch, fallback, privacy, recovery and physical verification requirements. This is an early packhouse pilot gate, not a final-stage enhancement.

## 13. Backend relationship plan

These are conceptual additions; finalize names against the existing schema before implementation. Every tenant-owned record needs tenant scoping and appropriate composite relationships.

| Domain | Proposed addition/extension | Reuse |
| --- | --- | --- |
| Platform administration | Tenant lifecycle/history, plan versions/entitlements, agreement changes, defined usage events/snapshots, quota overrides, health evidence and support sessions | Existing businesses, onboarding, billing plans/subscriptions/invoices/payments, readiness tools and MFA. |
| Customer access | Customer identities/invitations, customer-to-business grants, portal sessions | Existing customers, hashed link patterns and authentication infrastructure. |
| Orders | Operational approval/fulfilment/pricing state; nullable pending prices; versioned commercial agreements | Sales requests/items, orders/lines and existing quantity snapshots. |
| Quotations | Link grants, decisions, delivery state and pricing FX snapshots | Quotation revisions, frozen HTML/PDF and confirmation service. |
| Sourcing | Order sourcing plans/lines, supplier awards, purchase/receipt demand allocations | RFQs, offers, purchases and goods receipts. |
| Work | Assignments, deadlines, completion/blocker/handover events | Existing users, memberships, packing/shipment actions and event patterns. |
| Workforce | Staff records independent of login, rota templates/publication, leave/availability, shift coverage, attendance breaks/corrections, approved timesheets, rates and output/payroll allocations | Existing shifts, attendance/events, payroll approval/posting/settlement/correction services. |
| Partner/transport control | Partner timelines, promised delivery schedules, source-date reports, transporter/forwarder identities, legs, custody handovers, delivery/quality claim cases and recall exposure | Existing directories, RFQs/purchases/receipts, batches/cartons, shipments/events and document snapshots. |
| Market buying | Buying trips, trip lines, advances, expense/return allocations | Market purchases and finance sources. |
| Inventory | Locations, reservations, counts/adjustments, transfers and material-use lines | Batches, stock movements, cartons and quality holds. |
| Currency | Currency catalogue/business currencies; rate versions/purpose; quote snapshots | Approved exchange rates and journal snapshots. |
| Payments | Unallocated receipts/advances, multi-document allocation, transfers/exchanges | Current payment/posting/reconciliation services. |
| Expenses and cost accounting | Expense/claim lines, obligations, private receipts, petty cash/vouchers/reconciliation, cost classification and source-to-order allocations | Current supplier bills, shipment costs, stock valuation, COGS relief, payroll and journals. |
| Business position | Trade terms/due dates, credit/dispute/follow-up records, account/maturity classification, owner capital/loan/drawing sources, daily reconciliation, commitments/forecast and initial cutover evidence | Existing balances, reports, opening/period controls and approved historical FX. |
| Assets/loans | Asset register/events, loan facilities/drawdowns/schedules/repayments | Financial accounts, posting requests, journals and historical rates. |
| Printing | Printer/media profiles, configurable layout/template versions, required/source field snapshots, print batches/copies, stale/reprint controls, material usage and applied-label verification | Existing label templates/generations, ZPL rendering, agent credentials/leases and transfer history. |
| Evidence | Private attachments, retention metadata, notification/outbox delivery and unified audit events | Current private object storage, mailables and source snapshots. |
| PWA | Device-specific draft/command queue and server command deduplication | Existing idempotency patterns, extended to compare payloads. |

Apply changes through service transactions. Keep external delivery outside long stock/finance transactions and use an outbox after commit. Use one consistent stock/reservation lock ordering and test it on PostgreSQL. Avoid assuming that per-business locks are harmless at scale; measure contention as usage grows.

Opening-data cutover needs its own controlled import and sign-off: physical stock and valuation layers, trade debt/due dates, cash/money accounts, existing orders/commitments, assets/custody, borrowing, advances and owner balances must reconcile. Do not invent historical purchases, receipts or invoices merely to use an existing screen to force a balance into the application.

## 14. Delivery sequence and exit criteria

These stages express dependencies, not time estimates. All stages are required before claiming the complete scope requested; a limited pilot can have an explicitly narrower acceptance boundary.

| Stage | Work | Exit criterion |
| --- | --- | --- |
| **0 — Repair the baseline** | Fix F01/F02/F03; add permanent regression tests for intended behavior; classify existing screens; establish explicit test memory configuration and a current feature inventory | Suspended supplier links refuse reads/writes; older eligible records remain selectable; unsupported currencies are refused; existing tests remain green. |
| **0A — Printing and login installation** | Supported printer/media setup, template/preview and batch workflow, required/private/stale label controls and recoverable print queue; desktop/mobile login install invitation and browser-specific fallback | Real target labels scan and match cartons with honest print states; installation invitation works without blocking sign-in/MFA. This is required before the packhouse pilot and proceeds ahead of later ERP scope. |
| **1 — Superadmin control centre** | Platform dashboard; business/owner lifecycle; plan and subscription changes; billing/renewals; defined usage counters/allowances; health and support/audit views | Superadmin can onboard, monitor, bill, restrict/reinstate and support an exporter through the UI; counters reconcile; permissions, reminders and expiry behavior are demonstrated. This stage is required before wider multi-business onboarding. |
| **2 — Connect the operating spine and people** | Operational quantity-only orders, customer submission/tracking, assignments, order workspace, split sourcing/reservations/market buying; partner history/date reporting; domestic/international transport legs/custody; staff register, rota and attendance exceptions | One order uses multiple sources with reconciled quantities, visible responsibility/staffing and traceable transport handovers. Supplier/date questions are directly answerable. |
| **3 — Complete commercial, expense and cost flow** | Quotation link/decision; currencies/pricing FX; deposits/advances, partial bills, allocations/transfers; terms/due dates/collection; expenses and daily cash close; approved payroll/COGS/order costing; simple financial commands and dated position/funding view | Money, stock value, COGS, labour, trade debt and commitments reconcile without duplicate payments/costs. Buyer currency/internal UGX history remain intact; owner sees supported balances, spending and near-term cash needs. |
| **4 — Finish exceptions, assets and borrowing** | Returns/corrections, repacking/counts/locations, assets/maintenance, loan subledgers, document checklists and complete period/reporting controls | Normal mistakes can be corrected with preserved history; assets/loans update subledgers/journals; required documents gate dispatch; management and financial reports explain incomplete data. |
| **5 — Field delivery and PWA** | Retest the stage-0A installed/printing experience; finish selected offline drafts/sync, simple role navigation, delivery retry/outbox, device testing, PostgreSQL concurrency, restore and real exporter acceptance | The same workflow succeeds on target devices, survives connectivity loss and duplicate submission, and has independently reviewed operational evidence across both platform and exporter workspaces. |

Simplify each workspace alongside stages 1–4. Do not postpone usability until all backend modules are finished. Move historical implementation notes out of the main feature inventory once a verified current status table replaces them.

### Phase 0 implementation — 27 October 2026

Phase 0 repairs F01–F03 locally. The original audit remains the dated record of the defects before repair.

| Baseline requirement | Delivered behavior |
| --- | --- |
| F01: suspended-business supplier access | Public supplier links require an active business. Quote submission checks the business, RFQ and supplier again inside the save transaction, with locks; stale loaded models cannot bypass suspension. Reinstatement restores an otherwise valid link. |
| F02: older finance records | Unbilled purchases and outstanding supplier/customer documents are filtered before pagination. Reference/party searches and separate page controls replace fixed candidate cutoffs. Posting-source queues and bank matching candidates are searchable and paginated; bank candidates match amounts on the current statement page. Payment, posting and match validation remain authoritative. Unrelated history limits elsewhere, including printing, remain separate work. |
| F03: currencies | A supported catalogue of 21 currencies replaces arbitrary three-letter codes. Owners choose permitted trading currencies in Business settings, retaining the reporting currency. New customer, purchase, RFQ, quotation, rate, shipment-cost and bank-account inputs use those rules. Historical customer currencies can be retained on edits; bank-statement import permits supported historical currencies. Disabling a trading currency does not rewrite existing documents or rates. |
| Compatibility | Businesses without saved choices use their reporting currency plus USD/EUR/GBP/AED until the owner saves explicit choices. Enabling a currency does not supply an FX rate. Full pricing-rate snapshots, precision policies and internal UGX presentation remain Phase 3 requirements. |
| Database | Two additive migrations add nullable business currency settings and document/payment lookup indexes. Applied to the local development database; no production deployment. |
| Verification | 201 tests and 3,230 assertions pass, including 29 new baseline regression cases. Normal test execution now has an explicit 512 MiB allowance. Blade compilation and the new settings route check pass. Tests use isolated SQLite and simulated mail. PostgreSQL concurrency, production-scale performance, browser usability and physical printers remain unverified. |

### Current feature inventory after Phase 0

This is a scope summary, not a production-acceptance certificate. The [audit](EXPORT_BUSINESS_AUDIT_2026-10-27.md) and [owner-control review](EXPORT_OWNER_CONTROL_REVIEW_2026-10-27.md) contain the detailed working/partial/missing classifications.

| Area | Current position | Next completion stage |
| --- | --- | --- |
| Tenant access, roles and supplier links | Existing tenant/role controls; suspended supplier links now refuse access and writes | Platform lifecycle/support controls in 1; concurrency acceptance in 5 |
| Currency entry and finance candidate selection | Baseline defects repaired and regression tested | Connected commercial FX and money flows in 3 |
| Stickers and installation | Phase 0A software delivered locally; physical printer and device acceptance pending | 0A field gate |
| Superadmin | Phase 1 control-centre software delivered locally; production operational acceptance remains open | 1 release gate; infrastructure/device acceptance in 5 |
| Orders, procurement, receiving and packing | Working modules; quantity-only operational commitments, sourcing splits and assignments incomplete | 2 |
| People and transport | Shifts/clock events and shipment fields exist; staff register, rota-to-work/payroll and transport handovers incomplete | 2–3 |
| Finance and business position | Ledger/manual FX, approvals, payments and reconciliation exist; daily cash, expenses, due dates, deposits, full COGS and owner position incomplete | 3 |
| Stock exceptions, assets and borrowing | Existing traceability and some corrections; complete stock exceptions, asset/maintenance and loan subledgers incomplete | 4 |
| Offline operation and field readiness | Partial PWA foundation; controlled offline work and real-device/exporter acceptance incomplete | 5 |

### Phase 0A implementation — 27 October 2026

**Software delivered locally; physical acceptance remains open.** The user's first printer is a Zebra GK888t using 100 × 50 mm stickers, with Windows, Mac and Android operators. Follow the [printing setup and acceptance guide](zebra_agent/README.md).

| Area | Delivered behavior |
| --- | --- |
| Printer/media setup | GK888t 203 dpi / 100 × 50 mm defaults; separate configurable ZPL/PDF profiles, fractional mm dimensions, margins, offsets, media sensing, orientation and recorded physical test evidence. Wrong GK888t DPI/oversized print width is rejected. |
| Templates | Saved/versioned approval by an authorized template manager; text fields and required values, customer/product defaults and restrictions, automatic or coordinate-based layout, ordering, wrapping, alignment, font size and optional logo. Existing templates can be upgraded by saving in the new preview layout. This is a form-based layout editor, without a drag-and-drop canvas. |
| Artwork and batches | Up to 50 selected cartons and 1–10 copies each, subject to an artwork-size budget; immutable per-carton source, template, PNG and ZPL records with integrity checks. Preview, PDF and ZPL use the same monochrome artwork. Missing required fields, overlap and overflow reject the entire batch. Separate carton/revision identities and a manifest avoid repeating the first carton's identity. |
| Output | Actual-size PDF label pages or explicit sheet dimensions/grid/gaps/offsets; original ZPL and shared-workstation queue. Entry points on carton, packing-job, order and shipment records; historical generations are searchable and paginated. Archive downloads are labelled as historical output. |
| Windows/Mac/Android | Bundled helper with guided setup for an installed Windows RAW or Mac CUPS printer queue, including USB; optional existing private-LAN TCP transport. Android can queue to the shared workstation or use a compatible system PDF print service. Python and an appropriate installed printer/driver are prerequisites for the helper. Native spooler/driver compatibility remains a field test, not a simulated-test claim. |
| Recovery | Copies included in queue delivery; expired/partial deliveries become uncertain without automatic resend. Inspection confirmation/reason is required for cancel/resolve/reprint; linked retry jobs preserve prior evidence. Local started-transfer receipts prevent blind repeat delivery after a partial send. “Transferred” means OS queue/connection acceptance, not paper output. |
| Privacy and dispatch | Internal QR routes require authentication and tenant permission; no publication is required. Revision-code scans and operator confirmation record applied verification. Source/template changes flag stale labels and block new queue/output/verification. Owners can require current verified carton labels before dispatch; this gate is optional until the business enables it after setup. |
| Login installation | Shared invitation on public/sign-in and authenticated layouts, including platform pages; native Chromium prompt only following a click; iPhone/iPad, macOS Safari and other-browser guidance. Seven-day device-local dismissal where storage is available; standalone windows hide the invitation. Authentication/MFA remain authoritative. Manifest identity/icons and a network-only service worker are included. No private pages or business writes are cached; offline operation shows a connection-needed message. |
| Verification | 226 PHP tests / 3,436 assertions pass, including 25 new Phase 0A cases; 9 JavaScript installation/service-worker tests and 4 Python workstation transport tests pass. Blade compilation and route checks pass. A generated four-page PDF was rendered and visually reviewed: each page is 100 × 50 mm for two cartons with two copies. The local login page was visually reviewed in the in-app browser. One additive migration was applied locally after taking a SQLite backup; foreign-key checks pass. Nothing was deployed. |

**Open Phase 0A exit evidence:** actual GK888t calibration/readable codes/correct carton identity; Windows USB RAW and Mac queue/PDF tests; Android queue/PDF tests; real desktop/mobile installation and MFA launch; partial-copy/lost-acknowledgement recovery on equipment. These cannot be certified by HTTP tests, PDF geometry or an in-app browser. Packing-material stock/consumption accounting and controlled offline work remain later phases. PostgreSQL concurrency and production-scale performance remain field/release checks.

**Next ERP implementation stage: 1 — superadmin control centre.** Keep the Phase 0A physical/device gate open until its evidence is recorded; the packhouse pilot must not treat untested printer support as accepted.

### Phase 1 implementation — 27 October 2026

The control centre is implemented in the existing Laravel application. Platform staff land at `/platform`; exporter workspaces remain separate. This is local software delivery, not production rollout or proof that infrastructure is healthy.

| Control | Delivered behavior |
| --- | --- |
| Six-area workspace | Overview, Businesses, Billing, Usage & Health, Support & Audit, Settings. Directory search and lifecycle/security/cover filters run before pagination; each business has Overview, Subscription, Usage, Access, Health and History tabs. Desktop and narrow-screen layouts reviewed with test-only data. |
| Dashboard | Kampala reporting/comparison periods, gross verified platform receipts, current outstanding invoices, approximate paid recurring contract value, activity and current account states. Collections link to invoice evidence; tenant export turnover is excluded. Current balances/states are explicitly distinguished from historical period activity. |
| Business lifecycle | Profile correction; onboarding milestones; dated trial/exemption; security suspension/reinstatement; recoverable closure/reopening. Closure and suspension preserve source records. Billing exceptions do not lift security suspension. No subscription means restricted access unless a recorded trial, dated exemption or 30-day legacy review applies. |
| Owner access | Verified-purpose first-owner invitation, resend/revocation and explicit transfer. Superseded links stop working; the previous owner remains until the replacement accepts. Seat capacity is checked during invitation, acceptance and reactivation. Delivery payloads are encrypted and excluded from views. |
| Plans and agreements | Versioned module/seat/product-media terms; frozen agreement/invoice snapshots; dated absolute allowance overrides; contact/grace changes; pause/resume/cancel; next-unissued-period plan/price changes. Existing invoices retain their original terms. Explicit agreed prices support negotiated discounts; no automatic mid-cycle proration. |
| Billing | Frozen payment instructions, repeat-safe period issuance, partial/full verified receipts, reference deduplication, credit/refund/void history and source reconciliation. Refund entries record external evidence and reopen cover/balance unless credited. They do not transfer money. Void history remains retained; replacing a voided period requires a new reviewed agreement. |
| Access and entitlements | Existing business records remain readable after billing expiry; new module work is checked on the server. Staff get an owner-contact route; owners can still remove staff access while renewal is restricted. Public supplier quotation writes also check cover/module entitlement inside the transaction. Webhook evidence intake and acknowledgements for existing print jobs remain available subject to their security checks; they do not authorize new operational work. |
| Usage | Source counts for confirmed orders, receipts, cartons, shipments and provider events; distinct internal users per Kampala date; current active/reserved seats; measured product-object bytes and saved-artifact bytes. Repeated period reconciliation updates the same snapshot. Product-media quotas refuse unmeasured legacy capacity rather than treating it as zero. Generated artifacts, logs, backups and other files are excluded from that quota and disclosed separately. |
| Renewals and delivery | `platform:review` reconciles agreements, prepares agreed renewals, deduplicated weekly reminders/warnings, assigned renewal cases and source snapshots. `--dry-run` writes/sends nothing; ordinary review prepares notices; `--notify` attempts configured mail. Failed/uncertain delivery retains attempts and authorized retry; a log entry/provider acceptance does not prove inbox delivery. |
| Support and oversight | Business/billing/support platform roles; audited superadmin role management; assigned, due, acknowledged/resolved cases with resolution evidence. Owner-approved, operator-bound, read-only support grants last at most four hours, expire/revoke on every access, and expose workflow metadata without financial amounts or credentials. Owners can review support access history. |
| Health, cost and data | Failed jobs/deliveries, printer-job issues and stale agents from source records. Backup/restore/worker/storage/error/release/cost observations carry source, timestamp and bounded validity; absent/stale evidence is unknown. Costs are operator-entered allocation estimates, not complete metering. Owners can download a tenant-scoped structured export with exclusions in its manifest, or request additional files/retention/offboarding follow-up. No automatic deletion. |
| Database and verification | One additive migration applied to the backed-up local SQLite database; previous source-table row counts preserved and foreign-key check clean. **260 tests / 3,691 assertions pass**, including **34 new control-centre regression tests**. Blade views compile. No production deployment, real email or external money movement performed. |

**Release acceptance still open:** verify configured production mail and its delivery/recovery process; enable the single-cluster scheduler only after review (`EXPO_BILLING_SCHEDULE_ENABLED=true`, disabled by default); rehearse migration/rollback and concurrent invitation/payment/renewal changes on PostgreSQL; measure lock contention and large-directory performance; record independent backup/restore/release evidence. Infrastructure observations and cost allocation currently need an operator; automatic monitoring-to-case/escalation integration and complete infrastructure cost attribution remain open. Support cases expose due/overdue ownership and manual priority escalation; they do not claim a staffed paging service.

Existing businesses receive an explicit 30-day legacy review at migration. Recorded cover without supporting invoice history is preserved as a declared legacy baseline; invoice-backed coverage remains subject to later credit/refund reconciliation. Review those imported terms before widening onboarding. Activity tracking and byte measurement start with this release; historical activity must not be inferred as zero.

The Phase 0A physical gate remains open: GK888t 100 × 50 mm output/scan acceptance and actual Windows, Mac and Android installation/printing checks. Phase 2 is the next implementation workstream: connect quantity-only demand, split sourcing, staff market buying, responsibilities, transport and the people workflow. It does not imply those capabilities were completed by this platform phase.

## 15. Required platform and business acceptance scenarios

| ID | Scenario | Pass condition |
| --- | --- | --- |
| A01 | Customer submits 1,000 kg without prices; staff can enter the same request | Correct tenant/customer, no mandatory amounts, no fabricated zero-value invoice, visible submission and entering actor. |
| A02 | Split 1,000 kg into supplier A 400, supplier B 350 and market buyer 250 | Commitments total 1,000; partial awards leave a visible remainder; overcommitment is refused or explicitly approved. |
| A03 | Reserve stock for two competing orders | Same physical units cannot be double-reserved, including concurrent PostgreSQL requests. |
| A04 | Receive partial deliveries and reject some produce | Accepted/rejected/remainder reconcile; only accepted stock becomes usable; replacement or short-close is explicit. |
| A05 | Staff receive a market advance and buy from several vendors | Disbursement equals supported spend plus returned money plus outstanding accountability; each purchase links to received goods and demand. |
| A06 | Assign packing and logistics with deadline and handover | Owner sees assignee, due time, progress and blocker; unauthorized staff cannot reassign/approve. |
| A07 | Issue USD quotation using illustrative 3,700 UGX/USD pricing rate | Client sees USD; authorized staff see frozen UGX equivalent; later rate changes leave issued terms unchanged. |
| A08 | Client accepts a quote, then tries an obsolete/expired link | Current acceptance is attributable to the buyer; obsolete, revoked and expired decisions are refused; staff-entered acceptance has evidence. |
| A09 | Partial packing, loss and two shipments fulfil one order | Batch units reconcile; no double deduction at dispatch; shipped/delivered/remainder are correct; complete orders leave the active backlog. |
| A10 | Receive a customer deposit before dispatch, then invoice and settle | Deposit remains correctly classified until allocated; one money receipt is not counted twice; invoice and settlement historical rates remain intact. |
| A11 | Pay part of a supplier commitment before all deliveries | Advance/partial bill reflects the real event and is reconciled to later receipts/bills, without fake complete delivery. |
| A12 | Exchange USD bank funds into UGX with a fee | Both actual account balances, conversion amounts, fee and evidence reconcile to approved journals and statement lines. |
| A13 | Pay an invoice/bill older than 75 newer records | Search finds the eligible document; balance and allocation are correct; no hidden cutoff. |
| A14 | Correct receipt/packing/invoice after downstream activity | Controlled reversal/amendment preserves originals, updates availability/balances correctly and respects closed periods. |
| A15 | Register equipment, assign it, depreciate and dispose | Custody and financial carrying value agree with approved asset events/journals; disposal has evidence. |
| A16 | Record a foreign-currency loan drawdown and repayment | Principal, interest/fees, schedule, FX and cash balances reconcile; no repayment is treated wholly as an operating purchase. |
| A17 | Disable a business and revoke a user's membership | Applicable staff, customer/supplier links, devices/integrations and pending sync writes refuse access; another business remains unaffected. |
| A18 | Attempt cross-tenant access through every new identifier/file/link/job | No unauthorized reads/writes or existence disclosures; jobs and reports retain tenant context; support access is logged. |
| A19 | Install PWA, lose connectivity, enter drafts, reconnect twice | Drafts survive appropriately, clearly show unsynced state, synchronize once, and surface stock/permission conflicts rather than overwrite them. |
| A20 | Sign out or switch business on a shared phone | Prior business's private drafts, cached data and queued commands cannot leak or replay under the new context. |
| A21 | Simulate email failure and retry | Visible delivery failure, recoverable link/invitation, no duplicate onboarding or contradictory “sent” status. |
| A22 | Owner checks money and business progress | Posted versus unposted/reconciled is clear; advances, shortages and overdue work are visible; profit is qualified when costs are incomplete. |
| A23 | Restore backup and retry a concurrent stock/payment action | Restored records and private files are usable; final state has no duplicate money or stock consumption; restore evidence and responsible reviewer recorded. |
| A24 | Superadmin opens the platform dashboard across several businesses | Business/subscription totals reconcile to filtered records; tenant activity is scoped; metrics show period and freshness; unknown health/usage is not shown as zero/healthy. |
| A25 | Convert trial, change plan, renew, partially pay and cancel subscription | Effective dates, historical invoice terms, balances and entitlement state agree; billing changes and reminders/retries are recorded; cancellation preserves business data. |
| A26 | Reach a staff/storage allowance and replay a usage event | Accurate warning and intended server restriction; duplicate events do not inflate usage; dated overrides are audited; record recovery/export remains available. |
| A27 | Backup ages, mail fails or a tenant queue action fails | Usage/health dashboard identifies affected business, evidence timestamp and responsible action; it does not invent a successful backup or delivered message. |
| A28 | Superadmin recovers an owner and performs authorized support | Verification, scope, expiry and reason are recorded; support actions are attributable; unrelated tenant information remains inaccessible. |
| A29 | Submit a paid expense, unpaid bill, employee claim and petty-cash return | Evidence, approval, liability/reimbursement, payment and cash count reconcile; retries/claiming the same receipt do not pay it twice. |
| A30 | Cost a produce batch using purchase, applicable labour/materials and grading loss, then partly ship | Approved classification and quantity/value reconciliation explain remaining inventory, COGS, loss and order contribution; source costs are not counted twice. |
| A31 | Publish a weekly rota, approve leave and replace a worker on an overnight shift | Staffing/skills coverage and conflicts are visible; approved changes retain history; the worker sees the correct shift in Uganda time. |
| A32 | Record breaks, late arrival, absence and a missed clock-out correction | Actual versus planned time is distinct; authorized correction preserves observations/reason/approval; unapproved or missing time does not silently become payable. |
| A33 | Prepare daily/hourly/monthly/piece-rate wages from approved time/output | Effective rates, output and approved adjustments explain earnings; no duplicated output/wage event; payroll posting/settlement agrees with the order labour allocations. |
| A34 | Schedule/pay a casual worker with no application login | Authorized supervisor can record work and payroll can settle it; worker is not granted system access or charged as an internal login seat by accident. |
| A35 | Owner reviews monthly expenses, COGS, attendance and profitability | Reports drill down to approved sources, preserve original currency/UGX and identify incomplete/unposted costs; controls are accessible from the existing six work areas. |
| A36 | Owner asks who supplied a product on a selected date | Supplier/vendor/product/receipt/date filters reconcile to accepted/rejected quantities, receiver, batch and bills; export/print retains filter/date/currency context. |
| A37 | Export goes from market/packhouse to airport then overseas | Every applicable transporter/leg, driver/vehicle, handover and booking/AWB is traceable to cartons, responsible people and cost. |
| A38 | Owner opens the business position for a historical date | Cash, debts, current/fixed assets, stock, liabilities/equity and incomplete postings reconcile; commitments/projections are not presented as posted balances. |
| A39 | Customer owes a foreign invoice with agreed terms, partial receipt and dispute | Document due date/history and aging are correct; invoice/receipt/credit/deposit/FX are distinct; collection task has a responsible person. |
| A40 | Daily spending includes stock, wages, rent, equipment, advance, bank transfer and loan repayment | Money-out view includes all actual movements with proper meaning; expense/profit totals exclude inappropriate double classification; cash count and pending records are visible. |
| A41 | Owner contributes/withdraws funds or makes a loan to the business | Explicit transaction type, evidence/approval and equity/loan/cash effects reconcile without inventing sales or hiding personal withdrawals in operating costs. |
| A42 | Configure the business's real sticker media and printer | Supported fractional/mm dimensions, DPI, orientation/margins/media calibration and approved template print at correct size with readable codes on the actual device. |
| A43 | Bulk print selected cartons for an order/shipment | Correct unique carton identity and template/version for every selected carton; copies/counts reconcile to the batch manifest; no wrong-carton repetition. |
| A44 | Required AWB is absent, or long text overflows a small label | Generation is blocked with actionable validation or explicit approved layout adjustment; no silent omission/clipping of required data. |
| A45 | Print transfer succeeds but acknowledgement is lost | Status remains honest about uncertain physical output; operator can resolve/reprint with history; no claim of guaranteed exactly-once paper output. |
| A46 | Weight/flight changes after a label was generated/applied | Original remains available, new source revision is detected, stale sticker is flagged and controlled replacement verified before dispatch. |
| A47 | Scan an internal carton and find an old label generation | Authenticated traceability works with public visibility off; no tenant leakage; older eligible output can be found/reprinted with reason outside the latest-60 list. |
| A48 | Open web login on desktop/Android and choose Install | When browser prompt is available it follows a user click; sign-in/MFA remains usable; installed launch reaches the correct business/platform workspace. |
| A49 | Open iPhone/macOS/unsupported-browser login and dismiss installation | Correct guided path instead of a broken button; Not now respected; no repeated overlay, credential loss or false install-success message. |
| A50 | Owner sees overdue supply, short stock, failed printer and missed collection | Each exception has evidence/date, responsible person, due action and escalation; acknowledgements/closures are auditable and duplicate notifications controlled. |
| A51 | Superadmin compares adoption, service costs and a printer outage | Defined period/freshness, source-reconciled usage/cost estimates and affected-tenant issue owner; detailed tenant records require authorized support. |
| A52 | Bring a running exporter into the ERP with opening balances | Stock/cost layers, trade debts/due dates, cash, owner balances, assets, loans and advances reconcile to a signed-off cutover; pending orders are retained without fictitious business events. |
| A53 | Trace a quality claim back to a source batch | Identify all affected cartons/shipments/customers, hold what remains, assign follow-up and link replacement/return/credit outcome. |

Use the existing feature suite for regression coverage, then add the missing scenarios at HTTP/service level. Add actual browser/device checks for the PWA and core forms. Keep live bank/carrier/printer verification and exporter sign-off separate from simulated tests.
