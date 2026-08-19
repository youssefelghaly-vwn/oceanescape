<?php

namespace App\Http\Controllers;

use App\Models\CheckoutIntent;
use App\Services\Lodgify\LodgifyCheckout;
use App\Services\Lodgify\LodgifyRepository;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BookingRedirectController extends Controller
{
    public function __construct(
        protected LodgifyRepository $lodgify,
        protected LodgifyCheckout $checkout,
    ) {}

    /**
     * GET /book/{slug}
     *
     * Records the intent, then hands the guest to Lodgify's checkout.
     *
     * Availability is re-checked here rather than trusted from the page the
     * guest was looking at. Lodgify would reject an unavailable stay anyway, but
     * bouncing them back to our cottage page with an explanation is a much better
     * experience than a dead end on someone else's domain.
     */
    public function __invoke(Request $request, string $slug): RedirectResponse
    {
        $validated = $request->validate([
            'arrival'   => ['required', 'date_format:Y-m-d'],
            'departure' => ['required', 'date_format:Y-m-d', 'after:arrival'],
            'adults'    => ['sometimes', 'integer', 'min:1', 'max:20'],
            'children'  => ['sometimes', 'integer', 'min:0', 'max:20'],
            'pets'      => ['sometimes', 'integer', 'min:0', 'max:10'],
            // "155688-1,155689-3"
            'addons'    => ['sometimes', 'nullable', 'string', 'max:500'],
            'total'     => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

        $cottage = $this->lodgify->cottageBySlug($slug);
        if (!$cottage) {
            throw new NotFoundHttpException("Cottage not found: {$slug}");
        }

        if (!$this->checkout->isConfigured()) {
            Log::error('Lodgify checkout slug is not configured; cannot redirect');
            return redirect()
                ->route('cottage.show', ['slug' => $slug])
                ->with('checkout_error', 'Online booking is briefly unavailable — please call us on 902-398-1020.');
        }

        $arrival   = $validated['arrival'];
        $departure = $validated['departure'];
        $adults    = (int) ($validated['adults'] ?? 2);
        $children  = (int) ($validated['children'] ?? 0);
        $pets      = (int) ($validated['pets'] ?? 0);
        $addons    = $this->parseAddons($validated['addons'] ?? '');

        // --- still bookable? ---
        try {
            $free = $this->lodgify
                ->cottagesFreeFor($arrival, $departure)
                ->contains(fn ($c) => $c->id === $cottage->id);

            if (!$free) {
                return redirect()
                    ->route('cottage.show', [
                        'slug' => $slug, 'arrival' => $arrival, 'departure' => $departure,
                    ])
                    ->with('checkout_error', 'Those dates were taken while you were deciding — here is what is still open.');
            }
        } catch (\Throwable $e) {
            /*
             * A failed availability check must not block the booking. Lodgify
             * validates again at checkout, so the worst case is the guest being
             * told there by an authoritative source rather than a guess here.
             */
            Log::warning('Availability re-check failed before redirect; continuing', [
                'cottage' => $cottage->id, 'message' => $e->getMessage(),
            ]);
        }

        $url = $this->checkout->urlFor(
            $cottage, $arrival, $departure, $adults, $children, $pets, $addons
        );

        // --- record the intent (never fatal) ---
        try {
            CheckoutIntent::create([
                'cottage_id'   => $cottage->id,
                'cottage_name' => $cottage->name,
                'arrival'      => $arrival,
                'departure'    => $departure,
                'nights'       => Carbon::parse($arrival)->diffInDays(Carbon::parse($departure)),
                'adults'       => $adults,
                'children'     => $children,
                'pets'         => $pets,
                'quoted_total' => $validated['total'] ?? null,
                'currency'     => strtoupper((string) config('lodgify.checkout_currency', 'CAD')),
                'addons'       => $addons,
                'redirect_url' => $url,
                'status'       => 'redirected',
                'referrer'     => substr((string) $request->headers->get('referer'), 0, 512) ?: null,
                'utm_source'   => $request->query('utm_source'),
                'utm_medium'   => $request->query('utm_medium'),
                'utm_campaign' => $request->query('utm_campaign'),
                'ip_address'   => $request->ip(),
                'user_agent'   => substr((string) $request->userAgent(), 0, 512),
                'session_id'   => $request->hasSession() ? $request->session()->getId() : null,
            ]);
        } catch (\Throwable $e) {
            // Analytics are not worth losing a booking over.
            Log::error('Could not record checkout intent', [
                'cottage' => $cottage->id, 'message' => $e->getMessage(),
            ]);
        }

        return redirect()->away($url);
    }

    /**
     * "155688-1,155689-3" -> [['id' => '155688', 'quantity' => 1], ...]
     *
     * @return array<int, array{id: string, quantity: int}>
     */
    protected function parseAddons(string $raw): array
    {
        return collect(explode(',', $raw))
            ->map(function ($pair) {
                $parts = explode('-', trim($pair));
                $id    = trim($parts[0] ?? '');
                if ($id === '') {
                    return null;
                }
                return ['id' => $id, 'quantity' => max(1, (int) ($parts[1] ?? 1))];
            })
            ->filter()
            ->values()
            ->all();
    }
}
