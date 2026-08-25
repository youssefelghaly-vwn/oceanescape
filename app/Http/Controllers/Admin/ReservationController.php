<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BookingStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\BookingAuditLog;
use App\Services\Booking\BookingAuditor;
use App\Services\Lodgify\ReservationRepository;
use App\Services\Payments\PaymentLinkService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ReservationController extends Controller
{
    /**
     * Page sizes offered.
     *
     * An allowlist, not a free number: `per_page` comes from the query string, and an
     * unbounded value would let anyone ask this page to render every reservation Lodgify has
     * — a needless way to make the admin area slow from outside it.
     */
    public const PER_PAGE_OPTIONS = [25, 50, 100];

    public function __construct(protected ReservationRepository $reservations) {}

    protected function perPage(Request $request): int
    {
        $requested = (int) $request->query('per_page', self::PER_PAGE_OPTIONS[0]);

        return in_array($requested, self::PER_PAGE_OPTIONS, true)
            ? $requested
            : self::PER_PAGE_OPTIONS[0];
    }

    /** GET /admin/reservations */
    public function index(Request $request): View
    {
        $filters = [
            'q'           => $request->query('q'),
            'email'       => $request->query('email'),
            'status'      => $request->query('status'),
            'timeframe'   => $request->query('timeframe', 'all'),
            'property_id' => $request->query('property_id'),
            'source'      => $request->query('source'),
            'from'        => $request->query('from'),
            'to'          => $request->query('to'),
            'unpaid'      => $request->boolean('unpaid'),
            /*
             * Newest FIRST by default, on the date the booking was made rather than the
             * date of the stay. Opening this screen is nearly always "what came in?", and
             * arrival-order buried a booking taken this morning for next August somewhere in
             * the middle of the list.
             */
            'sort'        => $request->query('sort', 'created'),
            'dir'         => $request->query('dir', 'desc'),
        ];

        $results = $this->reservations->search($filters);

        /*
         * Paginated in PHP because the whole set is already in memory: Lodgify's
         * paging support is unconfirmed, and six cottages produce a small enough
         * volume that this is simpler and lets filters work across everything.
         */
        $perPage = $this->perPage($request);
        $total   = $results->count();

        /*
         * Clamp the page instead of serving an empty one.
         *
         * A stale link, a bookmark, or narrowing the filters while on page 4 all ask for a
         * page that no longer exists. The default behaviour is an empty table, which this
         * view renders as "No reservations match — try clearing the filters" — wrong, and it
         * sends the operator hunting for a filter problem that does not exist. We hold the
         * whole set in memory, so the last page is known and free to compute.
         */
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page     = min(max(1, (int) $request->query('page', 1)), $lastPage);

        $paginated = new LengthAwarePaginator(
            $results->forPage($page, $perPage)->values(),
            $total,
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('admin.reservations.index', [
            'reservations' => $paginated,
            'filters'      => $filters,
            'options'      => $this->reservations->filterOptions(),
            'stats'        => $this->reservations->stats(),
            'matched'      => $total,
            'perPage'      => $perPage,
            'perPageOptions' => self::PER_PAGE_OPTIONS,
        ]);
    }

    /**
     * GET /admin/reservations/{id}
     *
     * TWO SOURCES ON ONE PAGE, and they answer different questions:
     *
     *   Lodgify   the reservation itself — dates, guest, status, its own totals. Live, and
     *             authoritative: Lodgify owns the booking.
     *   Us        the money. What we quoted, what we asked for, what Stripe captured, which
     *             links were emailed and when, and the audit trail behind all of it.
     *
     * They are joined on `bookings.lodgify_booking_id`. A reservation with no local row is
     * normal, not an error — anything taken by phone, or through Airbnb or Booking.com,
     * exists only in Lodgify, and the page says so rather than implying data is missing.
     */
    public function show(string $id): View
    {
        $reservation = $this->reservations->find($id);

        if (! $reservation) {
            throw new NotFoundHttpException("Reservation {$id} not found");
        }

        $booking = $this->localBookingFor($id);

        return view('admin.reservations.show', [
            'reservation' => $reservation,
            'booking' => $booking,
            /*
             * The recent trail inline, with a link to the whole thing. Enough to see what
             * happened without leaving the page, and never so much that the reservation
             * itself is pushed off the screen.
             */
            'audits' => $booking
                ? BookingAuditLog::query()
                    ->with('bookingPayment:id,reference,type')
                    ->where('booking_id', $booking->getKey())
                    ->orderByDesc('created_at')->orderByDesc('id')
                    ->limit(12)->get()
                : collect(),
            'auditTotal' => $booking ? BookingAuditLog::where('booking_id', $booking->getKey())->count() : 0,
            'outstanding' => $booking?->amountOutstanding(),
            'sendable' => $booking ? $this->nextPaymentToRequest($booking) : null,
        ]);
    }

    /**
     * POST /admin/reservations/{id}/payment-link
     *
     * "Email them the link for what is still owed."
     *
     * Deliberately NOT "send the balance link": what is outstanding depends on the booking.
     * A deposit that was never paid needs the deposit link again, not a balance link for
     * money we have not asked for yet. The button names whichever it is, and this method
     * re-derives it rather than trusting the form.
     *
     * NOTHING HERE INVENTS AN AMOUNT. It comes from the booking's stored plan, which came
     * from Lodgify's own schedule at booking time — the same figures the guest already
     * agreed to.
     */
    public function sendPaymentLink(string $id, PaymentLinkService $links, BookingAuditor $auditor): RedirectResponse
    {
        $booking = $this->localBookingFor($id);

        if (! $booking) {
            return back()->with('status', 'That reservation was not taken on this site, so there is no payment to send.');
        }

        $type = $this->nextPaymentToRequest($booking);

        if (! $type) {
            return back()->with('status', "Nothing is outstanding on {$booking->reference} — no email sent.");
        }

        $amount = $type === PaymentType::Balance
            ? $booking->balanceAmount()
            : $booking->depositAmount();

        try {
            /*
             * issue() is idempotent on (booking, type): an existing row is reused rather
             * than re-priced, and the queued job rebuilds the Stripe session from it. So a
             * double-clicked button re-sends ONE link — it can never create a second
             * payable amount.
             */
            $payment = $links->issue($booking, $type, $amount);

            $auditor->record('payment.link_requested_by_admin', $booking, $payment, [
                'type' => $type->value,
                'amount' => $amount->format(),
                'to' => $booking->guest_email,
            ], actorType: 'admin');

            /*
             * Move the booking to awaiting_balance only when that is actually the step being
             * taken, and only when the transition is legal — re-sending a deposit link must
             * not advance anything.
             */
            if ($type === PaymentType::Balance && $booking->status->canTransitionTo(BookingStatus::AwaitingBalance)) {
                $booking->transitionTo(BookingStatus::AwaitingBalance);
            }
        } catch (\Throwable $e) {
            report($e);

            return back()->with('status', "Could not send that link: {$e->getMessage()}");
        }

        return back()->with('status', sprintf(
            '%s link for %s queued to %s.',
            ucfirst($type->value),
            $amount->format(),
            $booking->guest_email,
        ));
    }

    /** Our own record of a Lodgify reservation, if this booking came through this site. */
    protected function localBookingFor(string $lodgifyBookingId): ?Booking
    {
        return Booking::query()
            ->with(['payments', 'user:id,name,email'])
            ->where('lodgify_booking_id', $lodgifyBookingId)
            ->first();
    }

    /**
     * Which payment, if any, we should be asking this guest for.
     *
     * Returns null when there is nothing to chase: no money outstanding, a terminal booking,
     * or a payment of that type that is already settled.
     */
    protected function nextPaymentToRequest(Booking $booking): ?PaymentType
    {
        if ($booking->status->isTerminal() || ! $booking->amountOutstanding()->isPositive()) {
            return null;
        }

        $deposit = $booking->deposit();

        // The deposit (or a single full payment) still owing takes precedence: until it is
        // paid the dates are not even held, so a balance link would be asking for the wrong
        // thing at the wrong time.
        if ($deposit && ! $deposit->status->isSettled()) {
            return $deposit->type;
        }

        return $booking->balanceAmount()->isPositive() ? PaymentType::Balance : null;
    }

    /**
     * POST /admin/reservations/refresh
     *
     * Reservations are cached for a few minutes. When someone is on the phone
     * with a guest who has just booked, waiting for a TTL is not acceptable.
     */
    public function refresh(): RedirectResponse
    {
        $this->reservations->flush();

        return back()->with('status', 'Reservations refreshed from Lodgify.');
    }
}
