<?php

namespace App\Models;

use App\Enums\BusinessStayStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class BusinessStayRequest extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'check_in'            => 'date',
            'check_out'           => 'date',
            'dates_flexible'      => 'boolean',
            'needs_invoice'       => 'boolean',
            'needs_meeting_space' => 'boolean',
            'pets'                => 'boolean',
            'budget_per_night'    => 'decimal:2',
            'status'              => BusinessStayStatus::class,
            'contacted_at'        => 'datetime',
            'quoted_at'           => 'datetime',
            'closed_at'           => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $request) {
            $request->reference ??= self::generateReference();

            // Derive nights when both dates are given, so the admin list can
            // sort and total on it without recomputing per row.
            if ($request->check_in && $request->check_out && !$request->nights) {
                $request->nights = Carbon::parse($request->check_in)
                    ->diffInDays(Carbon::parse($request->check_out));
            }
        });
    }

    /**
     * Short, unambiguous reference: BS-7K2QMD.
     * Excludes vowels and lookalike characters so it survives being read aloud.
     */
    public static function generateReference(): string
    {
        do {
            $code = 'BS-' . strtoupper(Str::password(6, symbols: false, numbers: true, spaces: false));
            $code = preg_replace('/[^A-Z0-9]/', '', $code);
            $code = 'BS-' . substr(str_replace(['O', 'I', 'L', '0', '1'], ['X', 'Y', 'Z', '2', '3'], $code), 2, 6);
        } while (self::withTrashed()->where('reference', $code)->exists());

        return $code;
    }

    public function handler()
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    // ---------------------------------------------------------------- scopes

    public function scopeStatus(Builder $q, ?string $status): Builder
    {
        return $status ? $q->where('status', $status) : $q;
    }

    public function scopeOpen(Builder $q): Builder
    {
        return $q->whereIn('status', ['new', 'contacted', 'quoted']);
    }

    /** Free-text search across the fields someone would actually recall. */
    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $q;
        }

        return $q->where(function (Builder $q) use ($term) {
            foreach (['reference', 'company_name', 'contact_name', 'email', 'phone'] as $column) {
                $q->orWhere($column, 'like', "%{$term}%");
            }
        });
    }

    // ------------------------------------------------------------ accessors

    public function getPartyLabelAttribute(): string
    {
        return "{$this->guests_count} guests · {$this->cottages_count} "
             . Str::plural('cottage', $this->cottages_count);
    }

    public function getStayLabelAttribute(): string
    {
        if ($this->dates_flexible && !$this->check_in) {
            return $this->flexible_note ?: 'Flexible dates';
        }
        if (!$this->check_in) {
            return 'Dates TBC';
        }
        $label = $this->check_in->format('M j, Y');
        if ($this->check_out) {
            $label .= ' – ' . $this->check_out->format('M j, Y');
        }
        if ($this->dates_flexible) {
            $label .= ' (flexible)';
        }
        return $label;
    }

    /** Rough value, for prioritising the queue. Null when we have no budget. */
    public function getEstimatedValueAttribute(): ?float
    {
        if (!$this->budget_per_night || !$this->nights) {
            return null;
        }
        return (float) $this->budget_per_night * $this->nights * max(1, $this->cottages_count);
    }
}