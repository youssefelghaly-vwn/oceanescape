<?php

namespace App\Http\Controllers;

use App\Services\Lodgify\LodgifyRepository;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CottageController extends Controller
{
    public function __construct(protected LodgifyRepository $lodgify) {}

    /**
     * GET /cottages — the full listing with next-open windows per cottage.
     */
    public function index(): View
    {
        $listings = collect();
        $degraded = false;

        try {
            $listings = $this->lodgify->cottagesWithOpenings(windowsPerCottage: 3);
            $degraded = !empty($this->lodgify->lastErrors());
        } catch (\Throwable $e) {
            Log::error('cottages.index failed', ['message' => $e->getMessage()]);
            $degraded = true;
            // Still render the page with names/images even if availability failed.
            try {
                $listings = $this->lodgify->allCottages()
                    ->map(fn ($c) => ['cottage' => $c, 'windows' => []]);
            } catch (\Throwable) {
                $listings = collect();
            }
        }

        return view('pages.cottages', [
            'listings' => $listings,
            'degraded' => $degraded,
        ]);
    }

    /**
     * GET /cottage/{slug}
     *
     * Pulls everything the detail page needs in one pass. Every external call
     * is individually isolated, so a failure in (say) seasonal rates still
     * renders the rest of the page.
     */
    public function show(Request $request, string $slug): View
    {
        $cottage = $this->lodgify->cottageBySlug($slug);
        if (!$cottage) {
            throw new NotFoundHttpException("Cottage not found: {$slug}");
        }

        $arrival   = $request->query('arrival');
        $departure = $request->query('departure');
        $adults    = max(1, (int) $request->query('adults', 2));
        $children  = max(0, (int) $request->query('children', 0));
        $pets      = max(0, (int) $request->query('pets', 0));

        $windows  = [];
        $seasons  = collect();
        $quote    = null;
        $priceFrom = null;
        $degraded = false;

        $windowDays = (int) config('lodgify.availability_window_days', 90);

        try {
            $windows = array_slice(
                $this->lodgify->freeWindows(
                    $cottage,
                    now()->toDateString(),
                    now()->addDays($windowDays)->toDateString()
                ),
                0, 8
            );
        } catch (\Throwable $e) {
            Log::warning('cottage.show windows failed', ['message' => $e->getMessage()]);
            $degraded = true;
        }

        try {
            $seasons = $this->lodgify->seasons($cottage);
            $priceFrom = $seasons->filter(fn ($s) => $s->nightly !== null)->min('nightly');
        } catch (\Throwable $e) {
            Log::warning('cottage.show seasons failed', ['message' => $e->getMessage()]);
        }

        if ($arrival && $departure) {
            try {
                $quote = $this->lodgify->quote($cottage->id, $arrival, $departure, $adults, $children, $pets);
            } catch (\Throwable $e) {
                Log::warning('cottage.show quote failed', ['message' => $e->getMessage()]);
            }
        }

        if (!empty($this->lodgify->lastErrors())) {
            $degraded = true;
        }

        return view('pages.cottage', [
            'cottage'    => $cottage,
            'windows'    => $windows,
            'seasons'    => $seasons,
            'quote'      => $quote,
            'priceFrom'  => $priceFrom,
            'arrival'    => $arrival,
            'departure'  => $departure,
            'adults'     => $adults,
            'children'   => $children,
            'pets'       => $pets,
            'degraded'   => $degraded,
        ]);
    }
}