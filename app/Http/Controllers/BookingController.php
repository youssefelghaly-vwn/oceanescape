<?php

namespace App\Http\Controllers;

use App\Exceptions\BookingException;
use App\Http\Requests\StoreBookingRequest;
use App\Jobs\SendPaymentLink;
use App\Models\Booking;
use App\Models\BookingPayment;
use App\Models\User;
use App\Services\Booking\BookingCreator;
use App\Services\Booking\DepositPolicy;
use App\Services\Booking\QuoteReader;
use App\Services\Lodgify\LodgifyRepository;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BookingController extends Controller
{
    public function __construct(
        protected BookingCreator $creator,
        protected LodgifyRepository $lodgify,
        protected QuoteReader $quotes,
        protected DepositPolicy $policy,
    ) {}

    /**
     * GET /booking/details/{slug}
     *
     * The guest-details step. This is what the "Book now" button opens when direct
     * payments are enabled, replacing the immediate 302 to Lodgify's checkout.
     *
     * WHY A REAL PAGE RATHER THAN A MODAL
     *   - Validation failures have somewhere to land. POST /booking redirects back here
     *     with the input and the errors; a modal would lose them on the round trip.
     *   - It survives a refresh and can be linked to.
     *   - The price shown here is re-quoted SERVER-SIDE, so the figure the guest agrees to
     *     is the same figure DepositPolicy will charge — not whatever the calendar widget
     *     happened to be displaying.
     */
    public function details(Request $request, string $slug): View|RedirectResponse
    {
        $validated = $request->validate([
            'arrival' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'departure' => ['required', 'date_format:Y-m-d', 'after:arrival'],
            'adults' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'children' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'pets' => ['sometimes', 'integer', 'min:0', 'max:10'],
            // Accepted so the calendar's link works unchanged, but NEVER used for pricing.
            'total' => ['sometimes', 'nullable', 'numeric'],
            'addons' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $cottage = $this->lodgify->cottageBySlug($slug);

        if (! $cottage) {
            throw new NotFoundHttpException("Cottage not found: {$slug}");
        }

        $adults = (int) ($validated['adults'] ?? 2);
        $children = (int) ($validated['children'] ?? 0);
        $pets = (int) ($validated['pets'] ?? 0);

        /*
         * Price it now, live, from Lodgify. If Lodgify will not price the stay — or will
         * not tell us its payment schedule — we say so here rather than letting the guest
         * fill in a form that cannot succeed.
         */
        try {
            $quote = $this->quotes->authoritativeQuote(
                $cottage,
                $validated['arrival'],
                $validated['departure'],
                $adults,
                $children,
                $pets,
            );

            $plan = $this->policy->planFor($quote, Carbon::parse($validated['arrival']));
        } catch (BookingException $e) {
            Log::channel('booking')->info('booking.details could not price the stay', [
                'slug' => $slug,
                'reason' => $e->getMessage(),
            ]);

            return redirect()
                ->route('cottage.show', [
                    'slug' => $slug,
                    'arrival' => $validated['arrival'],
                    'departure' => $validated['departure'],
                ])
                ->with('checkout_error', $e->guestMessage() ?? $this->genericFailure());
        }

        /*
         * The calendar told us what it was showing. If the authoritative quote disagrees,
         * say so plainly instead of quietly charging a different number — this is exactly
         * the divergence the checkout_intents table was built to detect, except now we can
         * catch it before the guest commits.
         */
        $shownTotal = isset($validated['total']) && is_numeric($validated['total'])
            ? Money::fromFloat($validated['total'], $plan->total->currency)
            : null;

        $priceChanged = $shownTotal !== null && ! $shownTotal->equals($plan->total);

        return view('pages.booking-details', [
            'cottage' => $cottage,
            'plan' => $plan,
            'quote' => $quote,
            'arrival' => $validated['arrival'],
            'departure' => $validated['departure'],
            'nights' => Carbon::parse($validated['arrival'])->diffInDays(Carbon::parse($validated['departure'])),
            'adults' => $adults,
            'children' => $children,
            'pets' => $pets,
            'priceChanged' => $priceChanged,
            'shownTotal' => $shownTotal,
            'user' => $request->user(),
            /*
             * Drives the extra "pay now" button and the prefilled fields. Decided here, not
             * in the view, and re-decided on POST — a stale page cannot talk the controller
             * into the direct flow.
             */
            'canPayNow' => $this->directPayAllowed($request->user()),
        ]);
    }

    /**
     * POST /booking
     *
     * Creates the reservation in Lodgify as `Open` and issues the deposit payment.
     * NOTHING IS CHARGED HERE, on either path — this only decides where the guest goes next:
     *
     *   emailed link (default)   "check your email", and SendPaymentLink goes out now. The
     *                            act of opening that link proves the address is theirs.
     *   pay now (signed in)      straight to /pay/{token}, and on to Stripe. Available only
     *                            to a verified account booking its OWN email address, where
     *                            the inbox round trip proves nothing we do not already know.
     *                            The email still follows, delayed, in case they walk away.
     *
     * Same booking, same server-derived amount, same webhook. And no card is stored on
     * either path — see App\Services\Payments\StripeGateway.
     */
    public function store(StoreBookingRequest $request): RedirectResponse
    {
        $data = $request->safe()->except('website_url', 'terms_accepted', 'pay_now');

        /*
         * Decided from the SESSION, never from the form. The posted pay_now is a request;
         * this is the answer. A guest, an unverified account, or an account booking somebody
         * else's email address all fall back to the emailed link — which is the ordinary
         * flow, not an error.
         */
        $payNow = $request->boolean('pay_now')
            && $this->directPayAllowed($request->user(), (string) $request->input('guest_email'));

        try {
            $booking = $this->creator->create($data + [
                'user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 512),
                'session_id' => $request->hasSession() ? $request->session()->getId() : null,
            ], emailFirstLink: ! $payNow);
        } catch (BookingException $e) {
            /*
             * Guest-safe copy only. BookingException::guestMessage() returns null when the
             * reason is internal, and we fall back to something generic rather than
             * leaking an upstream error into the page.
             */
            Log::channel('booking')->warning('booking.store rejected', [
                'slug' => $request->input('slug'),
                'reason' => $e->getMessage(),
                'type' => $e::class,
            ]);

            return $this->backToDetails($request, $e->guestMessage() ?? $this->genericFailure());
        } catch (\Throwable $e) {
            report($e);

            return $this->backToDetails($request, $this->genericFailure());
        }

        if ($payNow) {
            $this->rememberDetailsOnProfile($request->user(), $data);

            if ($redirect = $this->straightToPayment($booking)) {
                return $redirect;
            }
        }

        /*
         * The reference goes in the session rather than the URL so the confirmation page
         * cannot be reached by guessing, and so a shared link does not expose someone
         * else's booking.
         */
        return redirect()
            ->route('booking.submitted')
            ->with('booking_reference', $booking->reference);
    }

    /**
     * Send a verified, signed-in guest straight to their payment.
     *
     * The redirect goes to OUR signed /pay/{token} route, not to Stripe: that page is where
     * the session is created (or re-created if it lapsed) and where the attempt is logged.
     * Building a Stripe URL here would duplicate all of it and lose the record.
     *
     * The emailed link is still queued, DELAYED, as the fallback for a guest who walks away
     * from Stripe — SendPaymentLink returns early if the payment settled first, so paying
     * immediately means no email at all.
     *
     * Returns null if there is no payable row, in which case the caller falls through to the
     * ordinary "check your email" page. Nothing has been charged either way.
     */
    protected function straightToPayment(Booking $booking): ?RedirectResponse
    {
        $payment = $booking->deposit();

        if (! $payment instanceof BookingPayment || ! $payment->isPayable()) {
            Log::channel('booking')->warning('Direct payment requested but no payable row exists', [
                'booking' => $booking->reference,
            ]);

            return null;
        }

        $delay = (int) config('booking.direct_pay.fallback_email_delay_minutes', 20);

        SendPaymentLink::dispatch($payment->getKey())->delay(now()->addMinutes($delay));

        return redirect()->to($payment->payUrl());
    }

    /**
     * May this visitor book and pay in one step?
     *
     * The config switch is checked here rather than in the view so that turning the feature
     * off closes the POST path too, not just the button.
     */
    protected function directPayAllowed(?User $user, ?string $forEmail = null): bool
    {
        return (bool) config('booking.direct_pay.enabled', true)
            && $user !== null
            && $user->canBookDirectly($forEmail);
    }

    /**
     * Keep the phone and country a signed-in guest just typed, so the next booking is
     * genuinely one click.
     *
     * Only ever FILLS BLANKS. Overwriting a profile from a booking form would let a stay
     * booked for someone else quietly rewrite the account holder's own details. Never fatal:
     * a failure here costs a prefill, not a booking.
     */
    protected function rememberDetailsOnProfile(?User $user, array $data): void
    {
        if ($user === null) {
            return;
        }

        $updates = array_filter([
            'phone' => blank($user->phone) ? ($data['guest_phone'] ?? null) : null,
            'country' => blank($user->country) ? ($data['guest_country'] ?? null) : null,
        ], fn ($v) => filled($v));

        if ($updates === []) {
            return;
        }

        try {
            $user->forceFill($updates)->save();
        } catch (\Throwable $e) {
            Log::channel('booking')->info('Could not store booking details on the profile', [
                'user' => $user->getKey(),
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * GET /booking/submitted
     *
     * "Check your email for the payment link." Deliberately shows the reference and the
     * amount, but is reachable only via the session flash — someone landing here directly
     * is sent back to the cottages list.
     */
    public function submitted(): View|RedirectResponse
    {
        $reference = session('booking_reference');

        if (! $reference) {
            return redirect()->route('cottages.index');
        }

        $booking = Booking::query()->where('reference', $reference)->first();

        if (! $booking) {
            return redirect()->route('cottages.index');
        }

        return view('pages.booking-submitted', [
            'booking' => $booking,
            'deposit' => $booking->deposit(),
        ]);
    }

    /**
     * Send the guest back to the details form with their input and the error.
     *
     * Explicit rather than back(): a bare back() depends on the Referer header, and on a
     * POST that arrived without one it would bounce to "/" and silently swallow the
     * validation errors.
     */
    protected function backToDetails(StoreBookingRequest $request, string $message): RedirectResponse
    {
        return redirect()
            ->route('booking.details', array_filter([
                'slug' => $request->input('slug'),
                'arrival' => $request->input('arrival'),
                'departure' => $request->input('departure'),
                'adults' => $request->input('adults'),
                'children' => $request->input('children'),
                'pets' => $request->input('pets'),
            ], fn ($v) => $v !== null && $v !== ''))
            ->withInput($request->except(['website_url', '_token']))
            ->withErrors(['booking' => $message]);
    }

    protected function genericFailure(): string
    {
        return 'We could not complete that booking just now. Nothing has been charged. '
             .'Please try again, or call us on '.config('booking.support_phone').'.';
    }
}
