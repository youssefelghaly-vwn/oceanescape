<x-mail::message>
# Action needed: paid booking not confirmed in Lodgify

A guest has **paid**, but we could not set their reservation to `Booked` in Lodgify after
all retries. **The dates are therefore still on sale** and could be double-booked.

<x-mail::panel>
**Booking:** {{ $booking->reference }}
**Lodgify reservation:** {{ $booking->lodgify_booking_id ?? '— none recorded —' }}
**Cottage:** {{ $booking->cottage_name }} (property {{ $booking->cottage_id }})
**Stay:** {{ $booking->stay_label }} ({{ $booking->nights }} nights)
**Guest:** {{ $booking->guest_name }} · {{ $booking->guest_email }} · {{ $booking->guest_phone }}
**Paid so far:** {{ $paid->format() }} of {{ $booking->total()->format() }}
</x-mail::panel>

**What to do now**

1. Open the reservation in Lodgify and set it to **Booked** by hand — this is what blocks the calendar.
2. Confirm the dates were not sold to somebody else in the meantime.
3. If they were, call the guest on {{ $booking->guest_phone }}. They have paid in good faith.

**Why it failed**

```
{{ $reason }}
```

Our record of the booking and the payment is intact — only the Lodgify write failed.
Nothing needs to be re-charged.
</x-mail::message>
