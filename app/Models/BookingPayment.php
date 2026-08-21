<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * One requested payment against a booking: the deposit, the balance, or a single
 * full payment.
 *
 * The schema enforces one row per (booking, type) — see the migration — so this model
 * never creates a second deposit. Requesting a link again reuses this row.
 */
class BookingPayment extends Model
{
    use HasFactory;

    /**
     * Explicit allowlist. Note what is NOT here: status, amount_cents,
     * amount_received_cents, every stripe_* id, paid_at, and idempotency_key. Those are
     * assigned by the payment services from server-side data only. An amount that a
     * request could influence is an amount a guest could choose.
     */
    protected $fillable = [
        'booking_id', 'type',
    ];

    protected function casts(): array
    {
        return [
            'type' => PaymentType::class,
            'status' => PaymentStatus::class,
            'link_sent_at' => 'datetime',
            'link_expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'expired_at' => 'datetime',
            'refunded_at' => 'datetime',
            'recorded_in_lodgify_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $payment) {
            $payment->reference ??= 'PAY-'.strtoupper(Str::random(6));

            /*
             * 64 hex chars of randomness in the URL path. Not the id: payment URLs get
             * forwarded, logged by mail clients, and land in browser history, so the
             * path must not be guessable or enumerable from a neighbouring booking.
             */
            $payment->token ??= bin2hex(random_bytes(32));
        });
    }

    // ========================================================== relations

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(StripeWebhookEvent::class);
    }

    // ============================================================== money

    public function amount(): Money
    {
        return Money::fromCents((int) $this->amount_cents, $this->currency);
    }

    public function amountReceived(): ?Money
    {
        return $this->amount_received_cents === null
            ? null
            : Money::fromCents((int) $this->amount_received_cents, $this->currency);
    }

    /**
     * Did Stripe capture exactly what we asked for?
     *
     * Checked before a booking is confirmed. A mismatch means either the session was
     * built wrong or something tampered with it, and both need a human rather than a
     * confirmed reservation.
     */
    public function amountMatches(): bool
    {
        return $this->amount_received_cents !== null
            && (int) $this->amount_received_cents === (int) $this->amount_cents;
    }

    // =============================================================== links

    /**
     * The guest-facing payment URL.
     *
     * A SIGNED, EXPIRING route on our own domain — never the raw Stripe session URL.
     * Three reasons: a Stripe URL cannot be revoked once emailed, it carries Stripe's own
     * much longer expiry, and routing through our own page lets us re-create an expired
     * session instead of showing the guest a dead Stripe error.
     */
    public function payUrl(): string
    {
        return URL::temporarySignedRoute(
            'booking.pay',
            $this->link_expires_at ?? now()->addHours((int) config('booking.deposit_link_ttl_hours', 48)),
            ['token' => $this->token],
        );
    }

    public function isExpired(): bool
    {
        return $this->link_expires_at !== null && $this->link_expires_at->isPast();
    }

    /** Payable means: not settled, not expired, and the booking is still live. */
    public function isPayable(): bool
    {
        return $this->status->isPayable() && ! $this->isExpired();
    }

    // ============================================================= scopes

    public function scopeOfType(Builder $q, PaymentType $type): Builder
    {
        return $q->where('type', $type->value);
    }

    public function scopeUnpaid(Builder $q): Builder
    {
        return $q->whereIn('status', [PaymentStatus::Pending->value, PaymentStatus::LinkSent->value]);
    }

    /** Links that lapsed without payment, for the expiry sweeper. */
    public function scopeLapsed(Builder $q): Builder
    {
        return $q->unpaid()
            ->whereNotNull('link_expires_at')
            ->where('link_expires_at', '<', now());
    }

    /** Paid but not yet reported back to Lodgify — the best-effort retry queue. */
    public function scopePendingLodgifyRecord(Builder $q): Builder
    {
        return $q->where('status', PaymentStatus::Paid->value)
            ->whereNull('recorded_in_lodgify_at');
    }

    public function getDescriptionAttribute(): string
    {
        return match ($this->type) {
            PaymentType::Deposit => 'Deposit for '.$this->booking->cottage_name,
            PaymentType::Balance => 'Balance for '.$this->booking->cottage_name,
            PaymentType::Full => 'Stay at '.$this->booking->cottage_name,
        };
    }
}
