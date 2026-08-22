# Ocean Escape Cottages — Engineering Documentation

Reference documentation for the `oceanescape` codebase, written from a full read of
the source at commit time. Everything here describes **what the code actually does**,
not what it was intended to do; where the two differ, the gap is called out explicitly.

## What this application is

A server-rendered marketing-and-booking website for a six-cottage oceanfront rental
business in Lockeport, Nova Scotia. The distinguishing architectural fact is that
**this application owns almost none of its own core data**:

| Domain data | System of record |
|---|---|
| Properties, photos, amenities, descriptions | **Lodgify** (PMS/channel manager) |
| Availability calendars, min-stay rules | **Lodgify** |
| Nightly rates, seasons, fees, taxes, VAT | **Lodgify** |
| Reservations / bookings | **Lodgify** |
| Payment collection & checkout | **Stripe, on this site.** Lodgify's hosted checkout has been removed entirely — see [`05-payments-and-booking.md`](05-payments-and-booking.md) |
| Guest reviews & ratings | **Google Places API (New)** |
| Users, admin accounts, sessions | This app (SQLite/MySQL) |
| Corporate ("business stay") enquiries | This app |
| Contact-form messages | This app |
| Guest-submitted photos + moderation | This app |
| Bookings taken on this site + their payments | This app (Lodgify still owns the *reservation* itself) |

So the app is best understood as a **read-heavy presentation and anti-corruption
layer over a third-party API**, with a small amount of genuinely local
lead-capture / CMS-lite data alongside it.

## The documents

| File | What it covers |
|---|---|
| [`01-architecture.md`](01-architecture.md) | The architecture as implemented — layers, patterns, dependency graph, file inventory, known structural defects — followed by a recommended target architecture and a staged migration path. |
| [`02-page-flows.md`](02-page-flows.md) | Every route in the application, traced end to end: URL → middleware → controller method → service methods → HTTP calls to Lodgify → cache keys → DTOs → Blade view → Alpine component → follow-up XHR. |
| [`03-security.md`](03-security.md) | Security controls actually applied (with file/line evidence), then ranked findings and concrete hardening recommendations. |
| [`04-caching-database-performance.md`](04-caching-database-performance.md) | The full cache-key inventory and TTL map, cache-correctness invariants, the database schema and index review, measured hot paths, and a prioritised performance-improvement plan. |
| [`05-payments-and-booking.md`](05-payments-and-booking.md) | The direct booking + Stripe payments flow: step-by-step lifecycle, the idempotency guarantees and how each is enforced, the security model around the webhook, the audit trail, the unverified Lodgify write contract and how to verify it, and a rollout checklist. |

## Tech stack (from `composer.json` / `package.json`)

- **PHP** ^8.3, **Laravel** ^13.17
- **Frontend**: Blade templates, Alpine.js ^3.16, Tailwind CSS ^4, Vite ^8, `laravel-vite-plugin` ^3.1
- **Database**: SQLite by default (`DB_CONNECTION=sqlite`); the `database` driver also
  backs cache, sessions and queues
- **Tooling**: Pint (formatting), PHPUnit ^12.5, Pail (log tailing), Tinker

## Quick orientation for a new contributor

```
routes/web.php                      every URL in the app (single file, 224 lines)
bootstrap/app.php                   kernel config: routing, middleware alias, exceptions
app/Http/Controllers/               thin controllers; almost no business logic
app/Services/Booking/               booking orchestration, deposit policy, audit
app/Services/Payments/              Stripe gateway, payment links, settlement
app/Services/Lodgify/               the whole Lodgify integration (~4,100 lines)
  LodgifyClient.php                 raw HTTP transport + endpoint knowledge
  LodgifyRepository.php             caching, mapping, availability/rate/quote logic
  ReservationRepository.php         read-only reservation access
  PropertyImageResolver.php         multi-strategy photo gallery resolution
  LodgifyCheckout.php               builds the hosted-checkout handoff URL
app/Services/Google/                Google Places reviews
app/DTO/                            immutable view-facing shapes (Cottage, RateDay, …)
app/Models/                         Eloquent models for locally-owned data only
resources/views/pages/              one Blade file per page
resources/js/                       three Alpine components
config/lodgify.php                  the single most important config file to read
config/booking.php                  the booking + payment flow, heavily commented
```

**Read `config/lodgify.php` first.** It is heavily commented and encodes most of the
hard-won knowledge about Lodgify's inconsistent API surface (four different hostnames,
three different parameter-casing conventions, endpoints locked to dashboard sessions).
