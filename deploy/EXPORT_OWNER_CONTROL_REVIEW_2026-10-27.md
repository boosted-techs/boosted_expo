# Owner and superadmin control review

**Date:** 27 October 2026, Africa/Kampala  
**Purpose:** audit the [first system audit](EXPORT_BUSINESS_AUDIT_2026-10-27.md), [revised roadmap](EXPORT_BUSINESS_ROADMAP_2026-10-27.md), and current local routes/implementations against the questions an exporter owner and platform owner must answer.  
**Deliverable:** additional requirements and release acceptance criteria. This review changes documentation only; it does not implement printing, dashboards or PWA installation.

## 1. Can the proposed system serve a Ugandan exporter?

Yes, provided completion is judged by the owner's ability to obtain reliable answers and take action. The current system cannot yet deliver all of those answers. The previous roadmap establishes an appropriate foundation but leaves some important control details too general: partner histories, transport custody, due dates/credit control, working-capital visibility, opening operational balances, printing and login installation behavior.

A module being present is insufficient. A useful answer identifies the business and date/period, shows quantities in their actual units and money in its actual currency, explains incomplete/unposted data, identifies the responsible person and links to the underlying evidence/action. “Who supplied what on 15 October?” must not require manually joining purchases, receipts, stock, packing and bills.

The acceptance boundary is a business with connected demand, supplier and market procurement, produce handling, staff, export logistics, equipment, obligations, money and books. Keep the six exporter work areas and the separate superadmin workspace. Add coherent detail and backend relationships rather than a new navigation item for every question.

## 2. Fresh verification and current route evidence

The local application still exposes **237 application routes**. The relevant current routes were re-inventoried. **23 existing tests, 253 assertions passed** using the Zebra label, phase-three integration, staff/dashboard and procurement test files with a 512 MiB PHP memory limit. These tests exercise current behavior; they do not prove the new requirements below are implemented.

No live printer, production database, customer messages or device installation was used. No new application behavior was written. Prior full-suite and defect-probe results remain historical evidence in the first audit, not a new full-suite run in this follow-up.

| Evidence ID | Current routes/code | What exists | Remaining control limitation |
| --- | --- | --- | --- |
| E01 | `GET /workspace/{section?}`; `WorkspaceMetrics` | Counts for confirmed orders, ready batches, preparing shipments and pending finance reviews | No complete today/exception/business-position dashboard; an all-time confirmed count is not open work due today. |
| E02 | Supplier directory GET/POST/PUT; RFQ/award routes | Supplier master and quotation transactions | No supplier detail/history/report route or combined promised/received/rejected/paid timeline. |
| E03 | `GET /purchases`, `GET /purchases/{purchase}`, `GET /receiving` | Purchase/receipt records with supplier/vendor source and dates | Purchase/receiving lists lack supplier/product/date-range reporting filters; supplier identity is not displayed on the receiving register. Market vendor data is free text. |
| E04 | Staff/shift/clock routes; `StaffOperations`, `FinancePayroll` | Individual shifts, clock records and manual payroll | No complete weekly rota, work assignment, approved-time/output-to-wages or worker-accountability view. |
| E05 | Stock/packing/carton routes; batch/stock services | Source batches, physical quantities, quality holds, losses and carton traceability | Reservations, locations, stocktake and initial-stock cost-layer workflows remain incomplete; there is no owner recall investigation workflow. |
| E06 | Shipment GET/POST/PUT; `ExportShipment` | Free-text airline/carrier, flight, AWB and shipment events | Air scope only; no transporter master, driver/vehicle, transport legs or custody/handover records. |
| E07 | `/finance/documents`, payment/review routes | Posted-document balances, manual payments and controlled posting | Not a complete customer/supplier statement, credit control or daily cashbook. Export commercial invoices and supplier bills lack dedicated due-date fields; SaaS billing due dates belong to a different domain. |
| E08 | `/finance/reports`, `/finance/reports/account/{account}`; `FinanceStatements` | Base-currency statements and account ledger | Accounts grouped as asset/liability/equity, without a complete current/fixed asset or maturity classification; no integrated cash forecast or operational-commitment view. |
| E09 | Inventory valuation/COGS, shipment cost and contribution services | Produce cost basis, COGS/waste relief, paid shipment costs and provisional contribution | Full overhead/labour/material linkage and general operating finance remain incomplete. |
| E10 | `/labels`, template edit/update, carton generation, label history | Whole-mm width 75–150, height 45–100, 203/300 DPI, selectable fields/codes, frozen ZPL | Bounded size selection is real. Media profiles, visual layout, preview, fractional sizes, logos, sheet layouts and batch printing are not implemented. |
| E11 | `/labels/printers`, queue submit; authenticated agent next/ack | Tenant device credentials, idempotent queue, leased delivery, original label snapshots and transfer status | One generation per submission; no copy/selection wizard, applied-label scan, printer/media profile or physical print confirmation. Queue picker shows only latest 60 label generations. |
| E12 | Login view/public layout/manifest; public scripts | Login, authenticated manifest link and standalone metadata | Public/login layout has no manifest link; no install invitation, install-event handler, icons or offline implementation was found. |
| E13 | `/platform/businesses`, `/platform/billing`, subscription/invoice actions | Exporter creation and selected platform commercial actions | No platform overview, measured usage dashboard or complete business/subscription lifecycle controls. Fixed commercial list limits also need searchable/paged access. |
| E14 | Active-business middleware, permissions, approval/events and release/operations tools | Tenant/role controls and several audit histories | Alerts, report completion, platform support controls and unified audit/cutover acceptance need explicit implementation. |

