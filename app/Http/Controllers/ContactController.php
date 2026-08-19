<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreContactMessageRequest;
use App\Models\ContactMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class ContactController extends Controller
{
    /** GET /contact */
    public function create(): View
    {
        return view('pages.contact');
    }

    /** POST /contact */
    public function store(StoreContactMessageRequest $request): RedirectResponse
    {
        $message = ContactMessage::create($request->safe()->except('website_url') + [
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 512),
        ]);

        // Never let a mail failure lose the message — it is already saved.
        try {
            // Mail::to(config('mail.enquiries_to'))->send(new ContactReceived($message));
        } catch (\Throwable $e) {
            Log::error('Contact notification failed', [
                'reference' => $message->reference,
                'message'   => $e->getMessage(),
            ]);
        }

        return back()->with('contact_sent', $message->reference);
    }
}
