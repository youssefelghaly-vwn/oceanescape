<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A received Stripe webhook, recorded before it is acted on.
 *
 * See the migration for why insert-before-process on a unique `stripe_event_id` is the
 * whole idempotency strategy: Stripe delivers at-least-once, and a duplicate-key
 * violation on insert is an atomic "already seen" that a check-then-act cannot match.
 */
class StripeWebhookEvent extends Model
{
    public const STATUS_RECEIVED = 'received';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_IGNORED = 'ignored';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'stripe_event_id', 'type', 'api_version', 'payload', 'status', 'booking_payment_id',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function bookingPayment(): BelongsTo
    {
        return $this->belongsTo(BookingPayment::class);
    }

    public function markProcessed(?int $paymentId = null): void
    {
        $this->forceFill([
            'status' => self::STATUS_PROCESSED,
            'processed_at' => now(),
            'error' => null,
            'booking_payment_id' => $paymentId ?? $this->booking_payment_id,
        ])->save();
    }

    /** Signature was valid and the event was ours, but we have no handler for its type. */
    public function markIgnored(string $reason): void
    {
        $this->forceFill([
            'status' => self::STATUS_IGNORED,
            'processed_at' => now(),
            'error' => $reason,
        ])->save();
    }

    public function markFailed(string $error): void
    {
        $this->forceFill([
            'status' => self::STATUS_FAILED,
            'attempts' => $this->attempts + 1,
            // Bounded: a Stripe error body can be long, and this is read in an admin UI.
            'error' => mb_substr($error, 0, 2000),
        ])->save();
    }
}
