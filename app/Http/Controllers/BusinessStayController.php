<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBusinessStayRequest;
use App\Models\BusinessStayRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class BusinessStayController extends Controller
{
    /** GET /business-stays */
    public function create(): View
    {
        return view('pages.business-stays');
    }

    /** POST /business-stays */
    public function store(StoreBusinessStayRequest $request): RedirectResponse
    {
        $stay = BusinessStayRequest::create($request->validated() + [
            'source'     => 'website',
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 512),
        ]);

        /*
         * Notification is deliberately not fatal: a corporate enquiry that
         * saved but failed to email is recoverable from the admin queue, while
         * showing the guest an error would lose the lead entirely.
         */
        try {
            // Mail::to(config('mail.enquiries_to'))->send(new BusinessStayReceived($stay));
        } catch (\Throwable $e) {
            Log::error('Business stay notification failed', [
                'reference' => $stay->reference,
                'message'   => $e->getMessage(),
            ]);
        }

        return redirect()
            ->route('business-stays.thanks')
            ->with('business_stay_reference', $stay->reference);
    }

    /** GET /business-stays/thank-you */
    public function thanks(): View|RedirectResponse
    {
        $reference = session('business_stay_reference');

        // Nothing to confirm if someone lands here directly.
        if (!$reference) {
            return redirect()->route('business-stays.create');
        }

        return view('pages.business-stays-thanks', ['reference' => $reference]);
    }
}