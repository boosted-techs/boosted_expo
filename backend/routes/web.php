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
use App\Support\BusinessPermissions;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('workspace'));
Route::get('/q/{token}', [PublicQrController::class, 'show'])->middleware('throttle:120,1')->name('qr.public');
Route::get('/q/{token}/photo', [PublicQrController::class, 'photo'])->middleware('throttle:120,1')->name('qr.photo');

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