<?php

namespace App\Http\Controllers\Admin;

use App\Models\Booking;
use App\Models\BookingAuditLog;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

/**
 * The audit trail, for a human.
 *
 * WHY THIS SCREEN EXISTS
 *
 * `booking_audit_logs` was built so that "why was I charged this?" and "I paid, where is my
 * booking?" are answerable months later **by a non-engineer** — that was the stated reason
 * for a table rather than only a log file. Until now the table had no reader: answering
 * either question meant a Tinker session or grepping `storage/logs`. A trail nobody can read
 * is documentation, not an audit trail.
 *
 * TWO VIEWS, BECAUSE THERE ARE TWO QUESTIONS
 *
 *   index  "what has been happening?" — everything, newest first, filterable. This is the
 *          morning check and the place a problem is noticed.
 *   show   "what happened to THIS booking?" — one booking's whole life in order, with its
 *          payments beside it. This is the answer given to a guest.
 *
 * STRICTLY READ-ONLY. No actions, no forms, no bulk anything. The rows are immutable by
 * construction (BookingAuditLog throws on update and delete), so an admin screen that
 * offered to change one would be lying about what it could do.
 */
class BookingAuditController extends Controller
{
    /** Prefix filters. Kept in one place so the view and the query cannot disagree. */
    public const GROUPS = [
        'all' => 'Everything',
        'booking' => 'Booking',
        'payment' => 'Payment',
        'stripe' => 'Stripe',
        'lodgify' => 'Lodgify',
    ];

    /** Who or what caused the row. Matches the values BookingAuditor writes. */
    public const ACTORS = [
        'all' => 'Anyone',
        'system' => 'System',
        'guest' => 'Guest',
        'admin' => 'Admin',
        'stripe' => 'Stripe',
        'lodgify' => 'Lodgify',
    ];

    /**
     * GET /admin/audits
     */
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'group' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', array_keys(self::GROUPS))],
            'event' => ['sometimes', 'nullable', 'string', 'max:120'],
            'actor' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', array_keys(self::ACTORS))],
            'attention' => ['sometimes', 'nullable', 'boolean'],
        ]);

        $entries = BookingAuditLog::query()
            /*
             * Eager loaded, not lazy: every row renders its booking reference and its
             * payment reference, so without this a 50-row page is 150 queries. The audit
             * table is the fastest-growing table in the schema — see Docs/04.
             */
            ->with(['booking:id,reference,guest_email,cottage_name,arrival,departure', 'bookingPayment:id,reference,type', 'actor:id,name,email'])
            ->search($filters['q'] ?? null)
            ->group($filters['group'] ?? null)
            ->event($filters['event'] ?? null)
            ->actorType($filters['actor'] ?? null)
            ->when(! empty($filters['attention']), fn ($q) => $q->needsAttention())
            /*
             * created_at DESC, then id DESC. The timestamp alone is not a total order: a
             * booking's create/transition/payment rows are written inside one request and
             * routinely share a second, so ties would shuffle between page loads and a row
             * could appear on two pages or none.
             */
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.audits.index', [
            'entries' => $entries,
            'filters' => $filters,
            'groups' => self::GROUPS,
            'actors' => self::ACTORS,
            'stats' => $this->stats(),
        ]);
    }

    /**
     * GET /admin/audits/{booking}
     *
     * One booking's trail, oldest first — the order it happened in, which is the order it
     * has to be read in to explain anything.
     */
    public function show(Booking $booking): View
    {
        $booking->load(['payments', 'user:id,name,email']);

        $entries = BookingAuditLog::query()
            ->with(['bookingPayment:id,reference,type', 'actor:id,name,email'])
            ->where('booking_id', $booking->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return view('admin.audits.show', [
            'booking' => $booking,
            'entries' => $entries,
        ]);
    }

    /**
     * The counts worth putting at the top.
     *
     * `attention` is the only one that is a call to action: it counts the events the docs
     * mark "needs a human" — a captured amount that did not match, a guest who paid while
     * Lodgify stayed unconfirmed, a payment link that never sent.
     *
     * @return array<string, int>
     */
    protected function stats(): array
    {
        return [
            'total' => BookingAuditLog::count(),
            'today' => BookingAuditLog::where('created_at', '>=', now()->startOfDay())->count(),
            'week' => BookingAuditLog::where('created_at', '>=', now()->subDays(7))->count(),
            'attention' => BookingAuditLog::needsAttention()->count(),
        ];
    }
}