## 3. Owner question catalogue

The tables below are the required answer contract. Each question needs an approved definition, an underlying source/query, visible completeness, permissions, an action and an acceptance test. “Partial” means supporting records exist; it does not mean the requested owner answer already exists.

### Today and responsibility

| ID | As owner, I ask | Required answer and action | Current coverage |
| --- | --- | --- | --- |
| O01 | What must we deliver today and this week? | Open orders, quantities/units, promised dates, readiness, responsible person and delivery/cargo cutoff; open the delayed order. | Partial: E01/E06; complete due-work view missing. |
| O02 | What is being bought, received, graded, packed or dispatched today? | A dated operation board with targets versus actual quantities and source records. | Partial: E03/E05/E06; combined board missing. |
| O03 | What is blocked, short, late or likely to spoil? | Exceptions ordered by deadline/impact, with reason, owner and recommended next action. | Partial holds/stock: E05; consolidated exception workflow missing. |
| O04 | Who is handling each order or job right now? | Planned assignment, shift, latest progress, outstanding task and handover. | Action actors exist; planned work ownership missing: E04. |
| O05 | What needs my decision and what happens if I delay? | One approval inbox with amount/quantity, evidence, deadline and approving authority. | Separate approvals exist: E14; unified owner queue missing. |
| O06 | What changed since I last checked, including backdated entries? | Business activity timeline with event date, recorded time, actor and approved correction. | Separate events exist: E14; complete timeline missing. |

### Suppliers and market sourcing

| ID | As owner, I ask | Required answer and action | Current coverage |
| --- | --- | --- | --- |
| O07 | What has each supplier committed to, and when? | Promised product/grade/quantity, acknowledged commitment, expected arrival, actual fulfilment and remainder. | RFQ/PO data partial: E02/E03; split commitment timeline missing. |
| O08 | Who supplied what on a selected date? | Filter supplier/vendor, product, purchase/receipt date and status; show accepted/rejected quantities, receiver, receipt and batch. Export/print the filtered register. | Source data exists: E03; combined searchable report missing. |
| O09 | Who supplied short, poor-quality or rejected produce? | Expected versus delivered/accepted/rejected, reason, corrective action, replacement/return and financial effect. | Receiving/rejections partial: E03/E05; supplier exception follow-through missing. |
| O10 | Which suppliers are dependable? | On-time and complete delivery, acceptance rate, claims, agreed period and sample count; never infer quality from purchase volume alone. | No computed supplier scorecard: E02/E03. |
| O11 | What do we owe this supplier, or what advance do they owe us? | Opening balance, bills/credits, payments, advances, due dates and unapplied balances by currency. | Posted bill/payments partial: E07; statement/advances/aging missing. |
| O12 | Which source can cover the remaining demand at an acceptable cost? | Available supplier commitments/stock and market assignments, relevant costs in original/UGX currency, lead time and quality history. | Separate quotes exist: E02/E09; connected sourcing comparison missing. |

### Staff and workforce

| ID | As owner, I ask | Required answer and action | Current coverage |
| --- | --- | --- | --- |
| O13 | Who should be here, who is here, and are we adequately staffed? | Weekly rota, leave/availability, actual attendance and required skills/headcount; arrange replacement. | Individual shifts/clocking partial: E04. |
| O14 | What work was each person assigned, and what did they finish? | Task/order/packing output with quantities, time, quality and approved completion. | Action history partial; assignment/output view missing: E04/E05. |
| O15 | Who is stuck or overloaded, and who can take over? | Open tasks, deadlines, reported blockers, workload and recorded handover. | Missing connected task controls: E04. |
| O16 | What wages have they earned and what remains unpaid? | Approved attendance/output × agreed effective rate, adjustments, payroll approval and payments. | Manual payroll exists; calculated inputs missing: E04. |
| O17 | Who has business money or equipment to account for? | Staff advances, purchases/claims, cash returned, outstanding amount, assigned assets and due handback. | Advances/custody incomplete: E07/E08. |
| O18 | Who can spend, approve, change stock or access private figures? | Active roles/limits, recent sensitive changes and approver separation; suspend inappropriate access. | Role/access controls exist; limits/unified review incomplete: E14. |

### Produce, stock and losses

