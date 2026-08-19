<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

class RegisterController extends Controller
{
    /** GET /register */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * POST /register
     *
     * Accounts exist so a guest can see their own Lodgify reservations, matched
     * on email address. That makes EMAIL VERIFICATION the security boundary, not
     * a nicety: an email address is not a secret, so without proving inbox
     * ownership anyone could register as a past guest and read their name,
     * phone, dates and totals.
     *
     * Registered users are therefore created UNVERIFIED, and the profile
     * controller refuses to show reservations until verification completes.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:120'],
            'email'    => ['required', 'string', 'email:rfc', 'max:180', 'unique:users,email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'website_url' => ['prohibited'],   // honeypot
        ], [
            'email.unique' => 'There is already an account with that email. Try signing in instead.',
        ]);

        $user = User::create([
            'name'     => $validated['name'],
            'email'    => strtolower($validated['email']),
            'password' => $validated['password'],   // hashed by the model cast
            'is_admin' => false,                     // never from a public form
        ]);

        event(new Registered($user));   // sends the verification email

        Auth::login($user);

        return redirect()
            ->route('verification.notice')
            ->with('status', 'Account created — please confirm your email address.');
    }
}
