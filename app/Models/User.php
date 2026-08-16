<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Notifications\ResetPasswordNotification;

class User extends Authenticatable
{
    use HasFactory;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_admin'          => 'boolean',
            'last_login_at'     => 'datetime',
        ];
    }

    /**
     * Admin access.
     *
     * A single boolean is the right amount of structure for a six-cottage
     * operation. If roles ever multiply, replace this with a proper
     * permissions package rather than adding more booleans.
     */
    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    /** Our own reset mail, so the link points at the named route and reads in our voice. */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function businessStayRequests()
    {
        return $this->hasMany(BusinessStayRequest::class, 'handled_by');
    }
}