| ID | As owner, I ask | Required answer and action | Current coverage |
| --- | --- | --- | --- |
| O19 | What did we order, buy and actually receive? | Distinct ordered/purchased/received/accepted/rejected quantities, amount where known and remaining source commitment. | Purchase/receiving data partial: E03. |
| O20 | What stock is available, reserved, being prepared, packed or held, and where? | Product/batch/location/state quantities and value; do not count packed and loose stock twice. | Loose/carton/hold data partial: E05/E08. |
| O21 | Can I promise a new order without taking stock from another customer? | Free-to-promise quantity after reservations and approved expected supply, with uncertainty identified. | Order reservation/availability planning incomplete: E05. |
| O22 | Where are we losing weight, stock or money? | Input/output/loss reconciliation by batch/job/date, reasons, value, approved adjustments and responsible actions. | Packing loss records exist; complete yield/cost view missing: E05/E09. |
| O23 | Do we have enough cartons, labels and other packing materials? | Consumable on-hand, expected usage, reorder alerts, stock issues and damaged/wasted labels/materials. | Produce stock exists; material/consumption control incomplete: E05. |
| O24 | If a batch is unsafe, which cartons and customers are affected? | Forward/backward batch trace, shipment/customer exposure, hold/recall case, contact action and disposition. | Traceability exists; recall case/control/report missing: E05/E06. |

### Transport and customer delivery

| ID | As owner, I ask | Required answer and action | Current coverage |
| --- | --- | --- | --- |
| O25 | Is the export ready before its collection/airport cutoff? | Preparation, stickers, paperwork, customer terms and transport readiness, with blockers and owner. | Shipment ready/dispatch checks partial: E06/E11/E14. |
| O26 | Which transporter was used for this export? | Each market/packhouse/airport/international/destination leg, carrier/forwarder, vehicle/driver/contact where applicable, booking/AWB and agreed cost. | Airline text/AWB exist; domestic legs/master missing: E06. |
| O27 | Who handed over which cartons to whom and when? | Carton/count/weight scan or manifest, releasing/receiving party, timestamps, location and proof/exception. | Shipment event exists; custody handover incomplete: E06. |
| O28 | Which required documents are missing, expiring or rejected? | Product/destination checklist, attachment/revision, reviewer, expiry and dispatch gate/authorized exception. | Document issuance/reference reviews partial: E06/E14. |
| O29 | Where is the shipment, why is it late, and who follows up? | Planned/manual/provider milestones distinguished, latest source/time, exception contact and follow-up deadline. | Manual shipment/provider evidence partial: E06. |
| O30 | Did the customer receive the correct goods, and are there claims? | Delivery evidence, shortages/damage/quality dispute, owner, resolution deadline, return/credit/refund and impact on debt/margin. | Manually entered delivery/limited credits exist; case flow incomplete: E06/E07. |

### Stickers and printing

| ID | As owner, I ask | Required answer and action | Current coverage |
| --- | --- | --- | --- |
| O31 | Which printer, paper and sticker template should this job use? | Approved printer/media/template defaults by customer/product/job, dimension/DPI compatibility and test-print record. | Dimensions/templates/agents exist; profiles/defaults incomplete: E10/E11. |
| O32 | Will the required information fit and will the codes scan? | Actual-size preview, required-field/data checks, layout overflow and barcode/QR validation before printing. | Some vertical fit checks exist; visual/device validation incomplete: E10. |
| O33 | Can we print all cartons for this order/shipment together? | Selected unique carton identities, correct quantity/copies, sequence and a batch manifest. | Individual generation/queue only: E10/E11. |
| O34 | Which labels failed, were duplicated or need a controlled reprint? | Queue/transfer/physical observation statuses, attempts, original ID, reprint reason/permission and uncertain-delivery resolution. | Delivery/lease controls partial; full recovery/reprint workflow missing: E11. |
| O35 | Are the labels still correct after a weight, buyer, flight or AWB change? | Source dependency fingerprint/version, stale-label warning and controlled replacement keeping old copies in history. | Immutable ZPL exists; source-staleness workflow missing: E10/E11. |
| O36 | Are the right stickers on the right cartons before export? | Scan/verify applied carton label against dispatch selection and template/source revision; identify unlabelled/mismatched cartons. | No applied-label verification gate: E11. |

### Money, assets and obligations

| ID | As owner, I ask | Required answer and action | Current coverage |
| --- | --- | --- | --- |
| O37 | How much money do we have in cash, each bank and mobile money? | Account/currency, book balance, counted/reconciled balance as of a date, unposted movements and restricted/committed amounts separately. | Ledger/bank profiles partial: E07/E08; unified cash position missing. |
| O38 | Who owes us, how much, and when should they pay? | Customer statement, due date, overdue buckets, disputed balance, deposit/unapplied credit and follow-up owner. | Posted invoice balances exist; export payment terms/due dates/collection control incomplete: E07. |
| O39 | Whom do we owe, and what must be paid next? | Supplier bills, payroll, expense claims, loan principal/interest/fees and scheduled due dates; show unbilled commitments separately. | Several source balances exist; maturity/payments-due view incomplete: E07/E08. |
| O40 | What current assets and fixed assets do we have? | Cash/receivables/advances/stock and equipment register with original cost, carrying value, location/custodian, condition and maintenance. Distinguish owned, hired and third-party items. | Financial asset category exists; classification/register/custody incomplete: E08. |
| O41 | How much did we spend today, on what and by whom? | Money-out register with recipient, reason/category, account, currency, evidence and approval; distinguish expenses, stock purchases, assets, advances, transfers and loan repayments. | Payments/paid shipment costs partial: E07/E09. |
| O42 | Can we fund next week's buying, wages and exports? | 7/30-day cash projection from timed collections, commitments and obligations, confidence/assumptions and expected shortfall. | No integrated forecast: E07/E08. |

