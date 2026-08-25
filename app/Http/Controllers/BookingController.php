<?php

namespace App\Http\Controllers;

use App\Exceptions\BookingException;
use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
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
        ]);
    }

    /**
     * POST /booking
     *
     * Creates the reservation in Lodgify as `Open` and queues the deposit link.
     * NOTHING IS CHARGED HERE — the guest pays from the emailed link.
     */
    public function store(StoreBookingRequest $request): RedirectResponse
    {
        $data = $request->safe()->except('website_url', 'terms_accepted');

        try {
            $booking = $this->creator->create($data + [
                'user_id' => $request->user()?->id,
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 512),
                'session_id' => $request->hasSession() ? $request->session()->getId() : null,
            ]);
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
