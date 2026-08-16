<?php

use App\Http\Controllers\Admin\BusinessStayRequestController;
use App\Http\Controllers\AvailabilityController;
use App\Http\Controllers\BusinessStayController;
use App\Http\Controllers\CottageController;
use App\Http\Controllers\DebugController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\RateController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Admin\ContactMessageController as AdminContactMessageController;
use App\Http\Controllers\Admin\GuestPhotoController as AdminGuestPhotoController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\GalleryController;
use App\Http\Controllers\GuestPhotoController;
use App\Http\Controllers\ReviewController;

Route::get('/',               [HomeController::class, 'index'])->name('home');
Route::get('/cottages',       [CottageController::class, 'index'])->name('cottages.index');
Route::get('/cottage/{slug}', [CottageController::class, 'show'])->name('cottage.show');
Route::get('/availability',   [AvailabilityController::class, 'search'])->name('availability.search');

Route::get('/api/availability/month', [AvailabilityController::class, 'month'])
    ->middleware('throttle:60,1')
    ->name('api.availability.month');

// Per-cottage per-day prices for the price-annotated calendar
Route::get('/api/cottage/{slug}/rates', [RateController::class, 'month'])
    ->middleware('throttle:120,1')
    ->name('api.cottage.rates');

// Live quote for the sticky booking panel
Route::get('/api/cottage/{slug}/quote', [RateController::class, 'quote'])
    ->middleware('throttle:120,1')
    ->name('api.cottage.quote');

// Optional extras offered with a booking
Route::get('/api/cottage/{slug}/addons', [RateController::class, 'addons'])
    ->middleware('throttle:60,1')
    ->name('api.cottage.addons');

// Debug / API inspection — local + staging only.
if (app()->environment(['local', 'staging'])) {
    Route::prefix('debug/lodgify')->group(function () {
        Route::get('/',                 [DebugController::class, 'lodgify']);
        Route::get('/why',              [DebugController::class, 'why']);
        Route::get('/flush',            [DebugController::class, 'flush']);
        Route::get('/probe/rates/{id}',  [DebugController::class, 'probeRates']);
        Route::get('/probe/photos/{id}', [DebugController::class, 'probePhotos']);
        Route::get('/images/{id}',       [DebugController::class, 'images']);
        Route::get('/raw/{what}/{id?}', [DebugController::class, 'raw']);
    });
}


// ---------------------------------------------------------------- public
Route::view('/things-to-do', 'pages.things-to-do')->name('things-to-do');

Route::controller(BusinessStayController::class)
    ->prefix('business-stays')
    ->name('business-stays.')
    ->group(function () {
        Route::get('/', 'create')->name('create');
        Route::post('/', 'store')->middleware('throttle:6,1')->name('store');
        Route::get('/thank-you', 'thanks')->name('thanks');
    });

Route::get('/contact',  [ContactController::class, 'create'])->name('contact');
Route::post('/contact', [ContactController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('contact.store');

Route::get('/gallery', [GalleryController::class, 'index'])->name('gallery');

Route::get('/share-your-photos',  [GuestPhotoController::class, 'create'])->name('photos.create');
Route::post('/share-your-photos', [GuestPhotoController::class, 'store'])
    // Uploads are expensive; 3 submissions a minute is generous for a person
    // and useless to anyone trying to fill the disk.
    ->middleware('throttle:3,1')
    ->name('photos.store');

// ----------------------------------------------------------------- admin
/*
 * Gated behind `auth`. If the project has no auth scaffolding yet:
 *     composer require laravel/breeze --dev && php artisan breeze:install blade
 *
 * For a single-operator site, an `is_admin` column plus a `can:admin` gate is
 * usually enough; add it before this goes public, since these routes expose
 * every enquiry's contact details.
 */


Route::middleware('guest')->group(function () {
    Route::get('/login',  [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('login.store');

    Route::get('/forgot-password',  [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('password.email');

    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password',        [ResetPasswordController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('password.update');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
 * Admin area — `auth` then `admin`, in that order, so a signed-out visitor is
 * sent to login while a signed-in non-admin gets a clear 403.
 */
Route::middleware(['auth', 'admin'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', fn() => redirect()->route('admin.business-stays.index'));

        Route::controller(\App\Http\Controllers\Admin\BusinessStayRequestController::class)
            ->prefix('business-stays')
            ->name('business-stays.')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/{businessStayRequest}', 'show')->name('show');
                Route::patch('/{businessStayRequest}', 'update')->name('update');
                Route::delete('/{businessStayRequest}', 'destroy')->name('destroy');
            });

        Route::controller(AdminContactMessageController::class)
            ->prefix('messages')->name('messages.')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                Route::get('/{contactMessage}', 'show')->name('show');
                Route::patch('/{contactMessage}', 'update')->name('update');
                Route::delete('/{contactMessage}', 'destroy')->name('destroy');
            });

        Route::controller(AdminGuestPhotoController::class)
            ->prefix('photos')->name('photos.')
            ->group(function () {
                Route::get('/', 'index')->name('index');
                // Streams pending files from the private disk — admin-only by design.
                Route::get('/{guestPhoto}/file', 'file')->name('file');
                Route::patch('/{guestPhoto}/approve', 'approve')->name('approve');
                Route::patch('/{guestPhoto}/reject', 'reject')->name('reject');
                Route::delete('/{guestPhoto}', 'destroy')->name('destroy');
            });
    });

    Route::view('/privacy-and-policy', 'pages.privacy-and-policy')->name('privacy');

Route::get('/reviews', [ReviewController::class, 'index'])->name('reviews');
