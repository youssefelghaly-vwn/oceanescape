<?php

namespace App\Http\Controllers;

use App\Services\Lodgify\LodgifyRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class AvailabilityController extends Controller
{
    public function __construct(protected LodgifyRepository $lodgify) {}

    /**
     * JSON endpoint consumed by the calendar widget.
     * Never 500s: on upstream failure returns 200 with an empty day map and
     * `degraded: true` so the calendar still renders.
     */
    public function month(Request $request): JsonResponse
    {
        $request->validate(['start' => ['required', 'date_format:Y-m-d']]);
        $start = (string) $request->query('start');

        try {
            $days   = $this->lodgify->aggregateAvailability($start);
            $errors = $this->lodgify->lastErrors();

            return response()->json([
                'start'    => $start,
                'days'     => $days->mapWithKeys(fn ($d) => [$d->date => $d->toArray()])->all(),
                'degraded' => !empty($errors),
                'notes'    => app()->environment(['local', 'staging']) ? $errors : [],
            ])->header('Cache-Control', 'public, max-age=60');
        } catch (\Throwable $e) {
            Log::error('availability.month failed', [
                'start' => $start, 'exception' => $e::class, 'message' => $e->getMessage(),
            ]);
            return response()->json([
                'start' => $start, 'days' => [], 'degraded' => true,
                'notes' => app()->environment(['local', 'staging']) ? [$e->getMessage()] : [],
            ], 200);
        }
    }

    /**
     * Search results, with a three-tier fallback so the page is never a
     * dead end:
     *
     *   TIER 1  exact    - cottages free for exactly the requested range
     *   TIER 2  nearby   - same LENGTH of stay, shifted +/- N days
     *   TIER 3  alternate- closest bookable window of ANY length
     *
     * Tier 3 matters for long requests: shifting a 22-night block rarely
     * finds a match, but "Sep 7-10, 4 nights" is a genuinely useful offer.
     */
    /**
     * Search results, styled as a stacked list (flight-search style).
     *
     * Dates are OPTIONAL. Without them the page becomes a browsable list of
     * every cottage with its next open windows — a useful landing page in its
     * own right, and a better destination than an empty form for anyone who
     * arrives at /availability from a nav link.
     *
     * With dates, results degrade through three tiers so the page is never a
     * dead end:
     *   1 exact      free for precisely the requested range
     *   2 nearby     same LENGTH of stay, shifted +/- N days
     *   3 alternate  closest bookable window of ANY length
     */
    public function search(Request $request): View
    {
        $validated = $request->validate([
            'arrival'   => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'departure' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after:arrival'],
            'adults'    => ['sometimes', 'integer', 'min:1', 'max:20'],
            'children'  => ['sometimes', 'integer', 'min:0', 'max:20'],
            'pets'      => ['sometimes', 'integer', 'min:0', 'max:10'],
        ]);

        $arrival   = $validated['arrival']   ?? null;
        $departure = $validated['departure'] ?? null;
        $adults    = (int) ($validated['adults']   ?? 2);
        $children  = (int) ($validated['children'] ?? 0);
        $pets      = (int) ($validated['pets']     ?? 0);
        $guests    = $adults + $children;

        $hasDates = $arrival !== null && $departure !== null;

        $fitsParty = fn ($c) => ($c->maxGuests === 0 || $c->maxGuests >= $guests)
                             && ($pets === 0 || $c->petFriendly);

        $exact        = collect();
        $nearby       = collect();
        $alternatives = collect();
        $browse       = collect();
        $degraded     = false;

        try {
            if (!$hasDates) {
                // No dates: browse everything, each with its next open windows.
                $browse = $this->lodgify->cottagesWithOpenings(windowsPerCottage: 3)
                    ->filter(fn ($l) => $fitsParty($l['cottage']))
                    ->values();
            } else {
                $exact = $this->lodgify->cottagesFreeFor($arrival, $departure)
                    ->filter($fitsParty)
                    ->values();

                $seen = $exact->pluck('id')->all();

                $nearby = $this->lodgify
                    ->nearbyMatches($arrival, $departure, (int) config('lodgify.nearby_window_days', 14))
                    ->reject(fn ($m) => in_array($m['cottage']->id, $seen, true))
                    ->filter(fn ($m) => $fitsParty($m['cottage']))
                    ->values();

                $seen = array_merge($seen, $nearby->pluck('cottage.id')->all());

                if ($exact->isEmpty() || $nearby->isEmpty()) {
                    $alternatives = $this->lodgify
                        ->alternativeStays($arrival, $departure, (int) config('lodgify.alternative_window_days', 30))
                        ->reject(fn ($m) => in_array($m['cottage']->id, $seen, true))
                        ->filter(fn ($m) => $fitsParty($m['cottage']))
                        ->values();
                }
            }

            $degraded = !empty($this->lodgify->lastErrors());
        } catch (\Throwable $e) {
            Log::error('availability.search failed', [
                'exception' => $e::class, 'message' => $e->getMessage(),
            ]);
            $degraded = true;
        }

        return view('pages.availability-results', [
            'hasDates'     => $hasDates,
            'arrival'      => $arrival,
            'departure'    => $departure,
            'nights'       => $hasDates
                                ? Carbon::parse($arrival)->diffInDays(Carbon::parse($departure))
                                : null,
            'adults'       => $adults,
            'children'     => $children,
            'pets'         => $pets,
            'guests'       => $guests,
            'exact'        => $exact,
            'nearby'       => $nearby,
            'alternatives' => $alternatives,
            'browse'       => $browse,
            'degraded'     => $degraded,
        ]);
    }
}