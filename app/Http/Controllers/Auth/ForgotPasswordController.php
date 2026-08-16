<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class ForgotPasswordController extends Controller
{
    /** GET /forgot-password */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /** POST /forgot-password */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = Password::sendResetLink($request->only('email'));

        /*
         * Always report success, whatever Password:: returns.
         *
         * Distinguishing "sent" from "no such user" turns this form into an
         * account-enumeration oracle. Genuine failures are logged rather than
         * shown. The one exception is throttling, which the user needs to know
         * about or they will keep pressing the button.
         */
        if ($status === Password::RESET_THROTTLED) {
            return back()->withErrors([
                'email' => 'A reset link was sent recently. Please wait a minute before asking for another.',
            ]);
        }

        if ($status !== Password::RESET_LINK_SENT) {
            report(new \RuntimeException("Password reset link not sent: {$status}"));
        }

        return back()->with('status', 'If that email is registered, a reset link is on its way.');
    }
}
