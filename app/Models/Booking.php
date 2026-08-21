<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Exceptions\IllegalBookingTransition;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A booking taken on our own site, paid through Stripe, mirrored into Lodgify.
 *
 * LODGIFY REMAINS THE SYSTEM OF RECORD FOR THE RESERVATION. This row owns the money
 * lifecycle; `lodgify_status` is only a cache of what Lodgify last told us. Anything
 * needing authoritative reservation state reads ReservationRepository.
 */
class Booking extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * Explicit allowlist, NOT `$guarded = []`.
     *
     * Everything that decides state or money is deliberately absent: status,
     * lodgify_booking_id, lodgify_status, booked_at, the *_cents columns, and the sync
     * bookkeeping. Those are set by the services that own them, never from a request —
     * which is what stops a crafted form from marking its own booking paid.
     */
    protected $fillable = [
        'cottage_id', 'cottage_name', 'room_type_id',
        'arrival', 'departure', 'nights',
        'adults', 'children', 'infants', 'pets',
        'guest_name', 'guest_email', 'guest_phone', 'guest_country', 'guest_notes',
        'user_id', 'checkout_intent_id',
        'utm_source', 'utm_medium', 'utm_campaign',
        'ip_address', 'user_agent', 'session_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => BookingStatus::class,
            'arrival' => 'date',
            'departure' => 'date',
            'requires_full_payment' => 'boolean',
            'quote_snapshot' => 'array',
            'payment_schedule' => 'array',
            'addons' => 'array',
            'lodgify_created_at' => 'datetime',
            'booked_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $booking) {
            $booking->reference ??= self::generateReference();
        });
    }

    /**
     * Short reference safe to read aloud: BK-7K2QMD.
     *
     * Same alphabet treatment as BusinessStayRequest::generateReference() — vowels and
     * lookalike characters substituted out, checked against soft-deleted rows too so a
     * reference is never reissued.
     */
    public static function generateReference(): string
    {
        do {
            $raw = strtoupper(Str::random(8));
            $body = substr(strtr($raw, ['O' => 'X', 'I' => 'Y', 'L' => 'Z', '0' => '2', '1' => '3']), 0, 6);
            $code = 'BK-'.$body;
        } while (self::withTrashed()->where('reference', $code)->exists());

        return $code;
    }

    // ========================================================== relations

    public function payments(): HasMany
    {
        return $this->hasMany(BookingPayment::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(BookingAuditLog::class)->latest('created_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function checkoutIntent(): BelongsTo
    {
        return $this->belongsTo(CheckoutIntent::class);
    }

    public function paymentOfType(PaymentType $type): ?BookingPayment
    {
        return $this->payments->firstWhere('type', $type)
            ?? $this->payments()->where('type', $type->value)->first();
    }

    public function deposit(): ?BookingPayment
    {
        return $this->paymentOfType(PaymentType::Deposit) ?? $this->paymentOfType(PaymentType::Full);
    }

    public function balance(): ?BookingPayment
    {
        return $this->paymentOfType(PaymentType::Balance);
    }

    // ============================================================== money

    public function total(): Money
    {
        return Money::fromCents((int) $this->total_cents, $this->currency);
    }

    public function depositAmount(): Money
    {
        return Money::fromCents((int) $this->deposit_cents, $this->currency);
    }

    public function balanceAmount(): Money
    {
        return Money::fromCents((int) $this->balance_cents, $this->currency);
    }

    /** Sum of everything actually captured, from our own payment rows. */
    public function amountPaid(): Money
    {
        $cents = $this->payments
            ->where('status', PaymentStatus::Paid)
            ->sum(fn (BookingPayment $p) => (int) $p->amount_received_cents);

        return Money::fromCents((int) $cents, $this->currency);
    }

    public function amountOutstanding(): Money
    {
        return $this->total()->minus(
            Money::fromCents(min((int) $this->total_cents, $this->amountPaid()->cents), $this->currency)
        );
    }

    // ==================================================== state machine

    /**
     * Move to a new status, atomically, refusing illegal transitions.
     *
     * COMPARE-AND-SWAP, not read-then-write. The UPDATE is guarded on the status we
     * believe we are in, so when two Stripe webhook deliveries race, exactly one wins
     * and the loser gets `false` back instead of both applying the same side effects.
     * A plain `$this->update(['status' => ...])` would let both through.
     *
     * @param  array<string, mixed>  $extra  additional columns to set in the same write
     * @return bool true if this call performed the transition
     */
    public function transitionTo(BookingStatus $to, array $extra = []): bool
    {
        $from = $this->status;

        if (! $from->canTransitionTo($to)) {
            throw IllegalBookingTransition::between($from, $to, $this->getKey());
        }

        if ($from === $to && $extra === []) {
            return false;
        }

        $applied = static::query()
            ->whereKey($this->getKey())
            ->where('status', $from->value)
            ->update($extra + ['status' => $to->value, 'updated_at' => now()]);

        if ($applied === 1) {
            $this->forceFill($extra + ['status' => $to])->syncOriginal();

            return true;
        }

        // Somebody else moved it first. Refresh so the caller sees reality.
        $this->refresh();

        return false;
    }

    // ============================================================ scopes

    public function scopeStatus(Builder $q, BookingStatus|string|null $status): Builder
    {
        if ($status === null || $status === 'all') {
            return $q;
        }

        return $q->where('status', $status instanceof BookingStatus ? $status->value : $status);
    }

    /**
     * Bookings whose balance should be requested now.
     *
     * Confirmed, still owing, arriving inside the lead window, and with no balance
     * payment row yet — the `whereDoesntHave` is what makes the scheduler idempotent,
     * so a second run in the same hour cannot send a second link.
     */
    public function scopeDueForBalanceLink(Builder $q, ?int $leadDays = null): Builder
    {
        $leadDays ??= (int) config('booking.balance_lead_days', 30);

        return $q->whereIn('status', [BookingStatus::DepositPaid->value])
            ->where('balance_cents', '>', 0)
            ->whereDate('arrival', '<=', Carbon::today()->addDays($leadDays))
            ->whereDate('arrival', '>=', Carbon::today())
            ->whereDoesntHave('payments', fn (Builder $p) => $p->where('type', PaymentType::Balance->value));
    }

    /**
     * Bookings sitting `Open` in Lodgify on an unpaid, expired deposit link.
     *
     * These are actively harmful: they hold nothing in Lodgify but they do occupy our
     * records, and a guest may still believe they have a booking. Released by
     * ExpireStalePayments.
     */
    public function scopeStaleAwaitingDeposit(Builder $q): Builder
    {
        return $q->where('status', BookingStatus::AwaitingDeposit->value)
            ->whereHas('payments', fn (Builder $p) => $p
                ->whereIn('type', [PaymentType::Deposit->value, PaymentType::Full->value])
                /*
                 * Expired is included ON PURPOSE, alongside the not-yet-settled states.
                 *
                 * ExpireStalePayments marks lapsed payments Expired and THEN releases their
                 * reservations. Matching only pending/link_sent meant the first step made the
                 * booking invisible to the second, so nothing was ever released — a bug the
                 * sweeper test caught. Accepting Expired also makes the command safe to
                 * re-run: a reservation left behind by a half-finished sweep is still found
                 * on the next pass.
                 */
                ->whereIn('status', [
                    PaymentStatus::Pending->value,
                    PaymentStatus::LinkSent->value,
                    PaymentStatus::Expired->value,
                ])
                ->whereNotNull('link_expires_at')
                ->where('link_expires_at', '<', now()));
    }

    public function scopeNeedsAttention(Builder $q): Builder
    {
        return $q->where(fn (Builder $q) => $q
            ->where('status', BookingStatus::Failed->value)
            ->orWhereNotNull('lodgify_sync_error'));
    }

    // ========================================================= accessors

    public function getStayLabelAttribute(): string
    {
        return $this->arrival->format('M j').' – '.$this->departure->format('M j, Y');
    }

    public function getPartyLabelAttribute(): string
    {
        $parts = [$this->adults.' '.Str::plural('adult', $this->adults)];

        if ($this->children) {
            $parts[] = $this->children.' '.Str::plural('child', $this->children);
        }
        if ($this->infants) {
            $parts[] = $this->infants.' '.Str::plural('infant', $this->infants);
        }
        if ($this->pets) {
            $parts[] = $this->pets.' '.Str::plural('pet', $this->pets);
        }

        return implode(' · ', $parts);
    }

    /** First name only, for guest-facing copy. */
    public function getGuestFirstNameAttribute(): string
    {
        return Str::before(trim((string) $this->guest_name), ' ') ?: 'there';
    }
}