### Performance and trust in the position

| ID | As owner, I ask | Required answer and action | Current coverage |
| --- | --- | --- | --- |
| O43 | What will this order cost, and what is its actual margin so far? | Estimate/commitment/actual comparison, pricing pending where appropriate and completeness of produce/labour/material/export costs. | Provisional shipment contribution exists; full order sheet incomplete: E09. |
| O44 | Which products, customers or routes make or lose money? | Revenue, COGS, losses, direct export expenses and allocated overhead shown distinctly by period/dimension, using consistent rates/policies. | Basic statements exist; dimensional result missing: E08/E09. |
| O45 | What does the business own, owe and retain at this date? | Reconciled assets, liabilities, equity and results, with current/non-current classifications, commitments and incomplete data clearly separated. | Statements exist; complete position view/classification missing: E08. |
| O46 | How much have owners put in or taken out? | Contributions, drawings/distributions, reimbursements and loans to/from owners kept distinct with approval/evidence. | Opening equity exists; ongoing owner-transaction workflow missing: E07/E08. |
| O47 | What is suspicious, unsupported or still outside the books? | Duplicate references/claims, excessive price/weight variances, missing evidence, overdue reconciliation, changed roles and unposted sources. | Several safeguards/events exist; combined risk queue missing: E14. |
| O48 | Can I trust today's opening/closing figures and reproduce last month's report? | Cutover evidence, opening stock/debts/assets, daily reconciliation and dated report snapshots showing backdated corrections/revisions. | Opening/period tools exist; operational cutover and daily-close contract incomplete: E05/E08/E14. |

## 4. Business-position view and simple financial work

### Owner landing page

Keep “Today” practical: due orders, incoming supplier commitments, staff/tasks, preparation progress and a short exception/approval queue. Offer a date picker with Today in Africa/Kampala as default and a quick link to the Money position. Let the owner open a useful summary without assembling a report manually.

The Money position has eight groups, with detail available when opened:

1. **Money available:** cash/bank/mobile-money balances by account and original currency; UGX equivalent with valuation basis; reconciliation freshness.
2. **Money customers owe:** invoiced outstanding, due/overdue, disputed and expected collections; customer deposits shown with the correct separate meaning.
3. **Money we owe:** posted bills, wages, claims and borrowing obligations by due date; unbilled purchase/export commitments separately identified.
4. **Stock and work:** loose/reserved/held/packed quantities and value, missing cost basis and stock-count exceptions.
5. **Equipment and other assets:** cost/carrying value, custodian/location and maintenance/condition; current/fixed classification under the approved reporting policy.
6. **Spending:** money out versus recognized expenses, broken down by category/order/staff/counterparty, with original evidence.
7. **Result:** sales, COGS, gross margin, identified operating/export costs and financial result for the chosen period; unpriced orders and incomplete cost sheets flagged.
8. **Next cash needs:** expected collections and approved obligations/commitments over the next 7/30 days, assumptions and forecast shortage.

Every amount needs an as-of date or period, original currency, base-currency basis, source links and status. Distinguish book balances, provider/count evidence and projections. Do not add unlike currencies, quantities or assets/expenses into a misleading grand total. Do not include the same pending commitment again once represented by a bill.

Use “Customers owe us,” “We owe suppliers,” “Customer deposits” and “Advances to account for” in daily screens. Accounting debit/credit remains available in professional ledger views, not required vocabulary for market buyers.

### Capture once; perform financial work through controlled rules

| Simple user action | Backend responsibility | Visible result/control |
| --- | --- | --- |
| Receive produce | Validate supplier/order/batch and quantities; create stock movement and eligible billing/cost tasks | Accepted stock, rejected remainder and the next finance task are linked, without falsely recording a payment. |
| Record expense or money out | Identify paid/unpaid/claim/advance/asset/transfer type; capture evidence and draft classified financial source/allocation | One record/form, outstanding approval/payment status and prevention of duplicate spend. |
| Receive money | Identify customer/deposit/invoice allocation and actual account/currency; handle approved FX and unapplied remainder | Receipt and customer balance update through controlled posting; external transfer execution is not implied. |
| Approve wages | Consume approved time/output/rates; create verified payroll inputs and labour allocations | Earnings and job costs share source records; no duplicate wage expense/payment. |
| Dispatch/invoice | Derive actual eligible carton quantities and approved costs; create recognition/COGS tasks according to policy | Physical dispatch, financial posting and unresolved exceptions are distinguishable. |
| Review/close | Match evidence and books, identify missing postings, apply period rules and retain snapshots | Explained position and report revision trail, rather than hidden reconciliation differences. |

Configure account mappings, cost treatment, required evidence and approval thresholds once with authorized financial users. The system prepares balancing, rate validation, allocations and posting tasks. Preserve mandatory independent review and distinct preparer/reviewer/poster responsibilities; automation must not silently grant self-approval. Ordinary staff should not navigate between chart-of-accounts, journal, FX and reconciliation screens for each daily transaction.

## 5. Superadmin question catalogue

The platform owner controls the service and its commercial relationship with exporters. Detailed private tenant finance requires authorized support access; operational oversight can use defined aggregate metadata.

