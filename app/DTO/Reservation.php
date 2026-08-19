<?php

namespace App\DTO;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A Lodgify reservation, normalised.
 *
 * READ-ONLY BY DESIGN. Lodgify is a channel manager — bookings arrive from
 * Airbnb, Booking.com, the phone and the website — so a local copy would be
 * stale the moment it was written. Everything is fetched and cached briefly.
 *
 * VERIFIED SHAPE (GET /v2/reservations/bookings):
 *   {
 *     "count": null,
 *     "items": [{
 *       "id": 17388658, "user_id": 812665,
 *       "arrival": "2026-09-23", "departure": "2026-09-25",
 *       "property_id": 752301,
 *       "rooms": [{ "room_type_id": 819429, "people": 2,
 *                   "guest_breakdown": {"adults":2,"children":0,"infants":0,"pets":0},
 *                   "key_code": "" }],
 *       "guest": { "name":"...", "email":"...", "phone":"...", "country_code":"CA" },
 *       "status": "Booked",            // Booked | Declined | Open | ...
 *       "source": "Manual", "source_text": "",
 *       "created_at": "2025-12-05T04:32:05", "canceled_at": null,
 *       "is_deleted": false,
 *       "currency_code": "CAD",
 *       "total_amount": 490.2, "amount_paid": 0.0, "amount_due": 490.2,
 *       "subtotals": { "stay":480, "promotions":-50, "fees":0,
 *                      "taxes":60.2, "addons":0, "vat":0 },
 *       "quote": { "policy": { "name":"...", "payments":"...",
 *                              "cancellation":"...", "damage_deposit":"..." } },
 *       "notes": "",
 *       "check_in":  {"time":"15:00:00","initiator":"Manual"},
 *       "check_out": {"time":"11:00:00","initiator":"Manual"}
 *     }]
 *   }
 *
 * TWO THINGS THAT MATTER DOWNSTREAM:
 *   - There is NO reference/confirmation-code field. The id is the reference.
 *   - `guest.email` CAN BE NULL. Such bookings can never be matched to a user
 *     account, so the guest profile will not show them.
 */
