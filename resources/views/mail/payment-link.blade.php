{{-- resources/views/mail/payment-link.blade.php

     COMPATIBILITY SHIM. Not the template — it forwards to `mail.payment-deposit` or
     `mail.payment-balance`, which are the real ones.

     WHY IT EXISTS

     This view used to be the single payment email. When it was split in two, deleting it
     opened a window in which a QUEUED WORKER still running the pre-deploy PaymentLinkMail
     asked for a view that no longer existed:

         local.ERROR: View [mail.payment-link] not found.

     A worker is a long-lived process: it keeps the code it booted with until it is
     restarted, so a deploy that removes a view its old code still names breaks every job in
     flight. The cost of that is not cosmetic — the guest never gets their payment link, and
     the booking sits Open until the sweeper releases it.

     So the file stays. It is a few lines, it derives everything it needs from `$booking` and
     `$payment` (never from variables only the NEW mailable passes), and it makes the same
     split safe to deploy in either order.

     Safe to delete once no worker anywhere can be running code from before that release —
     but there is no cost to keeping it, and the next person to split a mail template will be
     glad of the example. --}}
@php
    $shimIsBalance = $payment->type === \App\Enums\PaymentType::Balance;
@endphp

@include($shimIsBalance ? 'mail.payment-balance' : 'mail.payment-deposit', [
    'booking' => $booking,
    'payment' => $payment,
    'payUrl' => $payUrl,
    'amount' => $amount,
    'expires' => $expires ?? $payment->link_expires_at,
    'isFullPayment' => $payment->type === \App\Enums\PaymentType::Full,
    'daysToArrival' => $booking->arrival
        ? (int) now()->startOfDay()->diffInDays($booking->arrival->startOfDay(), false)
        : null,
])
