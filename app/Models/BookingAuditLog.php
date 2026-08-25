<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
