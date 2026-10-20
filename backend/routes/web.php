<?php

use App\Http\Controllers\BusinessSelectionController;
use App\Http\Controllers\BusinessSettingsController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\PlatformBusinessController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\ProductImageController;
use App\Http\Controllers\ProductQrController;
use App\Http\Controllers\PublicQrController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplyRequestController;
use App\Http\Controllers\SupplierReplyController;
use App\Http\Controllers\SupplierQuoteController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\GoodsReceiptController;
use App\Http\Controllers\PackingJobController;
use App\Http\Controllers\CartonController;
use App\Http\Controllers\LabelTemplateController;
use App\Http\Controllers\ExportShipmentController;
use App\Http\Controllers\ExportDocumentController;
use App\Http\Controllers\ExportDocumentTemplateController;
use App\Support\BusinessPermissions;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('workspace'));
Route::get('/q/{token}', [PublicQrController::class, 'show'])->middleware('throttle:120,1')->name('qr.public');
Route::get('/q/{token}/photo', [PublicQrController::class, 'photo'])->middleware('throttle:120,1')->name('qr.photo');
Route::get('/supplier-offer/{token}', [SupplierReplyController::class, 'show'])->middleware('throttle:60,1')->name('supplier-offer.show');
Route::post('/supplier-offer/{token}', [SupplierReplyController::class, 'store'])->middleware('throttle:10,1')->name('supplier-offer.store');

Route::middleware('guest')->group(function () {
    Route::get('/login', [SessionController::class, 'create'])->name('login');
    Route::post('/login', [SessionController::class, 'store'])->name('login.store');
});
Route::get('/invite/{token}', [InvitationController::class, 'show'])->name('invitations.show');
Route::post('/invite/{token}', [InvitationController::class, 'accept'])
    ->middleware('throttle:10,1')->name('invitations.accept');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');
    Route::get('/choose-business', [BusinessSelectionController::class, 'index'])->name('businesses.choose');
    Route::post('/choose-business', [BusinessSelectionController::class, 'store'])->name('businesses.activate');

    Route::prefix('platform')->name('platform.')->middleware('platform')->group(function () {
        Route::get('/businesses', [PlatformBusinessController::class, 'index'])->name('businesses.index');
        Route::post('/businesses', [PlatformBusinessController::class, 'store'])->name('businesses.store');
    });

    Route::middleware('business')->group(function () {
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
        Route::post('/labels', [LabelTemplateController::class, 'store'])->name('labels.store');
        Route::get('/labels/{template}/edit', [LabelTemplateController::class, 'edit'])
            ->whereNumber('template')->name('labels.edit');
        Route::put('/labels/{template}', [LabelTemplateController::class, 'update'])
            ->whereNumber('template')->name('labels.update');
        Route::post('/cartons/{carton}/labels/{template}/generate', [LabelTemplateController::class,'generate'])
            ->whereNumber('carton')->whereNumber('template')->name('labels.generate');
        Route::get('/label-history', [LabelTemplateController::class,'history'])->name('labels.history');
        Route::post('/label-history/{generation}/download', [LabelTemplateController::class,'downloadHistoric'])
            ->whereNumber('generation')->name('labels.history.download');
        // Shipment preparation, immutable document issuance and controlled dispatch.
        Route::get('/shipments', [ExportShipmentController::class,'index'])->name('shipments.index');
        Route::get('/shipments/new', [ExportShipmentController::class,'create'])->name('shipments.create');
        Route::post('/shipments', [ExportShipmentController::class,'store'])->name('shipments.store');
        Route::get('/shipments/{shipment}', [ExportShipmentController::class,'show'])
            ->whereNumber('shipment')->name('shipments.show');
        Route::get('/shipments/{shipment}/edit', [ExportShipmentController::class,'edit'])
            ->whereNumber('shipment')->name('shipments.edit');
        Route::put('/shipments/{shipment}', [ExportShipmentController::class,'update'])
            ->whereNumber('shipment')->name('shipments.update');
        Route::post('/shipments/{shipment}/cartons', [ExportShipmentController::class,'allocate'])
            ->whereNumber('shipment')->name('shipments.allocate');
        Route::delete('/shipments/{shipment}/cartons/{carton}', [ExportShipmentController::class,'release'])
            ->whereNumber('shipment')->whereNumber('carton')->name('shipments.release');
        Route::post('/shipments/{shipment}/ready', [ExportShipmentController::class,'ready'])
            ->whereNumber('shipment')->name('shipments.ready');
        Route::post('/shipments/{shipment}/reopen', [ExportShipmentController::class,'reopen'])
            ->whereNumber('shipment')->name('shipments.reopen');
        Route::post('/shipments/{shipment}/dispatch', [ExportShipmentController::class,'dispatch'])
            ->whereNumber('shipment')->name('shipments.dispatch');
        Route::post('/shipments/{shipment}/arrival', [ExportShipmentController::class,'arrive'])
            ->whereNumber('shipment')->name('shipments.arrive');
        Route::post('/shipments/{shipment}/cancel', [ExportShipmentController::class,'cancel'])
            ->whereNumber('shipment')->name('shipments.cancel');

        Route::get('/export-document-templates', [ExportDocumentTemplateController::class,'index'])
            ->name('document-templates.index');
        Route::post('/export-document-templates', [ExportDocumentTemplateController::class,'store'])
            ->name('document-templates.store');
        Route::get('/export-document-templates/{template}/edit', [ExportDocumentTemplateController::class,'edit'])
            ->whereNumber('template')->name('document-templates.edit');
        Route::put('/export-document-templates/{template}', [ExportDocumentTemplateController::class,'update'])
            ->whereNumber('template')->name('document-templates.update');

        Route::get('/export-documents', [ExportDocumentController::class,'index'])->name('export-documents.index');
        Route::post('/shipments/{shipment}/documents/{template}', [ExportDocumentController::class,'issue'])
            ->whereNumber('shipment')->whereNumber('template')->name('export-documents.issue');
        Route::get('/export-documents/{document}/print', [ExportDocumentController::class,'html'])
            ->whereNumber('document')->name('export-documents.print');
        Route::get('/export-documents/{document}/pdf', [ExportDocumentController::class,'pdf'])
            ->whereNumber('document')->name('export-documents.pdf');

        Route::get('/workspace/{section?}', function (?string $section = 'overview') {
            $sections = config('expo.sections');
            abort_unless(isset($sections[$section]), 404);
            abort_unless(app(BusinessPermissions::class)->allows(
                request()->attributes->get('membership'), $sections[$section][2],
            ), 403);

            return view('workspace', compact('section', 'sections'));
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

        Route::get('/team', [TeamController::class, 'index'])->name('team.index');
        Route::post('/team/invitations', [TeamController::class, 'invite'])->middleware('throttle:10,1')->name('team.invite');

        Route::get('/business-settings', [BusinessSettingsController::class, 'index'])->name('business.settings');
        Route::put('/business-settings', [BusinessSettingsController::class, 'update'])->name('business.settings.update');
    });
});