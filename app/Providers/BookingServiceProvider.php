<?php

namespace App\Providers;

use App\Services\Booking\BookingAuditor;
use App\Services\Booking\DepositPolicy;
use App\Services\Lodgify\LodgifyBookingWriter;
use App\Services\Lodgify\QuoteNormaliser;
use App\Services\Payments\StripeGateway;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class BookingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * Singletons for the stateless collaborators. StripeGateway in particular builds a
         * StripeClient in its constructor, and there is no reason to do that more than
         * once per request.
         */
        $this->app->singleton(StripeGateway::class);
        $this->app->singleton(QuoteNormaliser::class);
        $this->app->singleton(DepositPolicy::class);
        $this->app->singleton(BookingAuditor::class);
        $this->app->singleton(LodgifyBookingWriter::class);
    }

    public function boot(): void
    {
        $this->registerRateLimiters();
    }

    /**
     * Named rate limiters for the payment surface.
     *
     * Named rather than inline `throttle:N,M` so the reasoning lives next to the number,
     * and so the webhook limiter can be deliberately generous — see below.
     */
    protected function registerRateLimiters(): void
    {
        /*
         * Creating a booking is expensive and side-effectful: it re-quotes against
         * Lodgify, creates a real reservation, and issues a Stripe session. Keyed on IP
         * AND email so one visitor cannot spray reservations across many addresses, and
         * one address cannot be hammered from many IPs.
         */
        RateLimiter::for('booking-create', fn (Request $request) => [
            Limit::perMinute(5)->by('ip:'.$request->ip()),
            Limit::perMinute(3)->by('email:'.strtolower((string) $request->input('guest_email'))),
            Limit::perDay(40)->by('ip:'.$request->ip()),
        ]);

        /*
         * Payment pages are read-mostly but do talk to Stripe, so keep them modest. Keyed
         * on the token rather than the IP: a guest refreshing their own link is normal,
         * while the same IP walking many tokens is not.
         */
        RateLimiter::for('payment-page', fn (Request $request) => [
            Limit::perMinute(20)->by('token:'.$request->route('token')),
            Limit::perMinute(60)->by('ip:'.$request->ip()),
        ]);

        /*
         * DELIBERATELY GENEROUS. Throttling a payment webhook into a 429 makes Stripe
         * retry, which delays a real booking confirmation — the limiter must not become
         * the reason a paid guest is left Open. High enough to never trip on legitimate
         * Stripe traffic (including retry bursts after an outage), low enough to blunt an
         * outright flood. Requests are cheap to reject anyway: an invalid signature is
         * refused before any database work.
         */
        RateLimiter::for('stripe-webhook', fn (Request $request) => Limit::perMinute(300)
            ->by('stripe-webhook')
            ->response(fn () => response('Rate limited.', 429)));
    }
}