| ID | As platform owner, I ask | Required answer/control |
| --- | --- | --- |
| S01 | Which businesses are onboarding, active, restricted, suspended or closed? | Searchable directory; separate lifecycle, billing and security states; state changes with reason. |
| S02 | Did each owner accept the invitation and complete essential setup? | Delivery/acceptance progress, safe resend/recovery and outstanding onboarding steps. |
| S03 | Can I correct an owner/contact or suspend/reinstate safely? | Authorized recovery/transfer, immediate applicable path restrictions and full audit trail. |
| S04 | Which subscription terms and features apply to each business? | Versioned plan/agreement, effective dates, entitlements, allowances and dated overrides. |
| S05 | Who needs renewal, who owes us, and what was collected? | Due/overdue platform invoices, partial payments, reminders and reconciliation; recurring value separate from collections. |
| S06 | What happens on trial expiry, upgrade, cancellation or billing dispute? | Predictable grace/restriction/credit/refund rules, logged decisions and no accidental loss of tenant records. |
| S07 | Who is using the system and nearing their allowance? | Defined active users/seats, file storage and operational counters, period/freshness and deduplicated usage. |
| S08 | Which businesses have not adopted the workflow? | Last qualifying activity, essential setup completion and agreed adoption milestones; do not mistake an unused tenant for a healthy one. |
| S09 | What does each tenant cost to serve? | Metered/configured hosting/storage/email/support allocation with missing-cost caveats and comparison to subscription receipts/value. |
| S10 | Is the service functioning and which tenants are affected by failures? | Errors/queues/slow operations, provider and print-agent health, incident scope and responsible operator. |
| S11 | Are backups recoverable and has restore been tested? | Last backup and independent restore evidence, age/status and incident escalation. |
| S12 | Are printers/installations failing at a client site? | Agent last-seen/errors, job backlog, approved printer/media setup and optional install-flow support metadata without private document contents. |
| S13 | Who is supporting a business, with what permission? | Business-visible, time-limited support grant, access scope, actions and revocation. |
| S14 | Who changed a price, entitlement, payment or administrative permission? | Platform audit log, approval reason and previous/new version; protect privileged accounts. |
| S15 | Can a software release or migration be safely introduced? | Version/build, affected tenants, rehearsals, acceptance evidence and rollback/recovery readiness. |
| S16 | Does a business need an export, archive or approved closure? | Authorized data delivery and retention/offboarding process separate from subscription cancellation. |
| S17 | Which issues need action today, who owns them and when are they due? | One platform incident/support/renewal queue with acknowledged owner and escalation. |
| S18 | Can I trust dashboard totals and limit enforcement? | Counts reconcile to source records, filters/time periods are explicit, unknown metrics remain unknown and restrictions are enforced in backend operations. |

These questions extend the existing roadmap's control centre; they do not introduce a separate dashboard for each metric. Reuse its six platform areas and per-business detail tabs.

## 6. Priority sticker and printing requirement

Printing is an early operational release gate. A packhouse must demonstrate its actual printer/media workflow before relying on carton labels, even while later finance/asset modules are being completed.

| Requirement | Minimum acceptance |
| --- | --- |
| Printer and paper/media profiles | Actual model/protocol and supported DPI, label width/height in mm including supported fractions, orientation, gap/black-mark/continuous type as applicable, offsets/margins and printer-specific calibration. Reject incompatible combinations; do not promise arbitrary dimensions on every printer. |
| Template configuration | Business/customer/product defaults; configurable text/fields, ordering, position, font size, alignment, wrapping, logo and barcode/QR options. Offer tested preset layouts plus visual editing/preview, versioning and approval. |
| Required field rules | Mark required versus optional information by use/customer. Missing weight/identity/AWB or another required value must produce a clear validation error, not silently omit a selected line. |
| Actual-size preview/test print | Preview from the same data/layout as the output; show overflow, clipping and code areas. Test long product/company names, Unicode, mixed batches, small media and each target DPI. Verify actual scanning and carton matching on the real device. |
| Bulk printing | Select packing job/order/shipment or cartons; show unique identities, quantity/copies and sequence; generate immutable per-carton output plus a batch manifest and queue to the selected workstation. Do not repeat the first carton's identity across a batch. |
| Direct work actions | Print from the packing/carton/shipment record using a default profile. Search all eligible historic generations; the latest-60 dropdown must not exclude a needed older sticker. |
| Fallback | Keep ZPL downloads; provide accurate-size PDF/browser label output where the business's printer/sheet workflow requires it. Sheet size, rows/columns, spacing and offsets must be explicit. Test print scaling; do not claim silent raw printing from any mobile browser. |
| Queue and recovery | Distinguish generated/queued/leased/transferred, failed, uncertain physical output, and verified applied. If an acknowledgement is lost, reconcile the actual label before retry. Provide controlled retry/cancel/reprint with reason, copies and history. |
| Source changes | Snapshot source values as well as template/output. Detect when carton or shipment data differs from the last generated/applied label; retain historical output but flag stale stickers and guide replacement. |
| Private traceability | Allow internal authenticated scan identities without requiring public publication. Public customer/trace pages remain a separate explicit visibility choice. Scan/label creation must not silently expose supplier or business details. |
| Applied-label verification | Verify carton identity/version when applied and before manifest/dispatch. Track replacements, spoiled stock and label consumption where material control is enabled. Code readability and physical application require actual evidence. |

