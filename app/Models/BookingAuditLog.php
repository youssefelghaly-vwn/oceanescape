<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Append-only audit row. Written by App\Services\Booking\BookingAuditor.
 *
 * IMMUTABLE BY CONSTRUCTION: `$timestamps = false` with only `created_at` in the schema,
 * and the model refuses updates and deletes outright. An audit trail that can be edited
 * is not an audit trail.
 */
class BookingAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'booking_id', 'booking_payment_id', 'event',
        'from_status', 'to_status', 'actor_type', 'actor_id',
        'context', 'ip_address', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Audit log rows are immutable.'));
        static::deleting(fn () => throw new \LogicException('Audit log rows cannot be deleted.'));
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function bookingPayment(): BelongsTo
    {
        return $this->belongsTo(BookingPayment::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    // ============================================================== scopes

    /**
     * The dotted prefix an event belongs to: booking / payment / stripe / lodgify / mail.
     *
     * Filtering by prefix rather than by an enumerated list of event names, for the same
     * reason BookingAuditor mirrors by prefix: a new `payment.*` event has to appear in the
     * right filter without anyone remembering to register it here.
     */
    public function scopeGroup(Builder $q, ?string $group): Builder
    {
        return filled($group) && $group !== 'all'
            ? $q->where('event', 'like', $group.'.%')
            : $q;
    }

    public function scopeEvent(Builder $q, ?string $event): Builder
    {
        return filled($event) && $event !== 'all' ? $q->where('event', $event) : $q;
    }

    public function scopeActorType(Builder $q, ?string $actorType): Builder
    {
        return filled($actorType) && $actorType !== 'all' ? $q->where('actor_type', $actorType) : $q;
    }

    /**
     * Everything a human is expected to look at.
     *
     * Derived from the event NAME rather than a stored level, because the table has no level
     * column — `BookingAuditor::recordFailure()` decides that when it writes to the log
     * channels, and the row it writes is identical to any other. Keeping the list here means
     * the admin screen and the docs' "← needs a human" annotations cannot disagree.
     */
    public const NEEDS_ATTENTION = [
        'payment.amount_mismatch',
        'payment.settle_after_refund',
        'payment.link_send_exhausted',
        'payment.amount_drift',
        'booking.lodgify_create_failed',
        'booking.unexpected_transition',
        'mail.failed',
        'lodgify.mark_booked.failed',
        'lodgify.mark_booked.exhausted',
        'lodgify.record_payment.failed',
    ];

    public function scopeNeedsAttention(Builder $q): Builder
    {
        return $q->whereIn('event', self::NEEDS_ATTENTION);
    }

    /**
     * Free text over the identifiers support is actually given.
     *
     * Booking and payment REFERENCES, not ids: an operator has "BK-7K2QMD" from an email or
     * a guest on the phone, never a primary key. The guest's email address is searchable for
     * the same reason — it is often the only thing they can quote.
     */
    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $q;
        }

        return $q->where(function (Builder $inner) use ($term) {
            $inner->where('event', 'like', "%{$term}%")
                ->orWhereHas('booking', fn (Builder $b) => $b
                    ->where('reference', 'like', "%{$term}%")
                    ->orWhere('guest_email', 'like', "%{$term}%")
                    ->orWhere('cottage_name', 'like', "%{$term}%"))
                ->orWhereHas('bookingPayment', fn (Builder $p) => $p->where('reference', 'like', "%{$term}%"));
        });
    }

    // ========================================================== presentation

    public function getGroupAttribute(): string
    {
        return Str::before($this->event, '.');
    }

    /**
     * Named differently from the `needsAttention` SCOPE on purpose: Eloquent resolves a
     * static call to a real instance method before it looks for a scope, so two members
     * sharing that name would make `BookingAuditLog::needsAttention()` a fatal error.
     */
    public function needsHumanAttention(): bool
    {
        return in_array($this->event, self::NEEDS_ATTENTION, true);
    }

    /** Tailwind ring/bg classes for the event badge, by prefix. */
    public function badgeClasses(): string
    {
        if ($this->needsHumanAttention()) {
            return 'bg-rose-50 text-rose-800 ring-rose-200';
        }

        return match ($this->group) {
            'payment' => 'bg-emerald-50 text-emerald-800 ring-emerald-200',
            'mail' => 'bg-sky-50 text-sky-800 ring-sky-200',
            'stripe' => 'bg-indigo-50 text-indigo-800 ring-indigo-200',
            'lodgify' => 'bg-amber-50 text-amber-800 ring-amber-200',
            default => 'bg-fog-100 text-tide-700 ring-fog-300',
        };
    }

    /** The context column, pretty-printed for display. Already scrubbed on the way in. */
    public function contextJson(): ?string
    {
        return filled($this->context)
            ? json_encode($this->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null;
    }
}
