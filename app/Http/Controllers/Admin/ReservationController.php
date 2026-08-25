<?php

namespace App\Http\Controllers\Admin;

use App\Services\Lodgify\ReservationRepository;
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
            'sort'        => $request->query('sort', 'arrival'),
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

    /** GET /admin/reservations/{id} */
    public function show(string $id): View
    {
        $reservation = $this->reservations->find($id);

        if (!$reservation) {
            throw new NotFoundHttpException("Reservation {$id} not found");
        }

        return view('admin.reservations.show', ['reservation' => $reservation]);
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