Current limitations are source-confirmed, not newly reproduced printer defects: `ZebraLabelRenderer` uses a fixed vertical layout, one text line per field, trims to 100 characters, skips empty values, requires public-enabled QR pages and snapshots only generated output/template. Its existing capacity checks are useful but do not prove all text/codes physically fit or scan. The queue's “transferred” state deliberately means socket delivery, not paper output. Preserve that truthful distinction.

## 7. PWA invitation at login on desktop and mobile web

**Required experience:** show an “Install Boosted One” invitation on the web sign-in page, and offer it again after successful sign-in if appropriate. It must work for both platform and exporter users without blocking login or MFA. Show Install and Not now when installation is available; otherwise show relevant installation instructions. Use “Install” in the interface rather than suggesting a separate APK/desktop installer download.

The public/login layout needs manifest/icon metadata as well as the authenticated shell. Set a stable app identity, appropriate icons and HTTPS delivery. Launching the installed app must route through normal authentication, then to the correct platform/business workspace; installation never establishes authorization or grants offline access to private data.

| Browser situation | Expected behavior |
| --- | --- |
| Supported desktop/Android Chromium install event available | Show the app's login invitation; user selects Install; invoke the saved native prompt from that click. Do not assume the browser will supply an event immediately at every login. |
| iPhone/iPad supported installation | Show browser-appropriate Share → Add to Home Screen instructions; do not call an unavailable Chromium install API. |
| macOS Safari | Offer the supported browser Add to Dock installation instructions. |
| Other/unsupported/restricted browser or embedded view | Explain the available browser/menu route or opening in a supported external browser; leave normal web sign-in usable. |
| Already running as an installed app | Hide the install invitation. Update the UI on supported install events; use available standalone/display-mode detection and avoid claiming universal detection of an install made in a different browser. |
| User dismisses | Respect Not now/dismissal for a defined device-local interval; retain an Install/help action. Do not repeatedly cover the sign-in form or lose entered credentials. |

