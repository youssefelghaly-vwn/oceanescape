<?php

namespace App\Http\Controllers;

use App\DTO\Reservation;
use App\Models\Booking;
use App\Services\Lodgify\ReservationRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function __construct(protected ReservationRepository $reservations) {}

    /**
     * GET /my-stays
     *
     * Reservations are matched to the signed-in user BY EMAIL ADDRESS. That is
     * only safe because the route requires a VERIFIED email — see the `verified`
     * middleware on the route. Without it, registering with someone else's
     * address would expose their booking history.
     *
     * Nothing is written locally: this is a live read from Lodgify.
     */
    public function index(Request $request): View
    {
        $user = $request->user();

        $reservations = collect();
        $failed = false;

        try {
            $reservations = $this->withLocalTotals($this->reservations->forEmail($user->email));
        } catch (\Throwable $e) {
            report($e);
            $failed = true;
        }

        $grouped = [
            'current'   => $reservations->filter(fn (Reservation $r) => $r->timeframe() === 'current')->values(),
            'upcoming'  => $reservations->filter(fn (Reservation $r) => $r->timeframe() === 'upcoming')
                                        ->sortBy(fn (Reservation $r) => $r->arrival?->timestamp ?? 0)->values(),
            'past'      => $reservations->filter(fn (Reservation $r) => $r->timeframe() === 'past')->values(),
            'cancelled' => $reservations->filter(fn (Reservation $r) => $r->timeframe() === 'cancelled')->values(),
        ];

        return view('pages.profile', [
            'user'    => $user,
            'grouped' => $grouped,
            'total'   => $reservations->count(),
            'nights'  => $reservations->reject->isCancelled()->sum(fn (Reservation $r) => $r->nights ?? 0),
            'failed'  => $failed,
        ]);
    }

    /** GET /my-stays/{id} */
    public function show(Request $request, string $id): View|RedirectResponse
    {
        $reservation = $this->reservations->find($id);

        /*
         * Ownership check, not just existence. Without comparing the email the
         * booking id alone would be an access token — and Lodgify ids are
         * sequential, so they are trivially guessable.
         */
        if (!$reservation
            || strtolower((string) $reservation->guestEmail) !== strtolower($request->user()->email)) {
            return redirect()
                ->route('profile.index')
                ->with('profile_error', 'We couldn\'t find that booking on your account.');
        }

        if ($booking = $this->localBookingFor($reservation->id)) {
            $reservation = $this->applyLocalTotals($reservation, $booking);
        }

        return view('pages.reservation', ['reservation' => $reservation]);
    }

    /**
     * Swap Lodgify's money fields for the database's, on every reservation with a
     * matching local `Booking` row.
     *
     * A reservation with no local row was taken by phone, or through Airbnb or
     * Booking.com — those exist only in Lodgify, so its own totals are shown as-is.
     *
     * @param  Collection<int, Reservation>  $reservations
     * @return Collection<int, Reservation>
     */
    protected function withLocalTotals(Collection $reservations): Collection
    {
        $ids = $reservations->pluck('id')->all();
        if ($ids === []) {
            return $reservations;
        }

        $bookings = Booking::query()
            ->with('payments')
            ->whereIn('lodgify_booking_id', $ids)
            ->get()
            ->keyBy('lodgify_booking_id');

        return $reservations->map(function (Reservation $r) use ($bookings) {
            $booking = $bookings->get($r->id);

            return $booking ? $this->applyLocalTotals($r, $booking) : $r;
        });
    }

    protected function applyLocalTotals(Reservation $reservation, Booking $booking): Reservation
    {
        return $reservation->withMoney(
            total: $booking->total()->toFloat(),
            amountPaid: $booking->amountPaid()->toFloat(),
            amountDue: $booking->amountOutstanding()->toFloat(),
            currency: $booking->currency,
        );
    }

    /** Our own record of a Lodgify reservation, if this booking came through this site. */
    protected function localBookingFor(string $lodgifyBookingId): ?Booking
    {
        return Booking::query()
            ->with('payments')
            ->where('lodgify_booking_id', $lodgifyBookingId)
            ->first();
    }

    /** GET /account */
    public function edit(Request $request): View
    {
        return view('pages.account', ['user' => $request->user()]);
    }

    /**
     * PATCH /account
     *
     * Changing the email address changes which reservations are visible, so it
     * resets verification and the account loses access until the new address is
     * confirmed.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name'  => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:180', 'unique:users,email,' . $user->id],
            /*
             * Contact details only, and both optional. They exist so a returning guest does
             * not retype them when booking — the details form on /booking/details/{slug}
             * reads them straight off the account. Nothing to do with payment is stored on an
             * account: no card, no token, no Stripe customer.
             */
            'phone'   => ['sometimes', 'nullable', 'string', 'min:6', 'max:40'],
            'country' => ['sometimes', 'nullable', 'string', 'size:2', 'alpha'],
        ]);

        $emailChanged = strtolower($validated['email']) !== strtolower($user->email);

        $user->fill([
            'name'    => $validated['name'],
            'email'   => strtolower($validated['email']),
            'phone'   => $validated['phone'] ?? null,
            'country' => isset($validated['country']) ? strtoupper($validated['country']) : null,
        ]);

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();

            return redirect()
                ->route('verification.notice')
                ->with('status', 'Email updated — please confirm the new address to see your stays.');
        }

        return back()->with('status', 'Your details have been updated.');
    }

    /** PUT /account/password */
    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password'         => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $request->user()->update(['password' => $validated['password']]);

        return back()->with('status', 'Your password has been changed.');
    }
}