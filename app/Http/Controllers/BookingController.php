<?php

namespace App\Http\Controllers;

use App\Exceptions\BookingException;
use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Services\Booking\BookingCreator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class BookingController extends Controller
{
    public function __construct(protected BookingCreator $creator) {}

    /**
     * POST /booking
     *
     * Creates the reservation in Lodgify as `Open` and queues the deposit link.
     * NOTHING IS CHARGED HERE — the guest pays from the emailed link.
     */
    public function store(StoreBookingRequest $request): RedirectResponse
    {
        if (! config('booking.direct_payments_enabled')) {
            /*
             * Feature flag off: the old Lodgify hosted-checkout path is still the live
             * one, so send the guest there rather than half-running this flow.
             */
            return redirect()->route('booking.redirect', array_filter([
                'slug' => $request->input('slug'),
                'arrival' => $request->input('arrival'),
                'departure' => $request->input('departure'),
                'adults' => $request->input('adults'),
                'children' => $request->input('children'),
                'pets' => $request->input('pets'),
            ]));
        }

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

            return back()
                ->withInput($request->except('website_url'))
                ->withErrors(['booking' => $e->guestMessage() ?? $this->genericFailure()]);
        } catch (\Throwable $e) {
            report($e);

            return back()
                ->withInput($request->except('website_url'))
                ->withErrors(['booking' => $this->genericFailure()]);
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

    protected function genericFailure(): string
    {
        return 'We could not complete that booking just now. Nothing has been charged. '
             .'Please try again, or call us on '.config('booking.support_phone').'.';
    }
}
