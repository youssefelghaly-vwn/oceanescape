<?php

namespace App\Models;

use App\Enums\GuestPhotoStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class GuestPhoto extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status'        => GuestPhotoStatus::class,
            'stayed_on'     => 'date',
            'consent_given' => 'boolean',
            'is_featured'   => 'boolean',
            'reviewed_at'   => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(fn (self $p) => $p->uuid ??= (string) Str::uuid());

        // Remove the file when the record is hard-deleted, so purging really
        // purges. Soft deletes intentionally leave the file in place.
        static::forceDeleted(function (self $p) {
            try {
                Storage::disk($p->disk)->delete($p->path);
            } catch (\Throwable) {
                // already gone
            }
        });
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // ------------------------------------------------------------- scopes

    public function scopeApproved(Builder $q): Builder
    {
        return $q->where('status', GuestPhotoStatus::Approved->value);
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->where('status', GuestPhotoStatus::Pending->value);
    }

    public function scopeStatus(Builder $q, ?string $status): Builder
    {
        return $status ? $q->where('status', $status) : $q;
    }

    /** Featured first, then the moderator's manual order, then newest. */
    public function scopeGalleryOrder(Builder $q): Builder
    {
        return $q->orderByDesc('is_featured')
                 ->orderBy('sort_order')
                 ->orderByDesc('created_at');
    }

    // ---------------------------------------------------------- accessors

    /**
     * Public URL — only ever for approved photos on the public disk.
     *
     * Returns null while pending: a template that forgets to check the status
     * then renders nothing, rather than leaking an unmoderated image.
     */
    public function getUrlAttribute(): ?string
    {
        if ($this->status !== GuestPhotoStatus::Approved || $this->disk !== 'public') {
            return null;
        }
        return Storage::disk('public')->url($this->path);
    }

    /** Moderation preview, served through an authenticated route. */
    public function getPreviewUrlAttribute(): string
    {
        return route('admin.photos.file', $this);
    }

    public function getSizeLabelAttribute(): string
    {
        $kb = $this->size_bytes / 1024;
        return $kb > 1024
            ? number_format($kb / 1024, 1) . ' MB'
            : number_format($kb) . ' KB';
    }

    public function getDimensionsLabelAttribute(): ?string
    {
        return ($this->width && $this->height) ? "{$this->width} × {$this->height}" : null;
    }

    public function getCreditAttribute(): string
    {
        // First name only: enough to credit, not enough to identify.
        return Str::before(trim($this->guest_name), ' ') ?: 'A guest';
    }
}
