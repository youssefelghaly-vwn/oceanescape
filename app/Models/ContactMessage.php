<?php

namespace App\Models;

use App\Enums\ContactMessageStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ContactMessage extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status'     => ContactMessageStatus::class,
            'read_at'    => 'datetime',
            'replied_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $m) {
            $m->reference ??= 'MSG-' . strtoupper(Str::random(6));
        });
    }

    public function handler()
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function scopeStatus(Builder $q, ?string $status): Builder
    {
        return $status ? $q->where('status', $status) : $q;
    }

    public function scopeUnhandled(Builder $q): Builder
    {
        return $q->whereIn('status', ['new', 'read']);
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }
        return $q->where(function (Builder $q) use ($term) {
            foreach (['reference', 'name', 'email', 'phone', 'subject', 'message'] as $col) {
                $q->orWhere($col, 'like', "%{$term}%");
            }
        });
    }

    /** Mark as read the first time a moderator opens it, without touching later states. */
    public function markRead(): void
    {
        if ($this->status === ContactMessageStatus::New) {
            $this->forceFill([
                'status'  => ContactMessageStatus::Read,
                'read_at' => now(),
            ])->save();
        }
    }

    public function getExcerptAttribute(): string
    {
        return Str::limit(preg_replace('/\s+/', ' ', $this->message), 110);
    }
}