Native install prompting is browser-controlled and generally needs a user action; it cannot be forced uniformly on page load. Custom install prompting through `beforeinstallprompt` is Chromium-specific and not supported on iOS. Device installation needs its own acceptance checks, separate from offline stock/finance capture. Sources checked for this requirement: [MDN installation flow](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/How_to/Trigger_install_prompt), [MDN browser installation support](https://developer.mozilla.org/en-US/docs/Web/Progressive_web_apps/Guides/Making_PWAs_installable).

## 8. Additional pending requirements register

These extend, rather than replace, F01–F21 in the original audit. P1 denotes an owner-control or early operational release gate; P2 denotes required completion of the broader ERP. Current absence/limitations are source-confirmed; new end-to-end behavior still requires implementation and tests.

| ID | Pending requirement | Prior roadmap gap | Priority and acceptance |
| --- | --- | --- | --- |
| R01 | Owner answer contract and unified dated business position | Owner dashboard mentioned, but complete question/metric definitions and drill-down contract not specified | P1: O01–O48 have owners, definitions, source links and completeness; operational/financial totals reconcile. |
| R02 | Supplier/customer account workspace and supplied-on-date report | Directories/sourcing/aging mentioned, without full partner performance/history and filter requirements | P1: O07–O12/O38 answered from one partner record; registered and market vendor identities handled explicitly. |
| R03 | Transport partners, legs, custody, cost and delivery evidence | Shipment fields and future modes were mentioned, but domestic transporter control was not explicit | P1: O26/O27 show the complete transport chain with evidence, responsibilities and costs; airline text alone is insufficient. |
| R04 | Export credit terms, due dates, collection/dispute workflow | Aging was requested without its missing source due-date/credit data model | P1: dated customer/supplier terms snapshot on documents; due/overdue balances, deposits and disputes reconcile. SaaS invoice terms remain separate. |
| R05 | Current/fixed assets and liability maturity classification | Assets/loans existed in the plan, but clear owner balance classification was underspecified | P2: O40/O45 drill into register/subledgers and reconciliation, with owned/hired items distinguished. |
| R06 | Daily cash/money-out close and 7/30-day funding plan | Expenses/forecast mentioned without daily capture/count/commitment definition | P1: O37/O39/O41/O42 distinguish paid, expensed, committed, reconciled and projected money. |
| R07 | Owner capital, drawings and loans to/from owners | Opening equity exists; ongoing owner transactions were omitted as explicit daily workflows | P2: O46 supported without treating every owner transaction as sales or business expense. |
| R08 | Customer/supplier claim, recall and resolution cases | Credits/returns were covered, but case ownership, deadlines, evidence and recall exposure were not explicit | P2: O24/O30 connect goods and financial remedies without deleting history. |
| R09 | Simple financial commands with controlled automatic preparation | Heavy backend was described, but user actions still risk separate source/posting navigation | P1: everyday record/pay/receive actions create linked source/allocation/posting work; independent controls remain enforced. |
| R10 | Actionable exceptions, reminders, acknowledgement/escalation and daily summary | Today/inboxes existed, but thresholds, follow-up ownership and notification lifecycle lacked acceptance | P1: late supply, short stock, expiring documents, overdue money and failed labels have a responsible person and next action; reminders do not become repeated noise. |
| R11 | Sticker printing as an early gate with media profiles, layout/preview and batch output | Existing labels were acknowledged, but printing was not an explicit prioritized delivery stream | P1: actual paper/DPI/template/code/bulk workflow demonstrated on real target printers before packing pilot. |
| R12 | Label privacy, required/stale fields, applied verification and uncertain/reprint handling | Historical snapshots and print queue existed, without complete physical label lifecycle requirements | P1: O32/O34–O36 verified, internal QR does not require publication, and old generations remain searchable. |
| R13 | Login installation invitation across desktop/mobile web | Generic install guidance existed, but login layout and platform-specific invitation behavior were omitted | P1: login/post-login invitation, native install where available, guided fallback, installed/dismissed states, working auth/MFA. |
| R14 | Superadmin adoption, service cost, printer incidents and action ownership | Lifecycle/usage/health existed in plan, but service-owner daily questions were incomplete | P1: S01–S18 mapped to dashboard/actions, with accurate metrics, support permissions and recorded follow-up. |
| R15 | Initial business cutover and reconciliation | “Opening data” mentioned without a connected operational import/acceptance contract | P1: real opening stock/valuation, customer/supplier debts and due dates, cash, assets, loans and advances reconcile; no fabricated purchases or invoices merely to force balances into the system. |

## 9. Completion and verification contract

For each owner/platform question, record its destination, access rule, filters/date basis, source records, calculation owner, example data and acceptance result. Include no-data, partially posted and backdated-correction cases. Use one reusable report/table/filter framework rather than separate ad hoc screens.

Add the following end-to-end checks to the existing roadmap's A01–A35:

| ID | Additional acceptance scenario | Pass condition |
| --- | --- | --- |
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

Printer and PWA field tests must be recorded against actual supported devices. Current simulated HTTP tests are not substitutes. Add query/performance and tenant-isolation tests for each new view and command; stale/missing data cannot be presented as a completed control.

## 10. Source references

- E01: [WorkspaceMetrics](../backend/app/Services/WorkspaceMetrics.php), [workspace view](../backend/resources/views/workspace.blade.php).
- E02/E03: [SupplierController](../backend/app/Http/Controllers/SupplierController.php), [SupplyRequestController](../backend/app/Http/Controllers/SupplyRequestController.php), [PurchaseController](../backend/app/Http/Controllers/PurchaseController.php), [GoodsReceiptController](../backend/app/Http/Controllers/GoodsReceiptController.php), [receiving register](../backend/resources/views/receiving/index.blade.php).
- E04: [StaffOperations](../backend/app/Services/StaffOperations.php), [StaffController](../backend/app/Http/Controllers/StaffController.php), [FinancePayroll](../backend/app/Services/FinancePayroll.php).
- E05: [StockBatch](../backend/app/Models/StockBatch.php), [PackingOperations](../backend/app/Services/PackingOperations.php), [CartonController](../backend/app/Http/Controllers/CartonController.php), [StockQualityControls](../backend/app/Services/StockQualityControls.php).
- E06: [ExportShipment](../backend/app/Models/ExportShipment.php), [ExportShipmentController](../backend/app/Http/Controllers/ExportShipmentController.php), [ShipmentOperations](../backend/app/Services/ShipmentOperations.php).
- E07/E08/E09: [CommercialInvoice](../backend/app/Models/CommercialInvoice.php), [FinanceSupplierBill](../backend/app/Models/FinanceSupplierBill.php), [FinanceDocuments](../backend/app/Services/FinanceDocuments.php), [FinanceController](../backend/app/Http/Controllers/FinanceController.php), [FinanceStatements](../backend/app/Services/FinanceStatements.php), [FinanceInventoryValuation](../backend/app/Services/FinanceInventoryValuation.php), [ShipmentContribution](../backend/app/Services/ShipmentContribution.php).
- E10/E11: [LabelTemplateController](../backend/app/Http/Controllers/LabelTemplateController.php), [ZebraLabelRenderer](../backend/app/Services/ZebraLabelRenderer.php), [LabelPrintAgentController](../backend/app/Http/Controllers/LabelPrintAgentController.php), [LabelPrintQueue](../backend/app/Services/LabelPrintQueue.php), [print workstation view](../backend/resources/views/labels/printers.blade.php), [local agent and field-test instructions](zebra_agent/README.md).
- E12: [login view](../backend/resources/views/auth/login.blade.php), [public layout](../backend/resources/views/layouts/public.blade.php), [manifest](../backend/public/manifest.webmanifest).
- E13/E14: [PlatformBusinessController](../backend/app/Http/Controllers/PlatformBusinessController.php), [BoostedOneBillingController](../backend/app/Http/Controllers/BoostedOneBillingController.php), [EnforceBillingEntitlement](../backend/app/Http/Middleware/EnforceBillingEntitlement.php), [BusinessPermissions](../backend/app/Support/BusinessPermissions.php), [routes](../backend/routes/web.php).


## Phase 1 implementation follow-up — 27 October 2026

The platform-owner questions S01–S18 now have a local control-centre implementation and an explicit release boundary in the [roadmap](EXPORT_BUSINESS_ROADMAP_2026-10-27.md#phase-1-implementation--27-october-2026). Business/owner lifecycle, subscriptions and corrections, source usage/allowances, scoped support and audit are implemented and regression-tested. Infrastructure observations and cost estimates remain partly manual; production delivery/scheduling, concurrent PostgreSQL behavior, complete service-cost instrumentation and monitoring-to-incident escalation still require acceptance. These limits must remain visible when assessing S09–S12 and S15–S18.

This update does not close the exporter-owner gaps O01–O48, the Phase 0A physical printer/PWA device gate, or the operational/people/finance/asset requirements assigned to phases 2–5.


## Phase 2 implementation follow-up — 27 October 2026

The [roadmap's Phase 2 implementation entry](EXPORT_BUSINESS_ROADMAP_2026-10-27.md#phase-2-implementation--27-october-2026) now records locally implemented demand, sourcing, stock-reservation, work, transport and people controls. It gives the owner a connected order workspace, named/due work, supplier/date evidence, declared supplier-performance samples, partner follow-ups, transport/custody history and weekly staffing/attendance exceptions. R02/R03/R10 therefore have working operational components; their financial allocation, complete reminder/escalation and field acceptance portions retain the later gates.

The implementation deliberately preserves pricing-pending orders without zero-value sales, source history after closure, explicit unmeasured/missing data, independent time review and permissions after a staff role change. R04–R09/R15 and the complete financial position, assets, loans, cutover, physical printing and real-device installation requirements are not closed by this phase.

Local Phase 2 regression evidence: **294 tests / 4,045 assertions**, including 34 new operating/people cases, all passing. The physical printer/device and production-concurrency gates remain independent of these automated results.


## Phase 3 implementation follow-up — 27 October 2026

The [roadmap's Phase 3 implementation entry](EXPORT_BUSINESS_ROADMAP_2026-10-27.md#phase-3-implementation--commercial-and-daily-money-foundations--27-october-2026) records working money commands, private buyer decisions, frozen pricing FX, advances/accountability balances, receipt-specific partial bills, expenses/evidence, approved daily/hourly casual pay and order cost/position views. Owner questions about customer deposits, supplier/staff money held, dated trade debt, posted daily outflow and earned/unpaid approved wages now have connected sources. Paying or allocating an existing source clears its control balance rather than creating the cost/payment twice.

R04/R06/R09 therefore have implemented components, not full acceptance. The owner can see missing due dates, unposted records, a selected collection scenario and incomplete cost coverage. Physical cash counts/close, recurring expenses, conversion transactions, complete commitment funding, collection/dispute cases, monthly/piece/overtime and material/labour inventory costing still require Phase 3 work. R05/R07/R08/R15 and complete assets/loans, owner funding transactions, exception/cutover and physical printer/PWA acceptance are not closed by this pass. Phase 1 platform controls remain in place; no new unscoped superadmin access to private tenant money or wages was added.

Local verification: **309 tests / 4,340 assertions**, preserved existing source-table counts and a clean SQLite foreign-key check after four migrations. These results do not replace production concurrency or real exporter acceptance. Keep the roadmap in **Phase 3 — in progress**.


## Phase 4 implementation follow-up — 27 October 2026

The owner can now ask which equipment is owned/hired, who has it, its condition/maintenance due date, what is still payable and its posted carrying value; the asset register connects custody history to approved acquisition/opening, depreciation and disposal. The borrowing register answers lender, agreed limit/term, drawn principal, accrued interest, repayments, manual FX and entered instalments. Cancelled/replaced repayment plans preserve prior payments and the actual debt. The dated Money position exposes these balances and register-to-ledger differences, while current 7/30-day scenarios show entered borrowing commitments separately from expenses and actual cash.

Stock counts preserve the original observation and require independent stock and financial control before a nonzero variance changes both units and value. Whole-source location movements and full unallocated-carton repacking preserve traceability, restore order reservations and disable the old public QR. Shipment readiness now checks configured required documents, responsibility/due dates, original-file integrity, review validity and the current manifest before dispatch; historical release proof remains available. No official compliance assurance is inferred from a locally reviewed document or business waiver.

These controls extend R05/R07/R08 and parts of R15 to the precise boundaries in the [Phase 4 roadmap entry](EXPORT_BUSINESS_ROADMAP_2026-10-27.md#phase-4-implementation--exceptions-equipment-and-borrowing--27-october-2026). Full goods returns/claims/recall, partial repacking, all correction cases, financial classifications, owner equity/drawings, complete cost/cash/funding controls and cutover remain open. Broad owner control requires these as well as the existing Phase 1 superadmin controls. No additional unscoped platform access to private tenant data was introduced.

Local verification: **326 tests / 4,791 assertions**, three backed-up additive migrations, preserved original row/column values in 134 existing non-migration tables and a clean SQLite foreign-key check. Keep **Phase 4 — in progress**, with the Phase 3 backlog and Phase 0A/5 device, PostgreSQL and real exporter acceptance gates visible.