class Reservation
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $status,
        public readonly ?string $source,

        public readonly ?int $propertyId,
        public readonly ?string $propertyName,
        public readonly ?int $roomTypeId,

        public readonly ?Carbon $arrival,
        public readonly ?Carbon $departure,
        public readonly ?int $nights,
        public readonly ?string $checkInTime,
        public readonly ?string $checkOutTime,

        public readonly ?string $guestName,
        public readonly ?string $guestEmail,
        public readonly ?string $guestPhone,
        public readonly ?string $guestCountry,

        public readonly int $adults,
        public readonly int $children,
        public readonly int $infants,
        public readonly int $pets,

        public readonly ?float $total,
        public readonly ?float $amountPaid,
        public readonly ?float $amountDue,
        public readonly ?string $currency,

        /** @var array<string, float> stay / promotions / fees / taxes / addons / vat */
        public readonly array $subtotals,

        /** @var array<string, ?string> name / payments / cancellation / damage_deposit */
        public readonly array $policy,

        public readonly ?Carbon $createdAt,
        public readonly ?Carbon $canceledAt,
        public readonly ?string $notes,
        public readonly bool $isDeleted,

        /** @var array<int, array<string, mixed>> */
        public readonly array $rooms = [],
        /** @var array<int, array<string, mixed>> */
        public readonly array $addOns = [],
        /** @var array<int, array<string, mixed>> */
        public readonly array $payments = [],

        /**
         * The untouched Lodgify payload, so the admin detail page can show
         * fields the mapper does not model rather than silently dropping them.
         *
         * @var array<string, mixed>
         */
        public readonly array $raw = [],
    ) {}

    /** Lodgify issues no confirmation code, so the id serves as one. */
    public function reference(): string
    {
        return '#' . $this->id;
    }

    public function guestFirstName(): string
    {
        return Str::before(trim((string) $this->guestName), ' ') ?: 'Guest';
    }

    public function stayLabel(): string
    {
        if (!$this->arrival) {
            return 'Dates unknown';
        }
        $label = $this->arrival->format('M j, Y');
        if ($this->departure) {
            $label .= ' – ' . $this->departure->format('M j, Y');
        }
        return $label;
    }

    /** Adults + children. Infants and pets are counted separately by convention. */
    public function guestCount(): int
    {
        return $this->adults + $this->children;
    }

    public function partyLabel(): string
    {
        $parts = [$this->adults . ' ' . Str::plural('adult', $this->adults)];
        if ($this->children) $parts[] = $this->children . ' ' . Str::plural('child', $this->children);
        if ($this->infants)  $parts[] = $this->infants . ' ' . Str::plural('infant', $this->infants);
        if ($this->pets)     $parts[] = $this->pets . ' ' . Str::plural('pet', $this->pets);
        return implode(', ', $parts);
    }

    public function isCancelled(): bool
    {
        return $this->canceledAt !== null
            || in_array(strtolower((string) $this->status), ['declined', 'cancelled', 'canceled'], true);
    }

    public function isPast(): bool
    {
        return $this->departure !== null && $this->departure->isPast();
    }

    public function isCurrent(): bool
    {
        return $this->arrival !== null
            && $this->departure !== null
            && $this->arrival->startOfDay()->isPast()
            && $this->departure->endOfDay()->isFuture();
    }

    public function isUpcoming(): bool
    {
        return $this->arrival !== null && $this->arrival->startOfDay()->isFuture();
    }

    /** cancelled | current | upcoming | past — cancelled wins over dates. */
    public function timeframe(): string
    {
        if ($this->isCancelled()) return 'cancelled';
        if ($this->isCurrent())   return 'current';
        if ($this->isUpcoming())  return 'upcoming';
        return 'past';
    }

    /**
     * A booking with no email can never be matched to a user account.
     * Surfaced in admin so it's clear why a guest can't see their stay.
     */
    public function isMatchable(): bool
    {
        return $this->guestEmail !== null && trim($this->guestEmail) !== '';
    }

    public function statusClasses(): string
    {
        return match (strtolower((string) $this->status)) {
            'booked'                 => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            'open', 'tentative'      => 'bg-amber-50 text-amber-800 ring-amber-200',
            'declined', 'cancelled',
            'canceled'               => 'bg-red-50 text-red-800 ring-red-200',
            default                  => 'bg-fog-100 text-tide-600 ring-fog-300',
        };
    }

    public function money(?float $value): string
    {
        if ($value === null) {
            return '—';
        }
        $symbol = match ($this->currency) {
            'CAD'   => 'CA$',
            'USD'   => '$',
            default => ($this->currency ?? '') . ' ',
        };
        return $symbol . number_format($value, 2);
    }

    /** Non-zero subtotal lines, ready to itemise. */
    public function subtotalLines(): array
    {
        $labels = [
            'stay'       => 'Accommodation',
            'promotions' => 'Discount',
            'fees'       => 'Fees',
            'addons'     => 'Extras',
            'taxes'      => 'Taxes',
            'vat'        => 'VAT',
        ];

        $lines = [];
        foreach ($labels as $key => $label) {
            $value = $this->subtotals[$key] ?? 0.0;
            if (abs($value) > 0.001) {
                $lines[] = ['label' => $label, 'value' => $value];
            }
        }
        return $lines;
    }

    public function checkInLabel(): ?string
    {
        return $this->timeLabel($this->checkInTime);
    }

    public function checkOutLabel(): ?string
    {
        return $this->timeLabel($this->checkOutTime);
    }

    /** "15:00:00" -> "3:00 PM" */
    protected function timeLabel(?string $time): ?string
    {
        if (!$time) {
            return null;
        }
        try {
            return Carbon::createFromFormat('H:i:s', $time)->format('g:i A');
        } catch (\Throwable) {
            return $time;
        }
    }
}