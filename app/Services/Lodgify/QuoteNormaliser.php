<?php

namespace App\Services\Lodgify;

use App\DTO\Cottage;
use Illuminate\Support\Carbon;

/**
 * Normalises a Lodgify quote into the single shape the rest of the app uses.
 *
 * WHY THIS IS A SERVICE AND NOT TWO CONTROLLER METHODS
 *
 * This logic used to live as parseV2Quote()/parsePublicQuote() inside RateController,
 * where it served the price shown in the booking panel. The direct-payment flow needs the
 * SAME numbers to decide what to charge. Two copies of "how much does this stay cost"
 * is the one duplication this feature genuinely cannot afford: the moment they drift, the
 * guest is charged something other than what they were shown.
 *
 * So there is exactly one implementation, and both the display path
 * (RateController::quote) and the money path (BookingCreator via QuoteReader) go through
 * it.
 *
 * TWO UPSTREAM SHAPES, ONE OUTPUT. Lodgify's authenticated /v2/quote and its public
 * checkout price endpoint have entirely different payloads; `_source` on the repository
 * result says which answered, and `source` on the output preserves that.
 */
class QuoteNormaliser
{
    /**
     * @param  array<string, mixed>  $raw  as returned by LodgifyRepository::quote()
     * @return array<string, mixed>
     */
    public function normalise(array $raw, ?Cottage $cottage = null): array
    {
        return ($raw['_source'] ?? null) === 'v2'
            ? $this->fromV2($raw, $cottage)
            : $this->fromPublic($raw, $cottage);
    }

    /**
     * The AUTHENTICATED /v2/quote payload.
     *
     * VERIFIED SHAPE:
     *   {
     *     "total_including_vat": 600.0, "currency_code": "USD",
     *     "room_types": [ { "room_type_id":903506, "subtotal":600.0,
     *       "price_types": [
     *         { "type":0, "description":"Room rate", "prices":[{"amount":600.0}] },
     *         { "type":1, "description":"Promotion", "is_negative":true, ... },
     *         { "type":2, "description":"Fees",  ... },
     *         { "type":4, "description":"Taxes", ... }
     *       ] } ],
     *     "add_ons": [], "add_ons_subtotal": 0.0,
     *     "scheduled_payments": [ {"date_due":"On agreement","amount":300.0} ],
     *     "security_deposit": 0.0,
     *     "cancellation_policy_text": "...", "security_deposit_text": "..."
     *   }
     *
     * `type` is the grouping key: 0 room rate, 1 promotion, 2 fees, 4 taxes. An
     * unrecognised type falls into fees rather than being dropped — a charge we cannot
     * classify must still appear in the total the guest sees.
     *
     * @return array<string, mixed>
     */
    protected function fromV2(array $raw, ?Cottage $cottage): array
    {
        $nights = $this->nightsBetween($raw['date_arrival'] ?? null, $raw['date_departure'] ?? null);

        $rental = 0.0;
        $fees = [];
        $taxes = [];
        $promotions = [];

        foreach ((array) ($raw['room_types'] ?? []) as $roomType) {
            foreach ((array) ($roomType['price_types'] ?? []) as $group) {
                $type = (int) ($group['type'] ?? -1);
                $negative = (bool) ($group['is_negative'] ?? false);

                foreach ((array) ($group['prices'] ?? []) as $line) {
                    $amount = (float) ($line['amount'] ?? 0);

                    if ($amount === 0.0) {
                        continue;
                    }

                    $entry = [
                        'name' => $line['description'] ?? $group['description'] ?? 'Charge',
                        'value' => $negative ? -abs($amount) : $amount,
                    ];

                    match ($type) {
                        0 => $rental += $amount,
                        1 => $promotions[] = $entry,
                        2 => $fees[] = $entry,
                        4 => $taxes[] = $entry,
                        default => $fees[] = $entry,
                    };
                }
            }
        }

        $addOns = collect((array) ($raw['add_ons'] ?? []))->map(fn ($a) => [
            'name' => $a['description'] ?? $a['name'] ?? 'Extra',
            'value' => (float) ($a['amount'] ?? $a['subtotal'] ?? 0),
        ])->all();

        $schedule = collect((array) ($raw['scheduled_payments'] ?? []))->map(fn ($p) => [
            'name' => $p['date_due'] ?? 'Payment',
            'amount' => (float) ($p['amount'] ?? 0),
            'status' => $p['status'] ?? null,
            // Preserved because DepositPolicy prefers the instalment Lodgify marks
            // current over merely the first one.
            'is_current' => (bool) ($p['is_current'] ?? false),
        ])->all();

        return [
            'source' => 'v2',
            'currency' => $raw['currency_code'] ?? $cottage?->currency ?? 'USD',
            'nights' => $nights,
            'nightly' => $nights ? round($rental / $nights, 2) : null,
            'rental' => $rental,
            'fees' => $fees,
            'taxes' => $taxes,
            'promotions' => $promotions,
            'lodgify_addons' => $addOns,
            'addons_subtotal' => (float) ($raw['add_ons_subtotal'] ?? 0),
            'total' => (float) ($raw['total_including_vat'] ?? $raw['amount_gross'] ?? 0),
            'due_now' => collect($schedule)->firstWhere('is_current', true)['amount'] ?? null,
            'schedule' => $schedule,
            'security_deposit' => (float) ($raw['security_deposit'] ?? 0),
            'security_deposit_text' => $raw['security_deposit_text'] ?? null,
            'cancellation_policy' => $raw['cancellation_policy_text'] ?? null,
        ];
    }

    /**
     * The PUBLIC checkout price payload — the Cloudflare-guarded fallback shape.
     *
     * Carries no add-ons, security deposit or cancellation policy, so those normalise to
     * empty rather than being invented.
     *
     * @return array<string, mixed>
     */
    protected function fromPublic(array $raw, ?Cottage $cottage): array
    {
        $schedule = collect(data_get($raw, 'scheduledPayments.payments', []))->map(fn ($p) => [
            'name' => $p['name'] ?? 'Payment',
            'amount' => (float) ($p['amount'] ?? 0),
            'status' => $p['status'] ?? null,
            'is_current' => (bool) ($p['isCurrent'] ?? $p['is_current'] ?? false),
        ])->all();

        return [
            'source' => 'public',
            'currency' => $raw['currencyCode'] ?? $cottage?->currency ?? 'USD',
            'nights' => data_get($raw, 'rentalPrice.nights'),
            'nightly' => data_get($raw, 'rentalPrice.nightlyPrice'),
            'rental' => data_get($raw, 'rentalPrice.total'),
            'fees' => data_get($raw, 'fees.details', []),
            'taxes' => data_get($raw, 'localTaxes.details', []),
            'promotions' => data_get($raw, 'rentalPrice.promotions', []),
            'lodgify_addons' => [],
            'addons_subtotal' => 0.0,
            'total' => data_get($raw, 'totalPrice.total'),
            'due_now' => data_get($raw, 'totalPrice.amountToPay'),
            'schedule' => $schedule,
            'security_deposit' => 0.0,
            'security_deposit_text' => null,
            'cancellation_policy' => null,
        ];
    }

    protected function nightsBetween(mixed $arrival, mixed $departure): ?int
    {
        if (blank($arrival) || blank($departure)) {
            return null;
        }

        try {
            return Carbon::parse((string) $arrival)->diffInDays(Carbon::parse((string) $departure)) ?: null;
        } catch (\Throwable) {
            return null;
        }
    }
}
