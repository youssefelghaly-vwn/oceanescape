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
use App\Http\Controllers\Admin\CheckoutIntentController;
use App\Http\Controllers\BookingRedirectController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\Webhooks\StripeWebhookController;
use App\Http\Controllers\Admin\ReservationController as AdminReservationController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\ProfileController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
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



// Records the intent, then hands off to Lodgify's hosted checkout.
Route::get('/book/{slug}', BookingRedirectController::class)
    ->middleware('throttle:30,1')
    ->name('booking.redirect');

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/checkouts', [CheckoutIntentController::class, 'index'])->name('checkouts.index');
});


/*
|--------------------------------------------------------------------------
| Direct booking + Stripe payments
|--------------------------------------------------------------------------
| Replaces the hand-off to Lodgify's hosted checkout when
| config('booking.direct_payments_enabled') is true. /book/{slug} above remains the
| fallback and is what BookingController redirects to when the flag is off.
|
| Flow: POST /booking creates the reservation in Lodgify as `Open` (charging nothing) and
| emails a Stripe link. The guest pays from that link; the WEBHOOK is what confirms the
| booking. See Docs/05-payments-and-booking.md.
*/

/*
 * The guest-details step. This is where the cottage page's "Book now" button goes when
 * direct payments are on; it re-prices the stay server-side and collects the details we
 * need to create the reservation.
 */
Route::get('/booking/details/{slug}', [BookingController::class, 'details'])
    ->middleware('throttle:60,1')
    ->name('booking.details');

Route::post('/booking', [BookingController::class, 'store'])
    // Each attempt can create a Lodgify reservation and a Stripe session, so this is
    // deliberately tighter than the read endpoints.
    ->middleware('throttle:booking-create')
    ->name('booking.store');

Route::get('/booking/submitted', [BookingController::class, 'submitted'])
    ->name('booking.submitted');

/*
 * Payment pages.
 *
 * `signed` is doing real work here: the token in the path is 32 random bytes, and the
 * signature adds an expiry we control, so a forwarded link stops working when we say it
 * does rather than whenever Stripe's own session lapses.
 */
Route::middleware(['signed', 'throttle:payment-page'])->group(function () {
    Route::get('/pay/{token}', [PaymentController::class, 'show'])->name('booking.pay');
});

/*
 * Stripe's return URLs. NOT signed — Stripe appends its own query parameters and
 * redirects the browser here, which would break a signature. That is safe because
 * neither page grants anything: they only read state, and any reconciliation they do
 * goes through the same idempotent, amount-checking settler as the webhook.
 */
Route::get('/pay/{token}/success',   [PaymentController::class, 'success'])
    ->middleware('throttle:payment-page')
    ->name('booking.pay.success');

Route::get('/pay/{token}/cancelled', [PaymentController::class, 'cancelled'])
    ->middleware('throttle:payment-page')
    ->name('booking.pay.cancelled');

/*
 * Stripe webhook.
 *
 * CSRF-exempt (see bootstrap/app.php) because Stripe cannot present a token, and
 * therefore SIGNATURE-VERIFIED inside the controller before the body is parsed. That
 * verification is the only thing protecting this endpoint — see the controller docblock.
 *
 * Throttled generously rather than tightly: rate-limiting a payment webhook into a 429
 * means Stripe retries and a real payment is delayed, so the limit is set well above
 * Stripe's burst behaviour and exists only to blunt an outright flood.
 */
Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle'])
    ->middleware('throttle:stripe-webhook')
    ->name('webhooks.stripe');



// ------------------------------------------------------------ registration
Route::middleware('guest')->group(function () {
    Route::get('/register',  [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('register.store');
});

// ------------------------------------------------------ email verification
/*
 * NOT OPTIONAL. Reservations are matched to a user by email address, so
 * verification is what proves the inbox belongs to them. Without it, anyone
 * could register with a past guest's address and read their booking history.
 */
Route::middleware('auth')->group(function () {
    Route::get('/verify-email', fn () => view('auth.verify-email'))->name('verification.notice');

    Route::get('/verify-email/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();
        return redirect()->route('profile.index')->with('status', 'Email confirmed — here are your stays.');
    })->middleware('signed')->name('verification.verify');

    Route::post('/verify-email/send', function (Request $request) {
        $request->user()->sendEmailVerificationNotification();
        return back()->with('status', 'Verification link sent.');
    })->middleware('throttle:6,1')->name('verification.send');
});

// ------------------------------------------------------------ guest profile
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/my-stays',       [ProfileController::class, 'index'])->name('profile.index');
    Route::get('/my-stays/{id}',  [ProfileController::class, 'show'])->name('profile.show');

    Route::get('/account',           [ProfileController::class, 'edit'])->name('account.edit');
    Route::patch('/account',         [ProfileController::class, 'update'])->name('account.update');
    Route::put('/account/password',  [ProfileController::class, 'updatePassword'])->name('account.password');
});

// -------------------------------------------------------- admin reservations
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/reservations',        [AdminReservationController::class, 'index'])->name('reservations.index');
    Route::post('/reservations/refresh',[AdminReservationController::class, 'refresh'])->name('reservations.refresh');
    Route::get('/reservations/{id}',   [AdminReservationController::class, 'show'])->name('reservations.show');
});