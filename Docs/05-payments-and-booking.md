# Direct Booking and Payments

How the site takes bookings and money itself, instead of handing the guest to Lodgify's
hosted checkout.

- [Part 1 — What changed](#part-1--what-changed)
- [Part 2 — The flow, step by step](#part-2--the-flow-step-by-step)
- [Part 3 — Idempotency](#part-3--idempotency)
- [Part 4 — Security model](#part-4--security-model)
- [Part 5 — Audit and logging](#part-5--audit-and-logging)
- [Part 6 — The unverified Lodgify contract](#part-6--the-unverified-lodgify-contract)
- [Part 7 — Configuration reference](#part-7--configuration-reference)
- [Part 8 — Testing](#part-8--testing)
- [Part 9 — Rollout checklist](#part-9--rollout-checklist)
- [Part 10 — Known risks and limits](#part-10--known-risks-and-limits)

---

# Part 1 — What changed

## Removed

Lodgify's hosted checkout is **gone from this project**. There is no `/book/{slug}`
redirect, no `LodgifyCheckout` service, no `checkout_intents` table, and no feature flag.
A guest never sees a Lodgify URL.

| Deleted | Was |
|---|---|
| `app/Services/Lodgify/LodgifyCheckout.php` | Built the hand-off URL into `checkout.lodgify.com` |
| `app/Http/Controllers/BookingRedirectController.php` | `GET /book/{slug}` — recorded the intent, then 302'd off-site |
| `app/Models/CheckoutIntent.php` + `checkout_intents` table | Attribution for guests we had lost to the redirect |
| `app/Http/Controllers/Admin/CheckoutIntentController.php` + its view | `/admin/checkouts`, whose conversion rate was permanently 0% |
| `booking.direct_payments_enabled` | The rollback flag. There is nothing to roll back to |
| `lodgify.checkout_slug` / `checkout_currency` / `checkout_grace_minutes` | Hosted-checkout settings |

**One deliberate exception.** `lodgify.checkout_base_url` survives, because
`checkout.lodgify.com` *also* serves read-only `/api/v1/checkout/calendar`,
`/api/v1/checkout/price` and `/api/v1/checkout/{id}` endpoints, used server-to-server as a
data fallback when the authenticated v2 API fails. That is availability and pricing, not
payment, and no guest is ever sent there. `NoLodgifyCheckoutTest` pins both halves: the
payment path is absent, the read fallback is intentionally kept.

## The flow

## The design decisions behind it

| Decision | Choice | Why |
|---|---|---|
| Deposit timing | Booking first, then an emailed link | Nothing is charged when the guest confirms; the payment link follows by email. |
| What flips `Open → Booked` | The **deposit** | Lodgify's own semantics, and it blocks the calendar as early as possible. |
| Deposit amount | **Lodgify's `scheduled_payments`, strictly** | Lodgify is the authority on what is owed. Two systems disagreeing about money means the guest believes whichever they saw last. If Lodgify sends no schedule we **refuse** rather than guess. |
| Card handling | **Hosted** Stripe Checkout | Card data never touches this server: PCI SAQ A, not SAQ A-EP. Same reasoning that kept the project out of PCI scope before. |
| Lodgify create call | **Synchronous** | Lodgify's acceptance is the authoritative answer; the guest must be told *now* if it is no. Nothing is charged yet, so failing is safe. |
| Lodgify confirm call | **Queued, with retries and an alert** | Runs *after* money has moved. A Lodgify outage must never turn a successful payment into a failed webhook. |

---

# Part 2 — The flow, step by step

## 2.0 `GET /booking/details/{slug}` — the guest-details step

Where the cottage page's **Book now** button goes. There is no branch — this is the only
booking path:

```blade
bookUrl: '{{ route('booking.details', $cottage->slug) }}',
```

A real page rather than a modal, for three reasons:

- **Validation failures have somewhere to land.** `StoreBookingRequest::getRedirectUrl()`
  is overridden to return here — the FormRequest redirect happens *before* the controller
  runs, so the controller's own error handling never sees it, and Laravel's default
  `back()` depends on a `Referer` header that may not be there.
- It survives a refresh and can be linked to.
- **The price is re-quoted server-side**, so the figure the guest agrees to is the figure
  `DepositPolicy` will charge — not whatever the calendar widget happened to show.

That last point also gives us drift detection: the calendar passes the total it was
displaying, and if the live quote disagrees the page says so plainly rather than quietly
charging a different number. If Lodgify will not price the stay at all, the guest is sent
back to the cottage page with an explanation instead of being shown a form that cannot
succeed.

## 2.1 `POST /booking` — create, charge nothing

`BookingController::store` → `BookingCreator::create`

Order matters, and each step is ordered for a reason:

| # | Step | Why here |
|---|---|---|
| 1 | Look up the cottage by slug | 404 early |
| 2 | Check the idempotency key | A double-clicked confirm resolves to the same booking, not a second reservation |
| 3 | **Re-quote live from Lodgify** (`QuoteReader`) | The browser has been showing a cached price for up to 60s — possibly much longer in wall-clock terms. Never charge from a stale quote. `forgetQuote()` bypasses the cache. |
| 4 | **Re-check availability** | Cheap. A booking taken for sold nights is worse than a lost booking. A *failure to check* is not treated as a no — Lodgify validates again on create. |
| 5 | Derive the payment plan (`DepositPolicy`) | Throws `PaymentScheduleUnavailable` rather than inventing an amount |
| 6 | Write the `bookings` row, in a transaction | The guest's details survive a Lodgify failure |
| 7 | `POST /v1/reservation/booking` — **outside** the transaction | An HTTP call inside a transaction holds a write lock for a network round trip; on SQLite that stalls the whole site |
| 8 | Transition to `awaiting_deposit`, queue the link | Nothing irreversible has happened |

**Nothing in this path charges anything.** That is what makes failing safe: the worst
outcome is an orphaned row and possibly an `Open` reservation, both of which
`booking:expire-stale` releases.

### Validation
`StoreBookingRequest`. Note what is **absent**: any money field. There is no rule for a
total, a deposit, or a currency, because a price a request can influence is a price a
guest can choose. The `the_client_cannot_influence_the_price` test pins this.

Also present: a `website_url` honeypot (matching the other public forms) and
`terms_accepted` as `accepted` — we are creating a reservation in someone's name, which
needs a recorded yes.

## 2.2 The payment link

`SendPaymentLink` job → `PaymentLinkService::prepareSession` → `PaymentLinkMail`

The email carries a link to **our** domain, never a raw Stripe URL:

| Reason | Detail |
|---|---|
| Revocable | A Stripe session URL cannot be recalled once it is in an inbox |
| Our expiry | Stripe sessions last up to 24h; our deposit window is configurable and may be shorter or longer |
| Recoverable | An expired session shows the guest a bare Stripe error. Through our page we mint a fresh one and carry on |
| Observable | One place to record that the link was opened |

The route is **signed and expiring**, and the path segment is 32 random bytes
(`bin2hex(random_bytes(32))`) rather than an id — so links are neither guessable nor
enumerable from a neighbouring booking.

## 2.3 `GET /pay/{token}` → Stripe

`PaymentController::show`. Already paid → receipt page, not a second checkout. Terminal
booking → unavailable page. Lapsed session → `PaymentLinkService::refresh()` mints a new
one (rotating **both** the token and the idempotency key — reusing the key would make
Stripe hand back the same expired session forever).

## 2.4 The webhook — where a booking is actually confirmed

`StripeWebhookController::handle` → `PaymentSettler::settle`

| Event | Handling |
|---|---|
| `checkout.session.completed` | If `payment_status === 'paid'` → settle. Otherwise → `processing` (some methods settle asynchronously; treating completion as payment would confirm a booking against money that has not arrived) |
| `checkout.session.async_payment_succeeded` | Settle |
| `checkout.session.async_payment_failed` | Mark failed |
| `checkout.session.expired` | Mark expired; our own link may still be live |
| `charge.refunded` | Record it. **Does not cancel the reservation** — a partial refund, a goodwill gesture and a real cancellation are indistinguishable here, and unbooking a stay is not a webhook's decision |
| anything else | Recorded as `ignored`, answered 200 |

`PaymentSettler::settle` then, inside a transaction with a row lock:

1. Already `paid`? → return false. Nothing runs twice.
2. **Amount check.** Stripe's `amount_total` vs our `amount_cents`. A mismatch marks the
   payment `failed`, records `payment.amount_mismatch`, and **does not confirm the
   booking** — the money is in Stripe and a human sorts it out. Confirming a reservation
   against the wrong amount is the worse outcome.
3. Mark paid, advance the booking, queue the Lodgify write, send the confirmation.

The return page (`/pay/{token}/success`) also reconciles opportunistically from Stripe, so
a guest is not told "awaiting payment" a second after paying — through the **same**
settler, so whichever path arrives second is a no-op.

## 2.5 Confirming in Lodgify

`MarkLodgifyBookingBooked` — 6 attempts over ~30 minutes, `retryUntil` one hour.

This is the one job that must not silently give up: it only ever runs after money has been
captured, so if it never succeeds the guest has paid for nights Lodgify still believes are
for sale. On exhaustion it emails `BOOKING_ALERT_EMAIL` (`BookingNeedsAttention`) telling
a person exactly what to do, and if no alert address is set it logs at `critical` — silence
is the one unacceptable outcome.

`RecordLodgifyPayment` is **best effort** by contrast: the money is captured and the
reservation is confirmed, so this only keeps Lodgify's `amount_paid`/`amount_due` tidy. It
does not page anybody. See [Part 6](#part-6--the-unverified-lodgify-contract).

## 2.6 The balance, and the sweeper

`booking:send-balance-links` — hourly. Idempotent **by query**: the scope excludes any
booking that already has a balance payment row, so overlapping runs cannot double-send.
Hourly rather than daily so a booking made inside the window gets its link promptly.

`booking:expire-stale` — every 30 minutes. Expires lapsed Stripe sessions and releases
`Open` reservations whose deposit never arrived. It re-reads each booking immediately
before releasing and skips anything that has moved on, because the race it must never lose
is *a guest paying in the seconds between the query and the write*. There is a test named
exactly that.

---

# Part 3 — Idempotency

Enforced by **database constraints**, not by careful code. The consequence of getting it
wrong is charging a guest twice.

| Layer | Mechanism | Protects against |
|---|---|---|
| Booking creation | `bookings.idempotency_key` UNIQUE, derived from cottage + dates + guest email | Double-clicked confirm; browser retry |
| One payment per type | `UNIQUE(booking_id, type)` | Two deposits, two balances |
| Stripe session creation | `idempotency_key` sent to Stripe, stored on the row | A retry creating a second payable link |
| Stripe object binding | `UNIQUE` on `stripe_checkout_session_id`, `stripe_payment_intent_id` | One Stripe object attaching to two payment rows |
| Webhook delivery | `stripe_webhook_events.stripe_event_id` UNIQUE, **inserted before processing** | Stripe's at-least-once delivery |
| Settlement | Row lock + `status === Paid` guard | Two concurrent deliveries both settling |
| Booking status | Compare-and-swap `UPDATE … WHERE status = :from` | Two workers both applying the same transition and running side effects twice |
| Lodgify confirm | Guard on `lodgify_status === 'Booked'` | Double-writing to Lodgify |
| Balance link | `whereDoesntHave('payments', type=balance)` | Hourly scheduler double-sending |

## Why insert-before-process

Stripe guarantees at-least-once delivery. Two deliveries of one event can land on two
workers in the same millisecond, and a `if (! exists) { … }` leaves a gap between the check
and the write. Attempting the `INSERT` and catching `UniqueConstraintViolationException`
pushes the race down to the database, where it is atomic:

```php
try {
    $record = StripeWebhookEvent::create([...]);
} catch (UniqueConstraintViolationException) {
    return response('Already processed.', 200);   // normal, not an error
}
```

## Why compare-and-swap

```php
$applied = static::query()
    ->whereKey($this->getKey())
    ->where('status', $from->value)     // ← the guard
    ->update($extra + ['status' => $to->value]);

return $applied === 1;                  // false = somebody else won
```

A plain `$this->update(['status' => …])` would let both racing callers through, and both
would then queue a Lodgify write and send a confirmation email. The
`compare_and_swap_means_only_one_concurrent_caller_wins` test pins this.

## Why the webhook returns 500 on a handler error

Deliberately. Stripe retries with backoff, and the insert-before-process guard makes the
retry safe. Returning 200 on an error would tell Stripe the event was handled and
permanently strand a paid booking.

---

# Part 4 — Security model

## 4.1 The webhook is the boundary

`POST /webhooks/stripe` is public and CSRF-exempt — Stripe cannot present a token. The
exemption is scoped to that single path in `bootstrap/app.php`, and it is safe **only**
because the signature is verified against the raw body before anything is parsed. Without
that, a stranger who knows the URL could mark any booking paid and confirm a reservation
for free.

| Control | Implementation |
|---|---|
| Signature verification | `Webhook::constructEvent()` on `$request->getContent()` — the RAW body, before `json()` |
| Replay bounding | `STRIPE_WEBHOOK_TOLERANCE` (300s default) rejects an old captured request |
| Fail closed | No `STRIPE_WEBHOOK_SECRET` → reject everything, log `critical`. An unset secret must never mean "accept anything" |
| No information leak | Any failure → `400 Invalid signature.` Never which part failed |
| Generous rate limit | 300/min. Throttling a payment webhook into a 429 makes Stripe retry and delays a real booking — the limiter must not be the reason a paid guest is left `Open` |

Four tests cover this: no signature, forged signature, stale timestamp, and missing secret.

## 4.2 The amount is never client-controlled

Three independent layers:

1. `StoreBookingRequest` has **no money rules at all**. A `total` in the request is simply
   not validated data.
2. `Booking::$fillable` and `BookingPayment::$fillable` **exclude** every `*_cents` column,
   `status`, `currency`, `idempotency_key` and all `stripe_*` ids. They are set by
   `forceFill()` in the services that own them. (This follows finding **F4** in
   [`03-security.md`](03-security.md) — explicit `$fillable` rather than `$guarded = []`.)
3. `PaymentSettler` compares Stripe's captured amount against our stored amount and
   refuses to confirm on a mismatch.

## 4.3 Payment links

| Property | How |
|---|---|
| Not guessable | 64 hex chars of CSPRNG, not an id |
| Not enumerable | A signature for one token does not validate another — tested |
| Expiring | `URL::temporarySignedRoute`, TTL from config |
| Revocable | `PaymentLinkService::refresh()` rotates the token and expires the Stripe session |
| Rate limited | Keyed on the token, so a guest refreshing their own link is fine while walking many tokens is not |

The two Stripe **return** URLs are deliberately unsigned — Stripe appends its own query
parameters, which would break a signature. Safe because they grant nothing: they only read
state, and any reconciliation goes through the same amount-checking, idempotent settler.

## 4.4 PCI scope

Hosted Checkout means card data never reaches this server: **SAQ A**, not SAQ A-EP. This is
the same reasoning `LodgifyCheckout` documented for the old flow — we changed *who takes
the payment*, not whether we handle card data. No card number, CVC or expiry appears
anywhere in the schema, the logs, or the audit trail.

## 4.5 Other controls

- **Money in integer cents** throughout. `Money::fromFloat()` rounds instead of truncating,
  because `(int) (19.99 * 100)` is `1998` — a one-cent undercharge on every such price.
  There is a test asserting the naive cast is still wrong, so the reason cannot be lost.
- **Zero-decimal currencies rejected** rather than silently charged 100×.
- **Honeypot + `terms_accepted`** on the booking form.
- **Tight creation limit** (5/min/IP, 3/min/email, 40/day/IP): each attempt can create a
  real reservation and a Stripe session.
- **Guest-safe error copy only.** `BookingException::guestMessage()` returns null when the
  reason is internal, and controllers fall back to something generic rather than leaking an
  upstream error. `LodgifyWriteFailed::guestMessage()` returns null when
  `moneyAtRisk` — never tell a guest who has paid that something failed.

---

# Part 5 — Audit and logging

Every state change is written to **two** places:

- `booking_audit_logs` — queryable, joinable in an admin screen
- the `booking` log channel (`storage/logs/booking-*.log`, 90-day retention) — what you
  tail during an incident, and what survives the database being the thing that broke

## The audit table is immutable

No `updated_at`, and the model throws on `updating` and `deleting`. An audit trail that
can be edited is not an audit trail. Tested.

## Context is scrubbed centrally

`BookingAuditor::scrub()` redacts any key containing `secret`, `password`, `token`,
`api_key`, `authorization`, `card`, `cvc`, `cvv`, `number`, `signature` or `client_secret`,
at any depth, and summarises objects as `[ClassName]` rather than serialising them. Audit
rows are read by more people than write the code that fills them, so "I'll be careful at
the call site" does not survive a year of edits.

## Event vocabulary

```
booking.created                  booking.duplicate_suppressed
booking.awaiting_deposit         booking.lodgify_create_failed
booking.advanced                 booking.unexpected_transition
booking.expired_unpaid           booking.confirmation_mail_failed
payment.created                  payment.link_sent / link_emailed / link_opened
payment.link_refreshed           payment.checkout_abandoned
payment.succeeded                payment.amount_mismatch          ← needs a human
payment.failed                   payment.settle_ignored_already_paid
payment.expired                  payment.expired_by_sweeper
payment.amount_drift             payment.link_send_exhausted
lodgify.create.attempt/ok        lodgify.mark_booked.attempt/ok/failed
lodgify.mark_booked.exhausted    ← guest paid, calendar wrong. ALERT
lodgify.record_payment.ok/failed lodgify.release.ok
```

An audit write failing never breaks the flow it describes — losing the trail is bad,
failing a paid booking because we could not log it is worse. The log channel records that
the audit write itself failed.

---

# Part 6 — The unverified Lodgify contract

**Read this before enabling the feature in production.**

## What is now CONFIRMED against a live account

The create call has been exercised for real, and it works with the **default** field map —
no remapping needed:

| Fact | How we know |
|---|---|
| `POST /v1/reservation/booking` accepts the default `field_map` — snake_case keys with a nested `guest` object and a `rooms[]` array | A real create returned 2xx in production |
| It responds with a **bare integer** reservation id, e.g. `17388658` — not an object | Same. `createBooking()` was typed `: array` and threw a TypeError on it |

That second row cost a real reservation. The TypeError fired *after* the HTTP call
succeeded, so Lodgify created the booking and we discarded its id — leaving an orphaned
`Open` reservation on the calendar with nothing pointing at it. Three changes came out of
it:

- The write methods return `mixed` (raw decoded JSON). `extractBookingId()` already handled
  bare ids; the client's narrow return type was the whole bug.
- `createOpenBooking()` and `markBooked()` now catch `\Throwable`, not just
  `LodgifyApiException`, so a post-request failure becomes a `LodgifyWriteFailed` carrying
  the context and a `critical` log line instead of escaping raw.
- **`php artisan booking:reconcile-orphans`** is the recovery path — see below.

`LodgifyWriteResponseShapeTest` pins all of it: bare int, quoted string, `{id}`,
`{data:{id}}`, 2xx-with-no-usable-id, and that writes are never retried at the transport.

## Recovering an orphaned reservation

```bash
php artisan booking:reconcile-orphans           # report only
php artisan booking:reconcile-orphans --link    # attach the matches
```

Reads the reservation feed back out of Lodgify (the read side has always worked) and
matches it to bookings stuck with no `lodgify_booking_id`, on property + arrival +
departure, disambiguating on guest email where Lodgify has one.

It refuses to guess. Several reservations on the same nights with no email match →
reported as ambiguous and left alone, because attaching our booking (and later our
payments) to a stranger's reservation is far worse than making someone look. A booking
already marked `failed` is relinked but **not** silently revived — somebody decided it
failed, and reviving it behind their back is its own bug.

## What was established from documentation

| Fact | Source |
|---|---|
| `POST /v1/reservation/booking` creates a booking | Lodgify API reference |
| `PUT /v1/reservation/booking/{id}/book` sets it Booked and updates the availability calendar | Lodgify API reference |
| `status` values include `Open`, `Booked`, `Declined` | **Verified** read shape — see the `App\DTO\Reservation` docblock, captured from a live account |
| `total_amount` / `amount_paid` / `amount_due` exist on a reservation | Same |
| Auth is the `X-ApiKey` header | Existing working code |

## What is STILL not established

- Whether `PUT /v1/reservation/booking/{id}/book` behaves as documented — specifically
  whether the Lodgify calendar really blocks afterwards. **Verify this by eye in the
  dashboard**; it is the step that stops a double booking.
- Whether any public endpoint exists for **recording a payment** against a booking.

Neither could be checked from the environment this was built in: outbound access to
`docs.lodgify.com` and `api.lodgify.com` is blocked by the network egress policy, so the
live API could not be probed from here.

## How the code handles that honestly

1. **Field names live in config.** `config/lodgify.php → write.field_map`, with
   `LODGIFY_FIELD_*` env overrides. Correcting them is a config change, not a refactor.
   This mirrors the existing `rates_param_style` escape hatch, which exists because
   Lodgify's parameter naming differs per endpoint and had to be discovered empirically.

2. **A probe command discovers the real shape**, the same way every other Lodgify shape in
   this codebase was established (`probeRatesCalendar`, `probeBookings`):

   ```bash
   php artisan lodgify:probe-booking-write --property=738423          # dry run
   php artisan lodgify:probe-booking-write --property=738423 --confirm
   ```

   It tries five candidate shapes (snake_case nested/flat guest, PascalCase, camelCase,
   `houseId` naming), stops at the first Lodgify accepts, and prints the payload that
   worked plus the created reservation id. ⚠ **A successful probe creates a real
   reservation** — it is labelled `API PROBE — DELETE ME`, the command prints how to delete
   it, and it refuses to run in production.

3. **Payment recording is off unless configured.** `LODGIFY_RECORD_PAYMENT_PATH` is unset by
   default, and `recordBookingPayment()` returns `null` without calling anything rather than
   firing money-shaped data at a guessed URL. The payment row records
   `"Not recorded: no Lodgify payment endpoint configured."` so an admin can see why the
   Lodgify dashboard shows a balance that has in fact been paid.

4. **The create path was wrong before and is now fixed.** The pre-existing
   `LodgifyClient::createBooking()` posted to `/v2/reservations/bookings` — the v2 **list**
   endpoint. It had never failed in production only because nothing called it.

5. **Writes never auto-retry at the transport.** `writeHttp()` deliberately omits the
   `->retry()` that `http()` applies: retrying a non-idempotent POST is how you create two
   reservations for the same nights. Retry lives at the job level, guarded on
   `lodgify_booking_id` being null.

---

# Part 7 — Configuration reference

## `config/booking.php`

| Key | Env | Default | Notes |
|---|---|---|---|
| `deposit.source` | — | `lodgify_schedule` | Strictly Lodgify's schedule |
| `deposit.allow_percentage_fallback` | `BOOKING_DEPOSIT_ALLOW_FALLBACK` | `false` | Leave off. On = charging an amount Lodgify never sanctioned |
| `deposit.fallback_percent` | `BOOKING_DEPOSIT_FALLBACK_PERCENT` | `25.0` | Only used if the above is on |
| `deposit_link_ttl_hours` | `BOOKING_DEPOSIT_LINK_TTL` | `48` | **Also bounds how long dates sit unheld** |
| `balance_link_ttl_days` | `BOOKING_BALANCE_LINK_TTL_DAYS` | `14` | |
| `balance_lead_days` | `BOOKING_BALANCE_LEAD_DAYS` | `30` | Matches Lodgify's own default |
| `full_payment_within_days` | `BOOKING_FULL_PAYMENT_WITHIN_DAYS` | `14` | Single payment for imminent stays |
| `mark_booked_on` | `BOOKING_MARK_BOOKED_ON` | `deposit` | `balance` is supported but leaves dates unblocked |
| `record_payments_in_lodgify` | `BOOKING_RECORD_PAYMENTS_IN_LODGIFY` | `true` | Best effort |
| `alert_email` | `BOOKING_ALERT_EMAIL` | — | **Set this.** Paid-but-unconfirmed alerts |
| `support_phone` / `support_email` | `BOOKING_SUPPORT_*` | | Guest-facing copy |

## `config/services.php → stripe`

| Key | Env | Notes |
|---|---|---|
| `key` | `STRIPE_KEY` | Publishable |
| `secret` | `STRIPE_SECRET` | Secret |
| `webhook_secret` | `STRIPE_WEBHOOK_SECRET` | Endpoint **signing** secret, not the API key |
| `webhook_tolerance` | `STRIPE_WEBHOOK_TOLERANCE` | 300s |
| `api_version` | `STRIPE_API_VERSION` | **Leave unset.** Null uses the SDK's own pinned version, which its typed objects are written against |

## Schema added

| Table | Purpose |
|---|---|
| `bookings` | Our record + money lifecycle. Lodgify owns the reservation |
| `booking_payments` | One row per requested payment. `UNIQUE(booking_id, type)` |
| `stripe_webhook_events` | Webhook dedup + replayable payloads |
| `booking_audit_logs` | Append-only trail |

Removed: `checkout_intents` (see the table at the top of this document).

---

# Part 8 — Testing

**136 tests, 551 assertions, all passing.** `php artisan test`

| Suite | Covers |
|---|---|
| `MoneyTest` | Float rounding (incl. a guard asserting the naive cast is still wrong), zero-decimal rejection, currency mixing, deposit/balance reconciliation |
| `DepositPolicyTest` | Schedule reading, `is_current` preference, **refusal** when no schedule, over-total instalment rejection, full-payment window, pay-in-full schedules |
| `BookingStatusTest` | No backwards transitions from paid, terminality, and that `awaiting_deposit` does **not** hold dates |
| `BookingTransitionTest` | Compare-and-swap under concurrency, illegal transitions, reference format |
| `BookingAuditorTest` | Secret redaction at depth, object summarising, immutability |
| `CreateBookingTest` | Open reservation + queued link, **client cannot influence price**, double-submit, Lodgify failure charges nothing, imminent-stay single payment, quote snapshot |
| `StripeWebhookTest` | No/forged/stale signature, missing secret, happy path, **replay is a no-op**, **amount mismatch refuses to confirm**, async payments, unknown events, refunds |
| `PaymentLinkAccessTest` | Unsigned/tampered/expired signature rejection, cross-token substitution, token entropy, already-paid receipt |
| `LodgifyConfirmationJobTest` | Success, no-op when already booked, rethrow for retry, **alert on exhaustion**, and still-recorded when no alert address |
| `SweeperTest` | Balance windows, hourly idempotency, zero balance, release of unpaid reservations, and **never releasing a booking that was just paid** |
| `BookingEndpointTest` | Honeypot, terms, date rules, feature-flag fallback, rate limiting, failed submissions landing back on the form |
| `BookingDetailsPageTest` | Server-side pricing, price-drift warning, refusal when unpriceable, date validation, user prefill, CSRF + honeypot present |
| `BookButtonTargetTest` | **Regression:** the cottage page's Book button points at our details step with the flag on, and at Lodgify with it off |
| `EndToEndFlowTest` | The whole journey through real routes: cottage page → details → reserve → signed link → webhook → confirmed → balance link |
| `LodgifyWriteResponseShapeTest` | **Regression:** bare-integer / quoted-string / object / wrapped-object create responses, 2xx-with-no-id failing loudly, money-at-risk severity, and no transport-level retry on writes |
| `ReconcileOrphansTest` | Report-vs-link, confident match, refusing an ambiguous or someone-else's reservation, email disambiguation, and not reviving a `failed` booking |
| `NoLodgifyCheckoutTest` | The hosted-checkout classes, routes, table, column and settings are all absent — and the read-only `checkout.lodgify.com` fallback is deliberately kept |
| `PhantomPropertyTest` | Properties the list endpoint advertises but the detail endpoint 404s: dropped not half-rendered, negatively cached, and not retried |

Note `tests/TestCase.php` enables the feature flag, injects fake Stripe credentials, and
calls `withoutVite()` (views use `@vite`, and the suite asserts on copy rather than asset
bundling).

## Local Stripe testing

```bash
stripe login
stripe listen --forward-to localhost:8000/webhooks/stripe   # prints whsec_… → .env
stripe trigger checkout.session.completed
```

Use test cards `4242 4242 4242 4242` (success) and `4000 0000 0000 0002` (declined). To
exercise the async path, use a delayed method rather than a card.

---

# Part 9 — Rollout checklist

## Before enabling

- [x] ~~Verify the Lodgify create contract~~ — **confirmed working with the default field map**; the endpoint returns a bare integer id. The probe command remains available if the contract ever changes.
- [ ] Confirm `PUT /v1/reservation/booking/{id}/book` works, and **check in the Lodgify dashboard that the calendar actually blocks afterwards.** Still unverified, and it is the step that prevents double bookings.
- [ ] Run `php artisan booking:reconcile-orphans` once, to catch anything stranded by the earlier TypeError.
- [ ] `STRIPE_SECRET` + `STRIPE_WEBHOOK_SECRET` set (test keys first)
- [ ] Webhook endpoint registered in Stripe for: `checkout.session.completed`, `checkout.session.async_payment_succeeded`, `checkout.session.async_payment_failed`, `checkout.session.expired`, `charge.refunded`
- [ ] `BOOKING_ALERT_EMAIL` set and deliverable — **test it**
- [ ] `MAIL_MAILER` is a real transport (`.env.example` ships `log`; and note the enquiry notification mails elsewhere are still commented out — finding **F12**)
- [ ] A **queue worker is running.** The Lodgify confirm and the link emails are queued; without a worker, paid bookings are never confirmed. `QUEUE_CONNECTION=database` works but Redis + Horizon is better
- [ ] The **scheduler is running** (`schedule:run` every minute), or no balance link is ever sent and nothing is ever released
- [ ] End-to-end test in Stripe test mode: book → pay → confirm the reservation flips to `Booked` in the Lodgify dashboard
- [ ] Deliberately break it: point `LODGIFY_MARK_BOOKED_PATH` at a bad path, pay, and confirm the alert email arrives

## There is nothing to enable

The flow is live as soon as Stripe credentials are configured — there is no flag. Which
means the checklist above is not optional: **without a queue worker, a booking is created
and the guest never receives a payment link.**

If you need to stop taking bookings, remove `STRIPE_SECRET`. `StripeGateway` then throws
`StripeNotConfigured`, and the guest is told to call you rather than being shown a broken
payment page.

## Also apply from the security review

This feature does not fix the pre-existing findings in
[`03-security.md`](03-security.md), and two are now more pressing because money is
involved:

- **F2** — no security headers. A CSP and `Referrer-Policy` matter more now that payment
  URLs exist; `Referrer-Policy` stops a signed link leaking in a `Referer` header.
- **F9** — no `TrustProxies`. Behind a load balancer every rate limiter collapses to one
  bucket, including the booking-creation limit added here.

---

# Part 10 — Known risks and limits

### The `Open` window is real exposure

Between reservation creation and deposit payment the booking is `Open`, and **an `Open`
Lodgify reservation does not block the calendar**. Two guests can hold the same nights
until one pays. This is inherent to the chosen "booking first, then a link" flow, not a
bug.

Mitigations in place: `deposit_link_ttl_hours` bounds the window (48h default), the sweeper
releases lapsed reservations, and the guest is told plainly on the confirmation page and in
the email that their dates are not yet held. If double-bookings occur in practice, the
lever is a shorter TTL — or moving to in-session payment, which removes the window
entirely.

### Phantom properties are dropped from the site

Lodgify's `/v2/properties` list advertises ids that `/v2/properties/{id}` 404s (deleted or
orphaned records still in the index). Those cottages are now **excluded** rather than
rendered from the thin list payload, because a list-only cottage has no `rooms[]` — so no
room id, no rate calendar, no quote, and a booking payload Lodgify cannot honour. A cottage
that appears but cannot be booked is worse than one that is absent.

A transient failure (5xx, timeout) still falls back to the list entry, which is the right
behaviour there. `php artisan tinker` or `/debug/lodgify` → `phantom_properties` lists the
offending ids so they can be cleaned up in the Lodgify dashboard.

`BookingCreator` additionally refuses outright to book a cottage whose `primaryRoomId()` is
null, so this can never reach the point of taking a guest's details for an impossible
reservation.

### Payment recording in Lodgify is unverified
Until `LODGIFY_RECORD_PAYMENT_PATH` is confirmed, the Lodgify dashboard will show an
outstanding balance on bookings that are fully paid. Our records are correct; Lodgify's
`amount_paid` is not. Anyone working from the dashboard needs to know this.

### Refunds are not automated
`charge.refunded` is recorded but does not cancel the reservation or release dates — by
design, since a partial refund and a cancellation look identical. Cancellation is a manual
admin action, and there is no admin UI for it yet.

### No admin UI for bookings
`bookings`, `booking_payments` and `booking_audit_logs` have no screens. Nothing surfaces
`needsAttention()` bookings in the admin area, so the alert email is currently the only
route to a stuck booking. That is the most valuable follow-up.

### Not implemented
- No partial/custom payment amounts, payment plans beyond deposit+balance, or multi-currency
- No guest-facing "manage my booking" page (bookings are not linked to `/my-stays`, which
  reads live from Lodgify by verified email)
- No reconciliation job comparing our `booking_payments` against Stripe, or our bookings
  against Lodgify reservations
- `checkout_intents` conversion tracking is still unwired (see
  [`01-architecture.md`](01-architecture.md) Stage 7) — this feature makes it partly moot
  for direct bookings, but the table still never records a conversion
