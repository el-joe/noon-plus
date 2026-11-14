<?php

use App\Domains\Cart\Http\Controllers\CartController;
use App\Domains\Catalog\Http\Controllers\CatalogController;
use App\Domains\Countries\Http\Controllers\CountryController;
use App\Domains\Countries\Http\Middleware\ResolveMarket;
use App\Domains\FlashSales\Http\Controllers\FlashSaleController;
use App\Domains\Identity\Http\Controllers\EmailCodeAuthController;
use App\Domains\Identity\Http\Controllers\SecurityController;
use App\Domains\Orders\Http\Controllers\OrderController;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('countries', [CountryController::class, 'index'])->name('countries.index');

    Route::prefix('{market}')->middleware(ResolveMarket::class)->group(function (): void {
        Route::get('context', [CountryController::class, 'context'])->name('market.context');

        Route::get('categories', [CatalogController::class, 'categories'])->name('catalog.categories');
        Route::get('products', [CatalogController::class, 'products'])->name('catalog.products');
        Route::get('products/{slug}', [CatalogController::class, 'product'])->name('catalog.product');

        Route::get('flash-sales', [FlashSaleController::class, 'index'])->name('flash-sales.index');
        Route::get('flash-sales/{id}', [FlashSaleController::class, 'show'])->whereNumber('id')->name('flash-sales.show');

        Route::prefix('cart')->name('cart.')->group(function (): void {
            Route::get('/', [CartController::class, 'show'])->name('show');
            Route::put('items', [CartController::class, 'setItem'])->name('items.set');
            Route::delete('items/{listingId}', [CartController::class, 'removeItem'])->whereNumber('listingId')->name('items.remove');
            Route::put('items/{listingId}/warranty', [CartController::class, 'setWarranty'])->whereNumber('listingId')->name('items.warranty');
            Route::put('shipping', [CartController::class, 'setShipping'])->name('shipping');
            Route::post('coupons', [CartController::class, 'applyCoupon'])->name('coupons.apply');
            Route::delete('coupons/{code}', [CartController::class, 'removeCoupon'])->name('coupons.remove');
            Route::post('merge', [CartController::class, 'merge'])->middleware('auth:sanctum')->name('merge');
        });

        Route::middleware('auth:sanctum')->prefix('orders')->name('orders.')->group(function (): void {
            Route::post('/', [OrderController::class, 'store'])->middleware('throttle:10,1')->name('store');
            Route::get('/', [OrderController::class, 'index'])->name('index');
            Route::get('{number}', [OrderController::class, 'show'])->name('show');
            Route::post('{number}/shipments/{shipmentNumber}/cancel', [OrderController::class, 'cancelShipment'])->name('shipments.cancel');
        });

        Route::prefix('auth/email-code')->name('auth.email-code.')->middleware('throttle:email-code')->group(function (): void {
            Route::post('send', [EmailCodeAuthController::class, 'send'])->name('send');
            Route::post('verify', [EmailCodeAuthController::class, 'verify'])->name('verify');
        });

        Route::middleware('auth:sanctum')->prefix('me')->name('me.')->group(function (): void {
            Route::get('/', fn (Request $request) => ApiResponse::success([
                'id' => $request->user()->id,
                'name' => $request->user()->name,
                'email' => $request->user()->email,
            ]))->name('show');
            Route::get('devices', [SecurityController::class, 'devices'])->name('devices.index');
            Route::delete('devices/{tokenId}', [SecurityController::class, 'revokeDevice'])->whereNumber('tokenId')->name('devices.destroy');
            Route::get('login-history', [SecurityController::class, 'loginHistory'])->name('login-history');
        });
    });
});
