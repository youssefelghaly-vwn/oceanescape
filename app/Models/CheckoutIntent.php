<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class CheckoutIntent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'arrival'      => 'date',
            'departure'    => 'date',
            'quoted_total' => 'decimal:2',
            'addons'       => 'array',
            'converted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $intent) {
            $intent->reference ??= 'INT-' . strtoupper(Str::random(6));

            if ($intent->arrival && $intent->departure && !$intent->nights) {
                $intent->nights = Carbon::parse($intent->arrival)
                    ->diffInDays(Carbon::parse($intent->departure));
            }
        });
    }

    // ---------------------------------------------------------------- scopes

    public function scopeConverted(Builder $q): Builder
    {
        return $q->where('status', 'converted');
    }

    public function scopeStatus(Builder $q, ?string $status): Builder
    {
        return $status && $status !== 'all' ? $q->where('status', $status) : $q;
    }

    /**
     * Redirects that never converted and are old enough to give up on.
     *
     * The window matters: a guest may take twenty minutes over Lodgify's three
     * steps, so anything younger than the grace period is still in flight, not
     * abandoned.
     */
    public function scopeStale(Builder $q, int $graceMinutes = 90): Builder
    {
        return $q->where('status', 'redirected')
                 ->where('created_at', '<', now()->subMinutes($graceMinutes));
    }

    /**
     * Find the intent that most likely produced a given Lodgify booking.
     *
     * Matched on property and dates, newest first, ignoring anything already
     * claimed by another booking.
     */
    public static function matchFor(int $cottageId, string $arrival, string $departure): ?self
    {
        return static::query()
            ->where('cottage_id', $cottageId)
            ->whereDate('arrival', $arrival)
            ->whereDate('departure', $departure)
            ->whereNull('lodgify_booking_id')
            ->latest()
            ->first();
    }

    public function markConverted(?string $bookingId = null): void
    {
        $this->forceFill([
            'status'             => 'converted',
            'lodgify_booking_id' => $bookingId,
            'converted_at'       => now(),
        ])->save();
    }

    public function getStayLabelAttribute(): string
    {
        return $this->arrival->format('M j') . ' – ' . $this->departure->format('M j, Y');
    }

    public function getAddonCountAttribute(): int
    {
        return count($this->addons ?? []);
    }
}
