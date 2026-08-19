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
    public function __construct(protected ReservationRepository $reservations) {}

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
        $perPage = 25;
        $page    = max(1, (int) $request->query('page', 1));

        $paginated = new LengthAwarePaginator(
            $results->forPage($page, $perPage)->values(),
            $results->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('admin.reservations.index', [
            'reservations' => $paginated,
            'filters'      => $filters,
            'options'      => $this->reservations->filterOptions(),
            'stats'        => $this->reservations->stats(),
            'matched'      => $results->count(),
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
