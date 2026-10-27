<?php

use App\Http\Controllers\BoostedOneBillingController;
use App\Http\Controllers\BoostedOnePublicController;
use App\Http\Controllers\BusinessSelectionController;
use App\Http\Controllers\BusinessSettingsController;
use App\Http\Controllers\BuyerOrderPortalController;
use App\Http\Controllers\CartonController;
use App\Http\Controllers\CommercialInvoiceController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ExportClearanceController;
use App\Http\Controllers\ExportDocumentController;
use App\Http\Controllers\ExportDocumentTemplateController;
use App\Http\Controllers\ExportShipmentController;
use App\Http\Controllers\ExternalProviderController;
use App\Http\Controllers\FinanceBankController;
use App\Http\Controllers\FinanceControlController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\FinanceCreditController;
use App\Http\Controllers\FinanceFxRevaluationController;
use App\Http\Controllers\FinanceInventoryController;
use App\Http\Controllers\FinanceMonetaryRevaluationController;
use App\Http\Controllers\FinancePayrollController;
use App\Http\Controllers\FinanceReportController;
use App\Http\Controllers\FinanceTransactionReversalController;
use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\InvoiceAccountingHandoffController;
use App\Http\Controllers\LabelPrintAgentController;
use App\Http\Controllers\LabelTemplateController;
use App\Http\Controllers\LabelWorkflowController;
use App\Http\Controllers\MfaController;
use App\Http\Controllers\OperatingPolicyController;
use App\Http\Controllers\PackingJobController;
use App\Http\Controllers\PasswordRecoveryController;
use App\Http\Controllers\PlatformBusinessController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImageController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\ProductQrController;
use App\Http\Controllers\PublicQrController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\ReleaseAcceptanceController;
use App\Http\Controllers\SalesOrderController;
use App\Http\Controllers\SalesQuotationController;
use App\Http\Controllers\SalesRequestController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\ShipmentApprovalController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StockQualityController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierQuoteController;
use App\Http\Controllers\SupplierReplyController;
use App\Http\Controllers\SupplyRequestController;
use App\Http\Controllers\TeamAccessController;
use App\Http\Controllers\TeamController;
use App\Services\WorkspaceMetrics;
use App\Support\BusinessPermissions;
use Illuminate\Support\Facades\Route;

Route::get('/', [BoostedOnePublicController::class, 'home'])->name('home');
Route::get('/solutions', [BoostedOnePublicController::class, 'solutions'])->name('site.solutions');
Route::get('/solutions/{slug}', [BoostedOnePublicController::class, 'solution'])->name('site.solution');
Route::get('/features', [BoostedOnePublicController::class, 'features'])->name('site.features');
Route::get('/features/{slug}', [BoostedOnePublicController::class, 'feature'])->name('site.feature');
Route::get('/how-it-works', [BoostedOnePublicController::class, 'workflow'])->name('site.workflow');
Route::get('/pricing', [BoostedOnePublicController::class, 'pricing'])->name('site.pricing');
Route::get('/about', [BoostedOnePublicController::class, 'about'])->name('site.about');
Route::get('/contact', [BoostedOnePublicController::class, 'contact'])->name('site.contact');
Route::get('/get-started', [BoostedOnePublicController::class, 'onboarding'])->name('site.onboarding');
Route::get('/sitemap.xml', [BoostedOnePublicController::class, 'sitemap'])->name('site.sitemap');
Route::get('/privacy-policy', [BoostedOnePublicController::class, 'privacy'])->name('privacy');
Route::get('/data-retention', [BoostedOnePublicController::class, 'retention'])->name('retention');
Route::get('/terms-of-service', [BoostedOnePublicController::class, 'terms'])->name('terms');
Route::post('/onboarding/request', [BoostedOnePublicController::class, 'apply'])->middleware('throttle:4,1')->name('onboarding.apply');
// Provider callbacks have no browser session; authentication is HMAC with a
// separately provisioned per-business signing secret. Never accept arbitrary
// caller-supplied public keys as proof of bank or carrier identity.
Route::post('/integrations/webhook/{kind}/{provider}',
    [ExternalProviderController::class, 'webhook'])
    ->whereIn('kind', ['payment', 'carrier'])
    ->where('provider', '[a-z][a-z0-9_-]{2,59}')
    ->middleware('throttle:120,1')->name('integrations.webhook');

// A print agent is authenticated by a long, locally stored bearer token.
// No browser CSRF session or direct server-to-printer network connection.
Route::post('/print-agent/{agent}/next', [LabelPrintAgentController::class, 'poll'])
    ->whereNumber('agent')->middleware('throttle:60,1')->name('print-agent.next');
