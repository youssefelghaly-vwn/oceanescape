<?php

namespace App\Services\Lodgify;

use App\DTO\Cottage;
use Illuminate\Support\Carbon;

/**
 * Builds the handoff URL into Lodgify's hosted checkout.
 *
 * WHY WE HAND OFF RATHER THAN BUILD OUR OWN
 * Lodgify's checkout already does exactly what this business wants: it collects
 * dates, add-ons and contact details, then submits a REQUEST — "No payment will
 * be processed until your reservation is confirmed" — which the owner approves
 * before Stripe takes the deposit. Rebuilding that would mean an unverified
 * booking write, PCI scope, and reconciling two systems, to arrive at the same
 * guest experience.
 *
 * VERIFIED URL SHAPE (captured from the live account):
 *   https://checkout.lodgify.com/{slug}/{propertyId}/addons
 *       ?currency=CAD
 *       &arrival=2026-10-21
 *       &departure=2026-10-23
 *       &adults=1
 *       &addons=155688-1,155689-3
 *
 * Later steps are /contact, /pay and /en/{slug}/{propertyId}/confirmation, all
 * driven by Lodgify. We only ever link to the first one.
 *
 * IMPORTANT OPERATIONAL NOTE
 * The Stripe configuration is attached to Lodgify WEBSITE id 623105. Taking that
 * website OFFLINE was tested and does not break this checkout — but the website
 * record must not be DELETED, or the payment binding (and possibly the checkout
 * itself) goes with it.
 */
class LodgifyCheckout
{
    public function __construct(protected LodgifyRepository $repository) {}

    /**
     * @param array<int, array{id: string|int, quantity: int}> $addons
     */
    public function urlFor(
        Cottage $cottage,
        string $arrival,
        string $departure,
        int $adults = 2,
        int $children = 0,
        int $pets = 0,
        array $addons = [],
    ): string {
        $base = rtrim((string) config('lodgify.checkout_base_url', 'https://checkout.lodgify.com'), '/');
        $slug = trim((string) config('lodgify.checkout_slug'), '/');

        $query = [
            'currency'  => strtoupper((string) config('lodgify.checkout_currency', 'CAD')),
            'arrival'   => $arrival,
            'departure' => $departure,
            'adults'    => max(1, $adults),
        ];

        /*
         * Lodgify's checkout takes `adults`; children and pets are not part of
         * the URL contract we captured, so they are appended only when set and
         * treated as best-effort. The guest can adjust on Lodgify's own Dates
         * step if they are ignored.
         */
        if ($children > 0) {
            $query['children'] = $children;
        }
        if ($pets > 0) {
            $query['pets'] = $pets;
        }

        $addonParam = $this->formatAddons($addons);
        if ($addonParam !== null) {
            $query['addons'] = $addonParam;
        }

        // Lodgify expects a literal comma between add-on pairs, so build the
        // query string manually rather than letting http_build_query escape it.
        $pairs = [];
        foreach ($query as $key => $value) {
            $pairs[] = $key . '=' . ($key === 'addons' ? $value : rawurlencode((string) $value));
        }

        return "{$base}/{$slug}/{$cottage->id}/addons?" . implode('&', $pairs);
    }

    /**
     * "155688-1,155689-3"
     *
     * CRITICAL: the quantity here is the guest's chosen quantity ONLY. Lodgify
     * applies the add-on's own frequency itself — a PerNight add-on with
     * quantity 3 on a 2-night stay was observed billing 3 × 2. Multiplying by
     * nights on our side would double-charge.
     */
    protected function formatAddons(array $addons): ?string
    {
        $pairs = collect($addons)
            ->filter(fn ($a) => !empty($a['id']) && (int) ($a['quantity'] ?? 0) > 0)
            ->map(fn ($a) => $a['id'] . '-' . max(1, (int) $a['quantity']))
            ->values();

        return $pairs->isEmpty() ? null : $pairs->implode(',');
    }

    /** Is the handoff configured well enough to use? */
    public function isConfigured(): bool
    {
        return trim((string) config('lodgify.checkout_slug')) !== '';
    }
}
