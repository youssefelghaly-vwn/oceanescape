<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Contracts\Auth\MustVerifyEmail;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'country',
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

    /**
     * Details we can safely prefill into a booking form for this signed-in guest.
     *
     * NOT payment details — a card is never stored (see StripeGateway). This is the contact
     * information they have already given us, so a returning guest does not retype it.
     *
     * @return array<string, string|null>
     */
    public function bookingPrefill(): array
    {
        return [
            'guest_name' => $this->name,
            'guest_email' => $this->email,
            'guest_phone' => $this->phone,
            'guest_country' => $this->country,
        ];
    }

    /**
     * May this account book and pay in one step, without the emailed link?
     *
     * Two conditions, both about identity rather than convenience:
     *
     *   VERIFIED EMAIL     the same bar `/my-stays` sets. The booking is attached to this
     *                      account, and an unverified address is not yet proof the account
     *                      belongs to the person using it.
     *   ADDRESS MATCHES    the booking must be for this account's own email. Otherwise
     *                      "book direct" becomes a way to create a booking against someone
     *                      else's address while skipping the emailed link that would
     *                      normally have to be opened from that inbox.
     *
     * A guest who fails either can still book — they get the emailed payment link, which is
     * the ordinary flow and proves the address on the way through.
     */
    public function canBookDirectly(?string $forEmail = null): bool
    {
        if (! $this->hasVerifiedEmail()) {
            return false;
        }

        return $forEmail === null
            || strtolower(trim($forEmail)) === strtolower((string) $this->email);
    }

    public function businessStayRequests()
    {
        return $this->hasMany(BusinessStayRequest::class, 'handled_by');
    }
}