Route::post('/print-agent/{agent}/jobs/{job}/ack',
    [LabelPrintAgentController::class, 'ack'])->whereNumber('agent')
    ->whereNumber('job')->middleware('throttle:120,1')->name('print-agent.ack');

Route::get('/q/{token}', [PublicQrController::class, 'show'])->middleware('throttle:120,1')->name('qr.public');
Route::get('/q/{token}/photo', [PublicQrController::class, 'photo'])->middleware('throttle:120,1')->name('qr.photo');
Route::get('/buyer-order/{token}', [BuyerOrderPortalController::class, 'show'])
    ->middleware('throttle:20,1')->name('buyer-order.show');
Route::get('/supplier-offer/{token}', [SupplierReplyController::class, 'show'])->middleware('throttle:60,1')->name('supplier-offer.show');
Route::post('/supplier-offer/{token}', [SupplierReplyController::class, 'store'])->middleware('throttle:10,1')->name('supplier-offer.store');

Route::middleware('guest')->group(function () {
    Route::get('/forgot-password', [PasswordRecoveryController::class, 'requestForm'])->name('password.request');
    Route::post('/forgot-password', [PasswordRecoveryController::class, 'send'])->middleware('throttle:3,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordRecoveryController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password', [PasswordRecoveryController::class, 'reset'])->middleware('throttle:5,1')->name('password.update');
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])
        ->middleware('throttle:12,1')->name('login.store');
});
Route::get('/invite/{token}', [InvitationController::class, 'show'])->name('invitations.show');
Route::post('/invite/{token}', [InvitationController::class, 'accept'])
    ->middleware('throttle:10,1')->name('invitations.accept');

Route::middleware('auth')->group(function () {
    // Privileged staff must enrol and verify a second factor before any
    // production workspace, financial or platform business action.
    Route::get('/security/mfa/setup', [MfaController::class, 'setup'])
        ->name('security.mfa.setup');
    Route::post('/security/mfa/setup', [MfaController::class, 'enrol'])
        ->middleware('throttle:6,1')->name('security.mfa.enrol');
    Route::get('/security/mfa/challenge', [MfaController::class, 'challenge'])
        ->name('security.mfa.challenge');
    Route::post('/security/mfa/challenge', [MfaController::class, 'verify'])
        ->middleware('throttle:8,1')->name('security.mfa.verify');
    Route::get('/security/mfa/recovery-codes', [MfaController::class, 'recovery'])
        ->name('security.mfa.recovery');
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
    Route::get('/choose-business', [BusinessSelectionController::class, 'index'])->name('businesses.choose');
    Route::post('/choose-business', [BusinessSelectionController::class, 'store'])->name('businesses.activate');

    Route::prefix('platform')->name('platform.')->middleware('platform')->group(function () {
        Route::get('/businesses', [PlatformBusinessController::class, 'index'])->name('businesses.index');
        Route::post('/businesses', [PlatformBusinessController::class, 'store'])->name('businesses.store');
        Route::get('/billing', [BoostedOneBillingController::class, 'index'])->name('billing.index');
        Route::post('/billing/requests/{application}', [BoostedOneBillingController::class, 'requestStatus'])->name('billing.request');
        Route::post('/billing/requests/{application}/send-packages', [BoostedOneBillingController::class, 'sendPackages'])->name('billing.request.packages');
        Route::post('/billing/plans', [BoostedOneBillingController::class, 'plan'])->name('billing.plan');
        Route::post('/billing/methods', [BoostedOneBillingController::class, 'method'])->name('billing.method');
        Route::post('/billing/subscriptions', [BoostedOneBillingController::class, 'subscribe'])->name('billing.subscribe');
        Route::post('/billing/subscriptions/{subscription}/invoices', [BoostedOneBillingController::class, 'invoice'])->name('billing.invoice.issue');
        Route::get('/billing/invoices/{invoice}', [BoostedOneBillingController::class, 'show'])->name('billing.invoice.show');
        Route::post('/billing/invoices/{invoice}/email', [BoostedOneBillingController::class, 'email'])->name('billing.invoice.email');
        Route::post('/billing/invoices/{invoice}/payments', [BoostedOneBillingController::class, 'pay'])->name('billing.invoice.pay');
    });

    Route::middleware(['business', 'billing'])->group(function () {
        Route::get('/my-subscription', [BoostedOneBillingController::class, 'customer'])->name('business.billing');
        Route::get('/my-subscription/invoices/{invoice}', [BoostedOneBillingController::class, 'customerInvoice'])->name('business.billing.invoice');
        // Outstanding foreign invoice/bill valuation; never a bank movement.
        Route::get('/finance/monetary-revaluations', [FinanceMonetaryRevaluationController::class, 'index'])
            ->name('finance.monetary-revaluations.index');
        Route::post('/finance/monetary-revaluations', [FinanceMonetaryRevaluationController::class, 'prepare'])
            ->name('finance.monetary-revaluations.prepare');
        // Foreign-currency non-cash asset revaluation and financial approvals.
        Route::get('/finance/fx-revaluations', [FinanceFxRevaluationController::class, 'index'])
            ->name('finance.fx-revaluations.index');
        Route::post('/finance/fx-revaluations', [FinanceFxRevaluationController::class, 'prepare'])
            ->name('finance.fx-revaluations.prepare');
        // Audit-preserving corrections, foreign bank openings and book-balance proof.
        Route::get('/finance/transaction-corrections', [FinanceTransactionReversalController::class, 'index'])
            ->name('finance.transaction-reversals.index');
        Route::post('/finance/transaction-corrections/{type}/{id}',
            [FinanceTransactionReversalController::class, 'prepare'])
            ->whereIn('type', ['payment', 'shipment_cost', 'payroll_settlement'])
            ->whereNumber('id')->name('finance.transaction-reversals.prepare');
        Route::post('/finance/controls/banks/{bank}/identifier',
            [FinanceControlController::class, 'bankAccountIdentifier'])
            ->whereNumber('bank')->name('finance.controls.banks.identifier');
        Route::post('/finance/controls/foreign-opening', [FinanceControlController::class, 'foreignOpening'])
            ->name('finance.controls.foreign-opening.store');
        Route::post('/finance/bank/{statement}/book-proof',
            [FinanceBankController::class, 'proposeLedgerProof'])->whereNumber('statement')
            ->name('finance.bank.proof.submit');
        Route::post('/finance/bank/book-proof/{check}/{decision}',
            [FinanceBankController::class, 'reviewLedgerProof'])->whereNumber('check')
            ->whereIn('decision', ['approve', 'reject'])->name('finance.bank.proof.review');
        // Opening balances, company banks, period locks, price concessions and payroll.
        Route::get('/finance/controls', [FinanceControlController::class, 'index'])->name('finance.controls');
        Route::post('/finance/controls/banks', [FinanceControlController::class, 'bank'])
            ->name('finance.controls.banks.store');
        Route::post('/finance/controls/opening', [FinanceControlController::class, 'opening'])
            ->name('finance.controls.opening.store');
        Route::post('/finance/controls/periods/{period}/request-reopening',
            [FinanceControlController::class, 'requestPeriodReopening'])
            ->whereNumber('period')->name('finance.controls.periods.reopening.request');
        Route::post('/finance/controls/reopening/{case}/{decision}',
            [FinanceControlController::class, 'reviewPeriodReopening'])
            ->whereNumber('case')->whereIn('decision', ['approve', 'reject'])
            ->name('finance.controls.periods.reopening.review');
        Route::post('/finance/controls/periods', [FinanceControlController::class, 'period'])
            ->name('finance.controls.periods.store');
        Route::post('/finance/controls/periods/{period}/submit', [FinanceControlController::class, 'submitPeriod'])
            ->whereNumber('period')->name('finance.controls.periods.submit');
        Route::post('/finance/controls/periods/{period}/{action}', [FinanceControlController::class, 'reviewPeriod'])
            ->whereNumber('period')->whereIn('action', ['approve', 'reject'])
            ->name('finance.controls.periods.review');
        Route::post('/finance/controls/adjustments/{kind}/{id}', [FinanceControlController::class, 'adjustment'])
            ->whereIn('kind', ['customer', 'supplier'])->whereNumber('id')
            ->name('finance.controls.adjustments.store');
        Route::get('/finance/payroll', [FinancePayrollController::class, 'index'])->name('finance.payroll.index');
        Route::post('/finance/payroll', [FinancePayrollController::class, 'prepare'])->name('finance.payroll.prepare');
        Route::get('/finance/payroll/{run}', [FinancePayrollController::class, 'show'])
            ->whereNumber('run')->name('finance.payroll.show');
        Route::post('/finance/payroll/{run}/settlements', [FinancePayrollController::class, 'settlement'])
            ->whereNumber('run')->name('finance.payroll.settlements.store');
        Route::post('/finance/payroll/{run}/correction', [FinancePayrollController::class, 'correction'])
            ->whereNumber('run')->name('finance.payroll.correction.store');
        Route::post('/finance/payroll/{run}/correction/withdraw', [FinancePayrollController::class, 'withdrawCorrection'])
            ->whereNumber('run')->name('finance.payroll.correction.withdraw');

        // Manual bank statements, source inventory relief and controlled full credit notes.
        Route::get('/finance/bank', [FinanceBankController::class, 'index'])->name('finance.bank.index');
        Route::post('/finance/bank/import', [FinanceBankController::class, 'import'])->name('finance.bank.import');
        Route::get('/finance/bank/{statement}', [FinanceBankController::class, 'show'])
            ->whereNumber('statement')->name('finance.bank.show');
        Route::post('/finance/bank/{statement}/lines/{line}/match', [FinanceBankController::class, 'propose'])
            ->whereNumber('statement')->whereNumber('line')->name('finance.bank.match');
        Route::post('/finance/bank/matches/{match}/confirm', [FinanceBankController::class, 'confirm'])
            ->whereNumber('match')->name('finance.bank.confirm');
        Route::post('/finance/bank/matches/{match}/reject', [FinanceBankController::class, 'reject'])
            ->whereNumber('match')->name('finance.bank.reject');
        Route::post('/finance/bank/{statement}/authenticate',
            [FinanceBankController::class, 'authenticateEvidence'])
            ->whereNumber('statement')->name('finance.bank.authenticate');
        Route::post('/finance/bank/{statement}/complete', [FinanceBankController::class, 'complete'])
            ->whereNumber('statement')->name('finance.bank.complete');

        Route::get('/finance/inventory', [FinanceInventoryController::class, 'index'])->name('finance.inventory.index');
        Route::post('/finance/inventory/{kind}/{id}', [FinanceInventoryController::class, 'prepare'])
            ->whereNumber('id')->name('finance.inventory.prepare');
        Route::get('/finance/inventory/valuation/{id}', [FinanceInventoryController::class, 'show'])
            ->whereNumber('id')->name('finance.inventory.show');

        Route::get('/finance/credits', [FinanceCreditController::class, 'index'])->name('finance.credits.index');
        Route::post('/finance/credits/{invoice}', [FinanceCreditController::class, 'prepare'])
            ->whereNumber('invoice')->name('finance.credits.prepare');
        Route::get('/finance/credits/notes/{note}', [FinanceCreditController::class, 'show'])
            ->whereNumber('note')->name('finance.credits.show');
        Route::get('/finance/credits/notes/{note}/print', [FinanceCreditController::class, 'print'])
            ->whereNumber('note')->name('finance.credits.print');
        Route::get('/finance/credits/notes/{note}/pdf', [FinanceCreditController::class, 'pdf'])
            ->whereNumber('note')->name('finance.credits.pdf');

        Route::get('/finance/reports', [FinanceReportController::class, 'index'])->name('finance.reports.index');
        Route::get('/finance/reports/account/{account}', [FinanceReportController::class, 'ledger'])
            ->whereNumber('account')->name('finance.reports.ledger');

        // Finance requests, independent approval and immutable balanced postings.
        Route::get('/labels/printers', [LabelPrintAgentController::class, 'index'])
            ->name('labels.printers');
        Route::post('/labels/printers', [LabelPrintAgentController::class, 'create'])
            ->name('labels.printers.create');
        Route::post('/labels/printers/{agent}/revoke',
            [LabelPrintAgentController::class, 'revoke'])->whereNumber('agent')
            ->name('labels.printers.revoke');
        Route::post('/labels/printers/jobs', [LabelPrintAgentController::class, 'submit'])
            ->name('labels.printers.jobs');

        Route::post('/shipments/{shipment}/clearance', [ExportClearanceController::class, 'submit'])
            ->whereNumber('shipment')->name('shipments.clearance.submit');
        Route::post('/shipments/{shipment}/clearance/{record}/{action}',
            [ExportClearanceController::class, 'review'])
            ->whereNumber('shipment')->whereNumber('record')
            ->whereIn('action', ['accept', 'reject'])->name('shipments.clearance.review');

        Route::get('/release/acceptance', [ReleaseAcceptanceController::class, 'index'])
            ->name('release.acceptance.index');
        Route::post('/release/acceptance', [ReleaseAcceptanceController::class, 'begin'])
            ->middleware('throttle:6,1')->name('release.acceptance.begin');
        Route::get('/release/acceptance/{run}', [ReleaseAcceptanceController::class, 'show'])
            ->whereNumber('run')->name('release.acceptance.show');
        Route::post('/release/acceptance/{run}/{code}/submit',
            [ReleaseAcceptanceController::class, 'submit'])->whereNumber('run')
            ->name('release.acceptance.submit');
        Route::post('/release/acceptance/{run}/{code}/review/{action}',
            [ReleaseAcceptanceController::class, 'review'])->whereNumber('run')
            ->whereIn('action', ['approve', 'reject'])->name('release.acceptance.review');
        Route::post('/release/acceptance/{run}/seal', [ReleaseAcceptanceController::class, 'seal'])
            ->whereNumber('run')->name('release.acceptance.seal');

        Route::get('/integrations', [ExternalProviderController::class, 'index'])
            ->name('integrations.index');
        Route::post('/integrations/events/{event}/{action}',
            [ExternalProviderController::class, 'review'])
            ->whereNumber('event')->whereIn('action', ['accept', 'reject'])
            ->name('integrations.events.review');

        Route::get('/finance', [FinanceController::class, 'dashboard'])->name('finance.index');
        Route::post('/finance/setup', [FinanceController::class, 'setup'])->name('finance.setup');
        Route::get('/finance/rates', [FinanceController::class, 'rates'])->name('finance.rates');
        Route::post('/finance/rates', [FinanceController::class, 'proposeRate'])->name('finance.rates.propose');
        Route::post('/finance/rates/{rate}/approve', [FinanceController::class, 'approveRate'])
            ->whereNumber('rate')->name('finance.rates.approve');
        Route::get('/finance/documents', [FinanceController::class, 'documents'])->name('finance.documents');
        Route::post('/finance/bills/purchase/{purchase}', [FinanceController::class, 'bill'])
            ->whereNumber('purchase')->name('finance.bills.store');
        Route::post('/finance/payments', [FinanceController::class, 'payment'])->name('finance.payments.store');
        Route::post('/finance/shipment-costs', [FinanceController::class, 'cost'])->name('finance.costs.store');
        Route::get('/finance/reviews', [FinanceController::class, 'reviews'])->name('finance.reviews');
        Route::post('/finance/reviews/{type}/{source}', [FinanceController::class, 'requestPosting'])
            ->whereNumber('source')->name('finance.reviews.request');
        Route::post('/finance/reviews/{review}/approve', [FinanceController::class, 'approvePosting'])
            ->whereNumber('review')->name('finance.reviews.approve');
        Route::post('/finance/reviews/{review}/reject', [FinanceController::class, 'rejectPosting'])
            ->whereNumber('review')->name('finance.reviews.reject');
        Route::post('/finance/reviews/{review}/post', [FinanceController::class, 'post'])
            ->whereNumber('review')->name('finance.reviews.post');
        Route::get('/finance/shipments/{shipment}/contribution', [FinanceController::class, 'contribution'])
            ->whereNumber('shipment')->name('finance.contribution');
        Route::get('/finance/journals/{journal}', [FinanceController::class, 'journal'])
            ->whereNumber('journal')->name('finance.journal');
        Route::get('/sales-requests', [SalesRequestController::class, 'index'])->name('sales-requests.index');
        Route::get('/sales-requests/new', [SalesRequestController::class, 'create'])->name('sales-requests.create');
        Route::post('/sales-requests', [SalesRequestController::class, 'store'])->name('sales-requests.store');
        Route::get('/sales-requests/{record}', [SalesRequestController::class, 'show'])
            ->whereNumber('record')->name('sales-requests.show');
        Route::post('/sales-requests/{record}/quotation', [SalesRequestController::class, 'draft'])
            ->whereNumber('record')->name('sales-requests.draft');
        Route::get('/sales-quotes/{quote}', [SalesQuotationController::class, 'show'])
            ->whereNumber('quote')->name('sales-quotes.show');
        Route::put('/sales-quotes/{quote}', [SalesQuotationController::class, 'update'])
            ->whereNumber('quote')->name('sales-quotes.update');
        Route::post('/sales-quotes/{quote}/issue', [SalesQuotationController::class, 'issue'])
            ->whereNumber('quote')->name('sales-quotes.issue');
        Route::post('/sales-quotes/{quote}/confirm', [SalesQuotationController::class, 'confirm'])
            ->whereNumber('quote')->name('sales-quotes.confirm');
        Route::get('/sales-quotes/{quote}/print', [SalesQuotationController::class, 'print'])
            ->whereNumber('quote')->name('sales-quotes.print');
        Route::get('/sales-quotes/{quote}/pdf', [SalesQuotationController::class, 'pdf'])
            ->whereNumber('quote')->name('sales-quotes.pdf');
        Route::get('/sales-orders', [SalesOrderController::class, 'index'])->name('sales-orders.index');
        Route::post('/sales-orders/{order}/buyer-link', [BuyerOrderPortalController::class, 'share'])
            ->whereNumber('order')->middleware('throttle:5,1')
            ->name('sales-orders.buyer-link.share');
        Route::post('/sales-orders/{order}/buyer-link/{link}/revoke',
            [BuyerOrderPortalController::class, 'revoke'])
            ->whereNumber('order')->whereNumber('link')
            ->name('sales-orders.buyer-link.revoke');
        Route::get('/sales-orders/{order}', [SalesOrderController::class, 'show'])
            ->whereNumber('order')->name('sales-orders.show');
        Route::post('/sales-orders/{order}/shipments/{shipment}', [SalesOrderController::class, 'link'])
            ->whereNumber('order')->whereNumber('shipment')->name('sales-orders.shipments.link');
        Route::get('/invoice-accounting-handoff', [InvoiceAccountingHandoffController::class, 'index'])->name('invoice-accounting-handoff.index');
        Route::get('/commercial-invoices', [CommercialInvoiceController::class, 'index'])
            ->name('commercial-invoices.index');
        Route::get('/commercial-invoices/{invoice}', [CommercialInvoiceController::class, 'show'])
            ->whereNumber('invoice')->name('commercial-invoices.show');
        Route::post('/shipments/{shipment}/commercial-invoice', [CommercialInvoiceController::class, 'issue'])
            ->whereNumber('shipment')->name('commercial-invoices.issue');
        Route::get('/commercial-invoices/{invoice}/print', [CommercialInvoiceController::class, 'print'])
            ->whereNumber('invoice')->name('commercial-invoices.print');
        Route::get('/commercial-invoices/{invoice}/pdf', [CommercialInvoiceController::class, 'pdf'])
            ->whereNumber('invoice')->name('commercial-invoices.pdf');

        Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->whereNumber('customer')->name('customers.edit');
        Route::put('/customers/{customer}', [CustomerController::class, 'update'])->whereNumber('customer')->name('customers.update');

        Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::get('/suppliers/{supplier}/edit', [SupplierController::class, 'edit'])->whereNumber('supplier')->name('suppliers.edit');
        Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier')->name('suppliers.update');

        Route::get('/supply-requests', [SupplyRequestController::class, 'index'])->name('supply-requests.index');
        Route::post('/supply-requests', [SupplyRequestController::class, 'store'])->name('supply-requests.store');
        Route::get('/supply-requests/{rfq}', [SupplyRequestController::class, 'show'])->whereNumber('rfq')->name('supply-requests.show');
        Route::post('/supply-requests/{rfq}/issue', [SupplyRequestController::class, 'issue'])->whereNumber('rfq')->name('supply-requests.issue');
        Route::post('/supply-requests/{rfq}/quotes', [SupplierQuoteController::class, 'store'])->whereNumber('rfq')->name('supply-requests.quotes.store');
        Route::post('/supply-requests/{rfq}/quotes/{quote}/award', [SupplyRequestController::class, 'award'])->whereNumber('rfq')->whereNumber('quote')->name('supply-requests.award');

        Route::get('/purchases', [PurchaseController::class, 'index'])->name('purchases.index');
        Route::get('/purchases/new', [PurchaseController::class, 'create'])->name('purchases.create');
        Route::post('/purchases', [PurchaseController::class, 'store'])->name('purchases.store');
        Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])->whereNumber('purchase')->name('purchases.show');

        Route::get('/receiving', [GoodsReceiptController::class, 'index'])->name('receiving.index');
        Route::get('/receiving/purchase/{purchase}', [GoodsReceiptController::class, 'create'])->whereNumber('purchase')->name('receiving.create');
        Route::post('/receiving/purchase/{purchase}', [GoodsReceiptController::class, 'store'])->whereNumber('purchase')->name('receiving.store');
        Route::get('/stock', [GoodsReceiptController::class, 'stock'])->name('stock.index');
        Route::post('/stock/{batch}/quality/{action}', [StockQualityController::class, 'change'])
            ->whereNumber('batch')->whereIn('action', ['hold', 'release'])
            ->name('stock.quality.change');
        Route::put('/stock/{batch}/qr', [CartonController::class, 'batchVisibility'])
            ->whereNumber('batch')->name('stock.qr.visibility');

        Route::get('/packing', [PackingJobController::class, 'index'])->name('packing.index');
        Route::post('/packing', [PackingJobController::class, 'store'])->name('packing.store');
        Route::get('/packing/{job}', [PackingJobController::class, 'show'])
            ->whereNumber('job')->name('packing.show');
        Route::post('/packing/{job}/cartons', [PackingJobController::class, 'pack'])
            ->whereNumber('job')->name('packing.pack');
        Route::post('/packing/{job}/sorting-loss', [PackingJobController::class, 'loss'])
            ->whereNumber('job')->name('packing.loss');
        Route::post('/packing/{job}/complete', [PackingJobController::class, 'complete'])
            ->whereNumber('job')->name('packing.complete');

        Route::get('/cartons', [CartonController::class, 'index'])->name('cartons.index');
        Route::get('/cartons/{carton}', [CartonController::class, 'show'])
            ->whereNumber('carton')->name('cartons.show');
        Route::put('/cartons/{carton}/qr', [CartonController::class, 'visibility'])
            ->whereNumber('carton')->name('cartons.qr.visibility');

        Route::get('/labels', [LabelTemplateController::class, 'index'])->name('labels.index');
        Route::get('/labels/media', [LabelWorkflowController::class, 'profiles'])->name('labels.profiles');
        Route::get('/labels/print-helper', [LabelWorkflowController::class, 'helper'])->name('labels.agent.download');
        Route::post('/labels/media', [LabelWorkflowController::class, 'profile'])->name('labels.profiles.store');
        Route::post('/labels/media/{profile}/test', [LabelWorkflowController::class, 'testProfile'])->whereNumber('profile')->name('labels.profiles.test');
        Route::put('/labels/dispatch-policy', [LabelWorkflowController::class, 'policy'])->name('labels.policy');
        Route::get('/labels/print', [LabelWorkflowController::class, 'index'])->name('labels.print');
        Route::post('/labels/batches', [LabelWorkflowController::class, 'generate'])->name('labels.batches.store');
        Route::get('/labels/batches/{batch}', [LabelWorkflowController::class, 'show'])->whereNumber('batch')->name('labels.batches.show');
        Route::post('/labels/batches/{batch}/queue', [LabelWorkflowController::class, 'queue'])->whereNumber('batch')->name('labels.batches.queue');
        Route::post('/labels/batches/{batch}/output', [LabelWorkflowController::class, 'output'])->whereNumber('batch')->name('labels.batches.output');
        Route::get('/labels/artwork/{generation}', [LabelWorkflowController::class, 'artwork'])->whereNumber('generation')->name('labels.artwork');
        Route::get('/labels/scan/{token}', [LabelWorkflowController::class, 'scan'])->name('labels.scan');
        Route::get('/labels/trace/{token}', [LabelWorkflowController::class, 'trace'])->name('labels.trace');
        Route::post('/labels/verify/{generation}', [LabelWorkflowController::class, 'verify'])->whereNumber('generation')->name('labels.verify');
        Route::post('/labels/jobs/{job}/recover', [LabelWorkflowController::class, 'recover'])->whereNumber('job')->name('labels.jobs.recover');
        Route::post('/labels', [LabelTemplateController::class, 'store'])->name('labels.store');
        Route::get('/labels/{template}/edit', [LabelTemplateController::class, 'edit'])
            ->whereNumber('template')->name('labels.edit');
        Route::put('/labels/{template}', [LabelTemplateController::class, 'update'])
            ->whereNumber('template')->name('labels.update');
        Route::post('/cartons/{carton}/labels/{template}/generate', [LabelTemplateController::class, 'generate'])
            ->whereNumber('carton')->whereNumber('template')->name('labels.generate');
        Route::get('/label-history', [LabelTemplateController::class, 'history'])->name('labels.history');
        Route::post('/label-history/{generation}/download', [LabelTemplateController::class, 'downloadHistoric'])
            ->whereNumber('generation')->name('labels.history.download');
        // Shipment preparation, immutable document issuance and controlled dispatch.
        Route::get('/shipments', [ExportShipmentController::class, 'index'])->name('shipments.index');
        Route::get('/shipments/new', [ExportShipmentController::class, 'create'])->name('shipments.create');
        Route::post('/shipments', [ExportShipmentController::class, 'store'])->name('shipments.store');
        Route::get('/shipments/{shipment}', [ExportShipmentController::class, 'show'])
            ->whereNumber('shipment')->name('shipments.show');
        Route::get('/shipments/{shipment}/edit', [ExportShipmentController::class, 'edit'])
            ->whereNumber('shipment')->name('shipments.edit');
        Route::put('/shipments/{shipment}', [ExportShipmentController::class, 'update'])
            ->whereNumber('shipment')->name('shipments.update');
        Route::post('/shipments/{shipment}/cartons', [ExportShipmentController::class, 'allocate'])
            ->whereNumber('shipment')->name('shipments.allocate');
        Route::delete('/shipments/{shipment}/cartons/{carton}', [ExportShipmentController::class, 'release'])
            ->whereNumber('shipment')->whereNumber('carton')->name('shipments.release');
        Route::post('/shipments/{shipment}/ready', [ExportShipmentController::class, 'ready'])
            ->whereNumber('shipment')->name('shipments.ready');
        Route::post('/shipments/{shipment}/reopen', [ExportShipmentController::class, 'reopen'])
            ->whereNumber('shipment')->name('shipments.reopen');
        Route::post('/shipments/{shipment}/dispatch-review',
            [ShipmentApprovalController::class, 'submit'])->whereNumber('shipment')
            ->name('shipments.approval.submit');
        Route::post('/shipments/{shipment}/dispatch-review/{decision}',
            [ShipmentApprovalController::class, 'review'])->whereNumber('shipment')
            ->whereIn('decision', ['approve', 'reject'])->name('shipments.approval.review');
        Route::post('/shipments/{shipment}/dispatch', [ExportShipmentController::class, 'dispatch'])
            ->whereNumber('shipment')->name('shipments.dispatch');
        Route::post('/shipments/{shipment}/arrival', [ExportShipmentController::class, 'arrive'])
            ->whereNumber('shipment')->name('shipments.arrive');
        Route::post('/shipments/{shipment}/cancel', [ExportShipmentController::class, 'cancel'])
            ->whereNumber('shipment')->name('shipments.cancel');

        Route::get('/export-document-templates', [ExportDocumentTemplateController::class, 'index'])
            ->name('document-templates.index');
        Route::post('/export-document-templates', [ExportDocumentTemplateController::class, 'store'])
            ->name('document-templates.store');
        Route::get('/export-document-templates/{template}/edit', [ExportDocumentTemplateController::class, 'edit'])
            ->whereNumber('template')->name('document-templates.edit');
        Route::put('/export-document-templates/{template}', [ExportDocumentTemplateController::class, 'update'])
            ->whereNumber('template')->name('document-templates.update');

        Route::get('/export-documents', [ExportDocumentController::class, 'index'])->name('export-documents.index');
        Route::post('/shipments/{shipment}/documents/{template}', [ExportDocumentController::class, 'issue'])
            ->whereNumber('shipment')->whereNumber('template')->name('export-documents.issue');
        Route::get('/export-documents/{document}/print', [ExportDocumentController::class, 'html'])
            ->whereNumber('document')->name('export-documents.print');
        Route::get('/export-documents/{document}/pdf', [ExportDocumentController::class, 'pdf'])
            ->whereNumber('document')->name('export-documents.pdf');

        Route::get('/workspace/{section?}', function (?string $section = 'overview') {
            $sections = config('expo.sections');
            abort_unless(isset($sections[$section]), 404);
            abort_unless(app(BusinessPermissions::class)->allows(
                request()->attributes->get('membership'), $sections[$section][2],
            ), 403);

            $metrics = $section === 'overview'
                ? app(WorkspaceMetrics::class)
                    ->overview(request()->attributes->get('membership'))
                : [];

            return view('workspace', compact('section', 'sections', 'metrics'));
        })->name('workspace');

        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::get('/products/import', [ProductImportController::class, 'index'])->name('products.import');
        Route::post('/products/import/preview', [ProductImportController::class, 'preview'])->name('products.import.preview');
        Route::post('/products/import/{import}/apply', [ProductImportController::class, 'apply'])->name('products.import.apply');
        Route::get('/products/template', [ProductImportController::class, 'template'])->name('products.template');
        Route::get('/products/export', [ProductImportController::class, 'export'])->name('products.export');
        Route::post('/products/{product}/images', [ProductImageController::class, 'store'])->whereNumber('product')->name('products.images.store');
        Route::get('/products/{product}/images/{image}/{size?}', [ProductImageController::class, 'show'])
            ->whereNumber('product')->whereNumber('image')->name('products.images.show');
        Route::delete('/products/{product}/images/{image}', [ProductImageController::class, 'destroy'])
            ->whereNumber('product')->whereNumber('image')->name('products.images.destroy');
        Route::put('/products/{product}/qr', [ProductQrController::class, 'update'])->whereNumber('product')->name('products.qr.update');
        Route::get('/products/{product}/qr.png', [ProductQrController::class, 'png'])->whereNumber('product')->name('products.qr.png');
        Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->whereNumber('product')->name('products.edit');
        Route::put('/products/{product}', [ProductController::class, 'update'])->whereNumber('product')->name('products.update');

        Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
        Route::post('/staff/shifts', [StaffController::class, 'schedule'])->name('staff.schedule');
        Route::post('/staff/shifts/{shift}/cancel', [StaffController::class, 'cancel'])
            ->whereNumber('shift')->name('staff.cancel');
        Route::post('/staff/clock-in', [StaffController::class, 'clockIn'])
            ->middleware('throttle:10,1')->name('staff.clock-in');
        Route::post('/staff/attendance/{attendance}/clock-out', [StaffController::class, 'clockOut'])
            ->whereNumber('attendance')->middleware('throttle:10,1')->name('staff.clock-out');

        Route::get('/team', [TeamController::class, 'index'])->name('team.index');
        Route::put('/team/members/{member}', [TeamAccessController::class, 'update'])
            ->whereNumber('member')->name('team.members.update');
        Route::post('/team/invitations', [TeamController::class, 'invite'])->middleware('throttle:10,1')->name('team.invite');

        Route::post('/business-settings/policies', [OperatingPolicyController::class, 'update'])
            ->name('business.policies.update');

        Route::get('/business-settings', [BusinessSettingsController::class, 'index'])->name('business.settings');
        Route::put('/business-settings/currencies', [BusinessSettingsController::class, 'currencies'])
            ->name('business.currencies.update');
        Route::put('/business-settings', [BusinessSettingsController::class, 'update'])->name('business.settings.update');
    });
});
