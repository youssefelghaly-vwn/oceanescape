# Architecture — As Implemented, and As Recommended

- [Part 1 — The architecture as it exists today](#part-1--the-architecture-as-it-exists-today)
- [Part 2 — Known structural defects](#part-2--known-structural-defects)
- [Part 3 — Recommended architecture](#part-3--recommended-architecture)
- [Part 4 — Staged migration path](#part-4--staged-migration-path)

---

# Part 1 — The architecture as it exists today

## 1.1 One-line summary

A conventional **layered Laravel MVC monolith** (routes → controller → service → DTO →
Blade), where the "model" layer is split in two: an **anti-corruption layer over the
Lodgify REST API** for all core booking data, and ordinary **Eloquent models** for the
small set of data the site genuinely owns.

## 1.2 Layer diagram

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ BROWSER                                                                      │
│   Blade-rendered HTML  +  Alpine.js islands                                  │
│   ├── bookingSearch      (resources/js/components/booking-search.js)         │
│   ├── cottageCalendar    (resources/js/cottage-calendar.js)                  │
│   └── imageLightbox      (resources/js/image-lightbox.js)                    │
│   Alpine fetches JSON from the app's own /api/* routes after first paint      │
└───────────────────────────────┬──────────────────────────────────────────────┘
                                │ HTTP (web middleware group: session, CSRF, cookies)
┌───────────────────────────────▼──────────────────────────────────────────────┐
│ ROUTING            routes/web.php   (single file — public, api, auth, admin)  │
│ KERNEL             bootstrap/app.php                                          │
│                      • middleware alias  admin => EnsureUserIsAdmin           │
│                      • exceptions: render JSON when is('api/*')||expectsJson  │
│                      • health endpoint  /up                                   │
│ MIDDLEWARE         auth · guest · verified · signed · throttle:N,M · admin     │
└───────────────────────────────┬──────────────────────────────────────────────┘
┌───────────────────────────────▼──────────────────────────────────────────────┐
│ HTTP LAYER                                                                    │
│   Controllers (thin — orchestration + response shaping only)                  │
│     public : Home · Cottage · Availability · Rate · Gallery · Review          │
│              Contact · GuestPhoto · BusinessStay · BookingRedirect            │
│     auth   : Login · Register · ForgotPassword · ResetPassword · Profile       │
│     admin  : BusinessStayRequest · ContactMessage · GuestPhoto                │
│              Reservation · CheckoutIntent                                     │
│     debug  : DebugController  (local/staging only)                            │
│   FormRequests  StoreBusinessStayRequest · StoreContactMessageRequest ·        │
│                 StoreGuestPhotoRequest   (validation + honeypots)             │
└───────────────┬──────────────────────────────────────┬───────────────────────┘
                │                                      │
┌───────────────▼──────────────────────┐  ┌────────────▼───────────────────────┐
│ SERVICE LAYER (remote data)          │  │ DOMAIN LAYER (local data)          │
│                                      │  │                                    │
│  LodgifyRepository      1,924 ln     │  │  Eloquent models                   │
│    ├─ caching (rememberArray)        │  │    User                            │
│    ├─ failure isolation  safe()      │  │    BusinessStayRequest  (softdel)  │
│    ├─ raw JSON → DTO mapping         │  │    ContactMessage       (softdel)  │
│    └─ availability / rates / quotes  │  │    GuestPhoto           (softdel)  │
│  ReservationRepository    452 ln     │  │    CheckoutIntent                  │
│  PropertyImageResolver    449 ln     │  │                                    │
│  LodgifyCheckout          113 ln     │  │  Enums (string-backed, with        │
│  GoogleReviewsService     289 ln     │  │   label()/classes()/options())     │
│                                      │  │    BusinessStayStatus              │
│  ── DTOs (immutable, readonly) ──    │  │    ContactMessageStatus            │
│    Cottage · AvailabilityDay ·       │  │    GuestPhotoStatus                │
│    RateDay · RateSeason · Reservation │  │                                    │
└───────────────┬──────────────────────┘  └────────────┬───────────────────────┘
                │                                      │
┌───────────────▼──────────────────────┐  ┌────────────▼───────────────────────┐
│ TRANSPORT                            │  │ PERSISTENCE                        │
│  LodgifyClient    1,185 ln           │  │  SQLite / MySQL                    │
│   • http()        X-ApiKey → v1/v2   │  │  Tables: users · sessions · cache  │
│   • publicHttp()  browser headers    │  │   cache_locks · jobs · job_batches │
│                   → checkout.* (CF)  │  │   failed_jobs · password_reset_*   │
│  Illuminate\Http (Guzzle) + retry    │  │   business_stay_requests           │
│                                      │  │   contact_messages · guest_photos  │
│  Cache (database driver by default)  │  │   checkout_intents                 │
│  Storage: local (private) + public   │  │   cottage_availability_days ← DEAD │
└───────────────┬──────────────────────┘  └────────────────────────────────────┘
                │
┌───────────────▼──────────────────────────────────────────────────────────────┐
│ EXTERNAL SYSTEMS                                                             │
│  api.lodgify.com          v1 + v2, authenticated with X-ApiKey               │
│  checkout.lodgify.com     public /api/v1, unauthenticated, behind Cloudflare  │
│  property.lodgify.com     /api/v3 images — needs a DASHBOARD SESSION cookie   │
│  rates.lodgify.com        /api/v2 addons — session-locked, always 401/403     │
│  places.googleapis.com    Places API (New) — reviews, rating, photos          │
│  l.icdbcdn.com            Lodgify image CDN (f= transform presets)            │
└──────────────────────────────────────────────────────────────────────────────┘
```

## 1.3 Architectural patterns in use

### Repository + Anti-Corruption Layer
`LodgifyRepository` is the only class the rest of the app talks to for property data.
It absorbs every inconsistency of the upstream API — four hostnames, PascalCase vs
camelCase vs dot-notation query parameters, endpoints that return HTTP 200 with
`success: false` — and hands out clean DTOs. This is the single best decision in the
codebase: Lodgify's API is genuinely hostile, and nothing outside `app/Services/Lodgify/`
has to know that.

### DTOs as the view contract
`app/DTO/*` are `readonly` constructor-promoted value objects. `app/DTO/Cottage.php`
carries a documented comment: *"Views only ever see this shape, never raw Lodgify JSON."*
Blade templates bind to `$cottage->maxGuests`, never `$raw['max_people']`.

### Cache-primitives-only invariant
`LodgifyRepository` documents two hard rules at the top of the file, and enforces one of
them in code. `rememberArray()` (`LodgifyRepository.php:816`) throws a `LogicException`
if a cache callback ever returns an object:

```php
if (is_object($value)) {
    throw new \LogicException('LodgifyRepository cache callback returned an object…');
}
```

Rationale: DTOs are rebuilt on every read from cached arrays, so changing a DTO
signature can never poison the cache with `__PHP_Incomplete_Class`. This is paired with
per-class `CACHE_VERSION` constants (`v3` in `LodgifyRepository`, `v2` in
`PropertyImageResolver`, `v1` in `ReservationRepository`) prefixed onto every key, so a
shape change is invalidated by bumping a constant instead of by a deploy-time
`cache:clear`.

### Failure isolation / graceful degradation
The second documented rule: *"ONE FAILING COTTAGE MUST NOT TAKE DOWN THE WHOLE
CALENDAR."* Every upstream call goes through `safe()` (`LodgifyRepository.php:787`),
which catches `LodgifyApiException` and `Throwable`, logs at `warning`, appends to
`$lastErrors`, and returns `null`.

Callers then read `$lodgify->lastErrors()` and set a `$degraded` flag which the views
render as a soft banner. The JSON endpoints go further and **never return a non-200 for
an upstream failure** — `AvailabilityController::month()` and `RateController::month()`
both catch `Throwable` and return `200` with `days: []` and `degraded: true`, so the
calendar widget still renders a usable (if priceless) month grid.

A companion channel exists for user-facing upstream rejections: `$lastGuestMessage` is
kept separate from `$lastErrors` because Lodgify's 4xx bodies sometimes carry copy worth
repeating verbatim ("The minimum stay for this rental is 6 days"). `RateController::quote()`
uses its presence to distinguish `reason: 'rejected'` from `reason: 'error'` — a genuinely
good distinction that most codebases collapse into "unavailable".

### Strategy pattern, configured by env
Two pluggable resolution chains, both ordered lists tried until one succeeds:

- **Images** — `lodgify.image_strategies`, default `manifest,local,api`
  (also available: `api_v3`, `scrape`). Implemented in `PropertyImageResolver::resolve()`.
  Notable subtlety: a strategy is only accepted if it yields **more than one** image, and
  a single-image result is deliberately *not* cached, so a thin `/v2/properties` list
  payload (which carries only `image_url`) cannot poison the gallery cache for 6 hours.
- **Add-ons** — `lodgify.addon_strategies`, default `api,manifest`, where `manifest`
  reads `config/lodgify-addons.php` (currently empty, all examples commented out).

### Multi-tier search fallback
`AvailabilityController::search()` implements a three-tier degradation so the results
page is never a dead end:

1. **exact** — `cottagesFreeFor($arrival, $departure)`
2. **nearby** — `nearbyMatches()`, same *length* of stay shifted ±N days (default 14)
3. **alternate** — `alternativeStays()`, closest bookable window of *any* length (±30 days)

Tier 3 is only computed when tier 1 or tier 2 came back empty. Each tier rejects cottages
already surfaced by an earlier tier, and all three are filtered by a `$fitsParty` closure
(occupancy + pet policy).

### Hosted-checkout handoff instead of a local booking write
`LodgifyCheckout` builds a URL into `checkout.lodgify.com/{slug}/{propertyId}/addons`
and the app calls `redirect()->away($url)`. The class docblock states the reasoning:
rebuilding checkout locally would mean an unverified booking write, PCI scope, and
reconciling two systems, for the same guest experience. It also records an operational
warning worth preserving — the Stripe configuration is bound to Lodgify *website* id
`623105`, which may be taken offline but **must not be deleted**.

Because the handoff loses sight of the guest, `checkout_intents` exists to answer the
three questions Lodgify cannot: attribution (did this booking come from the new site),
abandonment (who reached checkout and didn't finish), and what-we-showed (the total on
our summary, for divergence checks). The migration is explicit that this is *not* a
booking record — Lodgify owns the booking.

### Progressive enhancement, not an SPA
Pages render fully server-side. Alpine components hydrate from `@js(...)` payloads and
then fetch richer data from the app's own `/api/*` endpoints. The `/api/*` routes exist
only to serve these widgets — there is no public API, no API authentication, and no
`routes/api.php`; they live in `routes/web.php` inside the `web` middleware group.

`resources/js/app.js` carries an important, correct comment: **every** Alpine component
must be registered before `Alpine.start()`, because `start()` walks the DOM and
evaluates all `x-data` expressions in one pass; anything registered later is invisible to
elements already on the page.

## 1.4 Dependency injection

`bootstrap/providers.php` registers `AppServiceProvider` (empty) and
`LodgifyServiceProvider`. The latter does three things:

```php
$this->mergeConfigFrom(__DIR__.'/../../config/lodgify.php', 'lodgify');
$this->app->singleton(LodgifyClient::class);
$this->app->singleton(LodgifyRepository::class);
```

Everything else — `PropertyImageResolver`, `ReservationRepository`, `LodgifyCheckout`,
`GoogleReviewsService` — is **not** registered and is resolved by container auto-wiring,
producing a **fresh instance per resolution**. Consequences:

- `PropertyImageResolver` is constructor-injected into the *singleton* `LodgifyRepository`,
  so in practice it is a de-facto singleton too. Fine.
- `ReservationRepository` is injected into `ProfileController` and
  `Admin\ReservationController`; each gets its own instance. Harmless today because its
  state is all in the shared cache, but it means `LodgifyRepository`'s `$lastErrors` /
  `$lastGuestMessage` mutable state is **request-scoped and shared** across every
  controller in a request. That accumulator design is why `lastErrors()` must be read
  immediately after the operation that produced it — and every caller does.

Controllers use constructor promotion for injection (`public function __construct(protected
LodgifyRepository $lodgify) {}`), which is consistent throughout.

## 1.5 Notable design choices worth keeping

| Choice | Where | Why it's right here |
|---|---|---|
| No local mirror of reservations | `ReservationRepository` docblock | Lodgify is a *channel manager*; bookings arrive from Airbnb, Booking.com and the phone. Any local copy is stale the moment it's written. |
| Filtering/sorting/paging in PHP | `ReservationRepository::search()`, `Admin\ReservationController::index()` | Lodgify's filter and paging support is unverified; six cottages produce a small enough set that in-memory filtering is simpler *and* lets filters work across the whole set. |
| `uniqueSlug()` appends the property id | `LodgifyRepository:1870` | Ocean Escape has two *pairs* of identically-named cottages; a name-only slug collides and makes the duplicates unreachable. |
| Pending photo uploads on the **private** disk | `GuestPhotoController::store()`, `guest_photos` migration | Nothing unmoderated is ever reachable by URL. Promotion to the public disk happens only on human approval, and only *after* the copy succeeds. |
| Login errors are deliberately uninformative | `Auth\LoginController::store()` | One message for wrong-email and wrong-password prevents account enumeration. `ForgotPasswordController` does the same. |
| Reference codes exclude lookalike characters | `BusinessStayRequest::generateReference()` | `BS-7K2QMD` survives being read aloud over the phone. |
| Add-on quantity is *not* multiplied by nights | `LodgifyCheckout::formatAddons()` | Observed behaviour: Lodgify applies the add-on's own frequency itself. Multiplying locally double-charged a `PerNight` add-on. |

---

# Part 2 — Known structural defects

These are defects in the architecture *as committed*, found by reading the source. They
are ordered by severity.

### D1 — The scheduler references a class that does not exist ❗

`routes/console.php`:

```php
use App\Console\Commands\SyncCottageAvailability;

Schedule::command(SyncCottageAvailability::class)
    ->everyThirtyMinutes()->withoutOverlapping()->onOneServer();
```

There is **no `app/Console/` directory** in the repository. `Schedule::command()` with a
non-existent class will fail when the console routes are loaded, which happens on **every
`artisan` invocation** — including `schedule:run`, `migrate`, `config:cache` and
`php artisan test`. This is not a latent risk; it is a hard breakage of the console
entrypoint. Either implement the command or remove the schedule entry.

### D2 — Dead schema: `cottage_availability_days`

`database/migrations/2026_08_13_173909_create_cottage_availability_days_table.php`
creates a well-designed table (`property_id`, `date`, `is_available`, `minimal_stay`,
`is_check_in_available`, `is_check_out_available`, `synced_at`, unique on
`[property_id, date]`). **Nothing reads or writes it** — there is no model, no query, no
reference anywhere in `app/`.

Taken together with D1, the intent is legible: availability was meant to be
*synced into the local database* every 30 minutes and read from there, rather than
fetched from Lodgify on request and cached. That is the right architecture (see
[§3.3](#33-move-availability-from-request-time-fetch-to-a-synced-read-model)) and it is
half-built. The table is the finished half.

### D3 — Duplicated route groups

`routes/web.php` opens `Route::middleware(['auth','admin'])->prefix('admin')->name('admin.')`
**three separate times** (lines 127, 175, 220). The admin surface is therefore split
across three non-adjacent blocks with the same declaration repeated. Purely a
maintainability issue, but it makes it easy to add an admin route to the wrong block or
forget the middleware entirely.

### D4 — `config/services-google.php` is not a config file

It is a copy-paste snippet whose header says *"Add to config/services.php"*. Because
Laravel loads every file in `config/`, it *does* become `config('services-google.*')` —
a key nothing reads. The real `config/services.php` `google` block only defines
`maps_key`, `place_id` and `cache_ttl`.

The consequence is a live (if minor) bug: `GoogleReviewsService` reads
`config('services.google.max_photos')` (line 169) and
`config('services.google.excerpt_words')` (line 238), **neither of which is defined in
`config/services.php`**. Both silently fall back to their hardcoded defaults (12 and 38),
and the `GOOGLE_MAX_PHOTOS` / `GOOGLE_EXCERPT_WORDS` environment variables are inert.

### D5 — `.env.example` documents none of the app's own configuration

`.env.example` is the stock Laravel skeleton. It contains **no** `LODGIFY_*` key
(not even `LODGIFY_API_KEY`), no `GOOGLE_MAPS_API_KEY` / `GOOGLE_PLACE_ID`, and no
`ADMIN_EMAIL` / `ADMIN_PASSWORD` — despite `AdminUserSeeder` refusing to run without the
last two, and the whole site being non-functional without the first. A fresh clone cannot
be configured without reading `config/lodgify.php` end to end.

### D6 — `protected $guarded = []` on every local model

`BusinessStayRequest`, `CheckoutIntent`, `ContactMessage` and `GuestPhoto` all disable
mass-assignment protection entirely. No current call site is exploitable — every
`create()` passes either `$request->validated()`, `$request->safe()->except(...)`, or an
explicit literal array — but it removes the safety net that makes those call sites safe
by construction rather than by review. See [`03-security.md` §F4](03-security.md).

### D7 — Test suite is the untouched skeleton

`tests/Feature/ExampleTest.php` asserts `GET /` returns 200; `tests/Unit/ExampleTest.php`
asserts `true`. There is **no test coverage** of the Lodgify mappers, the cache-key
scheme, the three-tier search fallback, the quote parsers, the photo moderation
state machine, or the admin authorization boundary. Given that the mappers are explicitly
documented as guessing at unverified upstream field names, this is the highest-leverage
gap in the repository.

### D8 — Two large classes are doing too much

`LodgifyRepository` is 1,924 lines and 60 methods spanning six responsibilities
(cottages, availability, pricing, add-ons, images, seasons, plus caching and mapping
helpers for all of them). `LodgifyClient` is 1,185 lines and mixes production transport
with a substantial probe/diagnostic toolkit (`diagnose()`, `probeRatesCalendar()`,
`probePhotos()`, `probeBookings()`, `harvestV3Ids()`, `harvestImageUrls()`, `raw()`).
The diagnostic code is genuinely valuable — it is how the API's real shapes were
discovered — but it is production-loaded on every request.

---

# Part 3 — Recommended architecture

The recommendation is **not** a rewrite. The layering, the ACL boundary, the DTO
contract and the failure-isolation discipline are all correct and should be preserved.
What follows fixes the defects above and addresses the one genuine architectural
weakness: **availability and rate data is fetched from a slow third-party API inside the
request cycle, and the only thing standing between a visitor and a 15-second timeout is
a short-TTL cache.**

## 3.1 Target diagram

```
┌──────────────────────────────────────────────────────────────────────────────┐
│ BROWSER — Blade + Alpine (unchanged)                                          │
└───────────────────────────────┬──────────────────────────────────────────────┘
┌───────────────────────────────▼──────────────────────────────────────────────┐
│ ROUTING   routes/web.php  ·  routes/admin.php  ·  routes/api-internal.php    │
│           (split by audience; one admin group, declared once)                 │
│ MIDDLEWARE  + TrustProxies  + SecurityHeaders (CSP/HSTS/X-CTO/Referrer)      │
│             + named RateLimiter definitions (replace inline throttle:N,M)    │
└───────────────────────────────┬──────────────────────────────────────────────┘
┌───────────────────────────────▼──────────────────────────────────────────────┐
│ HTTP LAYER — controllers stay thin; Policies replace ad-hoc ownership checks │
│   + ReservationPolicy   (owns the "is this booking mine?" rule)              │
│   + GuestPhotoPolicy    (owns approve/reject/destroy)                        │
│   + API Resources for the /api/* JSON shapes (replace hand-built arrays)     │
└───────────────────────────────┬──────────────────────────────────────────────┘
┌───────────────────────────────▼──────────────────────────────────────────────┐
│ READ MODEL (local, authoritative for reads)          ◄── the key change      │
│   cottage_availability_days   ← synced every 15–30 min                       │
│   cottage_rate_days           ← synced every 15–30 min                       │
│   cottage_snapshots           ← name/photos/amenities, synced hourly         │
│                                                                              │
│   AvailabilityReader · RateReader   (plain SQL/Eloquent, no HTTP, no timeout) │
└───────────────────────────────┬──────────────────────────────────────────────┘
┌───────────────────────────────▼──────────────────────────────────────────────┐
│ SYNC LAYER (queued, out of band)                                             │
│   SyncCottagesCommand           hourly                                       │
│   SyncAvailabilityCommand       every 15 min   → dispatches per-cottage jobs │
│   SyncRateCalendarCommand       every 30 min   → dispatches per-cottage jobs │
│   SyncReservationsCommand       every 5 min                                  │
│   Each job: fetch → validate → upsert → stamp synced_at → never partial-write │
└───────────────────────────────┬──────────────────────────────────────────────┘
┌───────────────────────────────▼──────────────────────────────────────────────┐
│ SERVICE LAYER — LodgifyRepository decomposed by responsibility               │
│   PropertyCatalogue · AvailabilityService · PricingService                   │
│   AddonService · ImageGalleryService · ReservationService                    │
│   Live-only (never cached, correctness-critical): QuoteService               │
│                                                                              │
│   LodgifyClient  →  split into LodgifyApiClient (prod)                       │
│                              + LodgifyProbe    (dev-only, own provider)      │
└───────────────────────────────┬──────────────────────────────────────────────┘
┌───────────────────────────────▼──────────────────────────────────────────────┐
│ INFRA   Redis (cache + sessions + queue + tags)  ·  MySQL/Postgres           │
│         Horizon  ·  structured logging  ·  Sentry/Flare                      │
└──────────────────────────────────────────────────────────────────────────────┘
```

## 3.2 Fix the defects first (do this before anything else)

| # | Action | Effort |
|---|---|---|
| D1 | Implement `SyncCottageAvailability` **or** delete the `Schedule::command()` block. The console entrypoint is broken until one of these happens. | 30 min (delete) / 1 day (implement) |
| D4 | Move the missing keys into `config/services.php`; delete `config/services-google.php`. | 15 min |
| D5 | Add every `LODGIFY_*`, `GOOGLE_*` and `ADMIN_*` key to `.env.example` with safe defaults and one-line comments. | 30 min |
| D3 | Consolidate the three admin route groups into one; consider extracting `routes/admin.php`. | 30 min |
| D6 | Replace `$guarded = []` with explicit `$fillable` on all four models. | 1 h |
| D7 | Add feature tests for the admin authorization boundary, the `verified` gate on `/my-stays`, and the photo moderation state machine; add unit tests for `mapCottage()`, `normaliseRateCalendar()`, `parseV2Quote()` and `mapReservation()` against captured fixture payloads. | 3–5 days |

## 3.3 Move availability from request-time fetch to a synced read model

**This is the single highest-value architectural change**, and the codebase is already
half-way there (defect D2 — the table exists).

### The problem today

`GET /cottages` calls `cottagesWithOpenings()` → `allCottages()` → `cottageRaw($id)` per
cottage (2 HTTP calls each: `/v2/properties/{id}` + `/v1/properties/{id}/rooms/{rid}`)
→ then `freeWindows()` per cottage → `cottageAvailability()` per cottage (1 more HTTP
call each). **On a cold cache that is roughly 19 sequential HTTP round-trips to Lodgify
before the first byte of HTML.** With `lodgify.timeout` at 15 s and `retries` at 2, the
theoretical worst case is minutes; the practical worst case is a visibly slow page and,
under concurrency, a cache stampede where every simultaneous visitor triggers the same
19 calls.

`aggregateAvailability()` (behind `/api/availability/month`, TTL 300 s) has the same
shape and is called by the site-wide search widget on **every page that renders it**.

### The fix

Invert the direction of data flow. Instead of *pull on request, cache the result*, do
*push on schedule, read locally*:

```php
// app/Console/Commands/SyncAvailability.php
public function handle(PropertyCatalogue $catalogue): int
{
    foreach ($catalogue->all() as $cottage) {
        SyncCottageAvailabilityJob::dispatch($cottage->id);   // one job per cottage
    }
    return self::SUCCESS;
}

// app/Jobs/SyncCottageAvailabilityJob.php
public function handle(LodgifyApiClient $client): void
{
    $rows = $client->getAvailability($this->propertyId, $from, $to);

    // Never partial-write: an empty upstream response must not blank the calendar.
    if ($rows === []) {
        Log::warning('Availability sync returned nothing; keeping previous data', [...]);
        return;
    }

    DB::transaction(fn () => CottageAvailabilityDay::upsert(
        $this->rows($rows),
        ['property_id', 'date'],                      // the unique key already exists
        ['is_available', 'minimal_stay', 'is_check_in_available',
         'is_check_out_available', 'synced_at']
    ));
}
```

Then `AvailabilityService` reads SQL, not HTTP:

```php
public function aggregate(string $from, string $to): Collection
{
    return CottageAvailabilityDay::query()
        ->whereBetween('date', [$from, $to])
        ->selectRaw('date,
                     sum(is_available)                 as available_count,
                     min(nullif(minimal_stay, 0))      as min_stay,
                     max(is_check_in_available)        as ci,
                     max(is_check_out_available)       as co,
                     count(*)                          as total')
        ->groupBy('date')
        ->get()
        ->mapWithKeys(fn ($r) => [$r->date => AvailabilityDay::fromRow($r)]);
}
```

**What this buys:**

- `/api/availability/month` goes from *up to 7 HTTP calls* to **one indexed GROUP BY** —
  single-digit milliseconds, and no timeout path at all.
- Zero cache-stampede risk: concurrent visitors read the same committed rows.
- Lodgify call volume becomes **constant and predictable** (a fixed number of syncs per
  hour) instead of proportional to traffic and inversely proportional to cache hit rate.
- Staleness becomes **explicit and observable** via `synced_at`, instead of implicit in
  a TTL. You can render "availability as of 14:32" and alert when `synced_at` goes stale.
- The `degraded` flag stops being a per-request accident and becomes a real health signal.

**What must stay live and uncached:** the **quote**. `RateController::quote()` produces
the number a guest is about to pay. The current 60 s TTL (`lodgify.cache.quote`) is
already an uncomfortable compromise; do not extend it, and prefer dropping it to 0 once
availability no longer competes for the same request budget. Same for the availability
re-check inside `BookingRedirectController` — that one *should* be as fresh as possible,
and against a synced read model it becomes cheap enough to also add a live confirmation.

### Keep the same treatment for rates
Add a `cottage_rate_days` table mirroring `cottage_availability_days` (`property_id`,
`date`, `price`, `min_stay`, `max_stay`, `season_name`, `currency`, `synced_at`) and move
`rateCalendar()` behind it. `RateController::month()` then becomes a single indexed range
scan, and `nightlySegments()` — which currently re-fetches the rate calendar on **every
quote request** — becomes free.

## 3.4 Decompose the two large classes

`LodgifyRepository` (1,924 lines) → six focused services behind the same façade so
callers do not change on day one:

| New service | Absorbs |
|---|---|
| `PropertyCatalogue` | `allCottages`, `cottage`, `cottageBySlug`, `cottageRaw`, `mergePropertyAndRoom`, `mapCottage` and its extractors (`extractAmenities`, `extractImages*`, `extractHouseRules`, `roomCountsFromAmenities`, `amenityLabel`, `prettyAmenityGroup`) |
| `AvailabilityService` | `cottageAvailability`, `aggregateAvailability`, `cottagesFreeFor`, `freeWindows`, `nearbyMatches`, `alternativeStays`, `cottagesWithOpenings`, `explainAvailability` |
| `PricingService` | `rateCalendar`, `rateCalendarRaw`, `normaliseRateCalendar`, `extractRatePrice`, `rateSettings`, `seasons`, `mapSeasons`, `seasonNameFor` |
| `QuoteService` | `quote` (live, uncached) |
| `AddonService` | `addons`, `addonsFrom*`, `mapAddon`, `addonIs*`, `addonTranslation`, `addonChargeScaling` |
| `ImageGalleryService` | wraps the existing `PropertyImageResolver` |

Extract the shared caching helper (`rememberArray`, `flushCache`, the tag/driver
switching) into a small `TaggedCache` collaborator injected into each — right now that
logic is duplicated in both `LodgifyRepository` and `PropertyImageResolver`, and
`ReservationRepository` has a third hand-rolled variant that does not use tags at all.

`LodgifyClient` (1,185 lines) → split the ~450 lines of probe/diagnostic code
(`diagnose`, `probeRatesCalendar`, `probePhotos`, `probeBookings`, `harvestV3Ids`,
`harvestImageUrls`, `raw`, `fetchPublicPage`, `v3ImageAttempts`) into a `LodgifyProbe`
class registered by a **dev-only** service provider, matching the existing
`app()->environment(['local','staging'])` gate on the debug routes. `DebugController`
becomes its only consumer.

## 3.5 Formalise authorization

Two ownership rules are currently enforced inline:

- `ProfileController::show()` compares `strtolower($reservation->guestEmail)` against
  `strtolower($request->user()->email)` — correct, and the comment explaining *why*
  (Lodgify ids are sequential and therefore guessable) is exactly right. But the rule
  lives in a controller, so a second read path would have to re-implement it.
- `EnsureUserIsAdmin` is a single boolean check, which `User::isAdmin()`'s own docblock
  concedes is "the right amount of structure for a six-cottage operation… if roles ever
  multiply, replace this with a proper permissions package."

Move both to Policies (`ReservationPolicy::view()`, `GuestPhotoPolicy::*`) and call
`$this->authorize(...)`. This makes the rule testable in isolation and re-usable, without
adopting a permissions package before it is warranted.

## 3.6 Replace hand-built JSON with API Resources

`RateController` hand-assembles its response arrays (`cottageMeta()`, `rulesFor()`,
`parseV2Quote()`, `parsePublicQuote()`) and `AvailabilityController` does the same. The
`parseV2Quote` / `parsePublicQuote` split — two upstream shapes normalised to one
frontend shape — is the right idea, but it belongs in a `QuoteResource` (plus a
`QuoteNormaliser` for the shape branching) so the wire contract is declared in one place
and can be asserted against in tests. Today the Alpine components in
`cottage-calendar.js` depend on ~25 field names that exist only as literals inside a
controller method.

## 3.7 Infrastructure

| Concern | Today | Recommended |
|---|---|---|
| Cache driver | `database` | **Redis** — unlocks `Cache::tags()`, which `rememberArray()` and `PropertyImageResolver::cacheStore()` already branch on but never get. Currently `flushCache()` falls through to `Cache::flush()`, nuking sessions and rate limiters along with Lodgify data. |
| Session driver | `database` | Redis |
| Queue | `database`, and unused | Redis + **Horizon**; needed the moment sync jobs exist |
| Database | SQLite | **MySQL/Postgres** before any concurrent write load; SQLite + `database` cache + `database` sessions on one file is a write-lock bottleneck |
| Errors | `Log::warning` / `report()` only | Sentry or Flare — `safe()` swallows failures by design, so without aggregation a degraded site looks healthy |
| Uptime | `/up` health endpoint exists | Extend to assert Lodgify reachability and `max(synced_at)` freshness |
| CI | none | Pint `--test` + PHPUnit on every push |

---

# Part 4 — Staged migration path

Each stage is independently shippable and leaves the app working.

### Stage 0 — Unbreak and document (1–2 days)
Fix D1 (broken scheduler), D4 (config keys), D5 (`.env.example`), D3 (route groups),
D6 (`$fillable`). Add the security headers middleware and `TrustProxies` from
[`03-security.md`](03-security.md). No behaviour change beyond correctness.

### Stage 1 — Test the mappers (3–5 days)
Capture real Lodgify payloads as fixtures (the `/debug/lodgify/raw/*` routes exist
precisely for this) and pin `mapCottage()`, `normaliseRateCalendar()`, `parseV2Quote()`,
`parsePublicQuote()` and `mapReservation()`. Add feature tests for the authorization
boundaries. Everything after this stage is safe to refactor.

### Stage 2 — Redis (1 day)
Switch cache and session drivers. Tagged flushing starts working immediately — no code
change needed, because `rememberArray()` and `PropertyImageResolver::cacheStore()`
already detect the driver. `flushCache()` stops nuking sessions.

### Stage 3 — Sync availability (3–5 days)
Implement `SyncCottageAvailability` (closing D1 properly and D2 together) plus
per-cottage jobs, keep the request-time path as a fallback behind a feature flag, then
cut over `aggregateAvailability()` and `cottageAvailability()` to read from the table.
Add `synced_at` freshness to `/up`.

### Stage 4 — Sync rates (2–3 days)
Same pattern for `cottage_rate_days`. `RateController::month()` and `nightlySegments()`
become local reads.

### Stage 5 — Decompose services (5–8 days)
Split `LodgifyRepository` into the six services and `LodgifyClient` into
client + probe. Safe only because Stage 1 pinned the behaviour.

### Stage 6 — Policies and Resources (2–3 days)
Extract `ReservationPolicy` / `GuestPhotoPolicy`; convert the `/api/*` responses to
API Resources with contract tests.

### Stage 7 — Close the checkout loop (3–5 days)
`checkout_intents` has `markConverted()` and `matchFor()` implemented but **nothing calls
either** — conversion is never actually recorded, so `Admin\CheckoutIntentController`'s
"converted" count is permanently zero and every intent ages into "abandoned". Wire a
Lodgify booking webhook (or a reconciliation job over `SyncReservations` output) to call
`CheckoutIntent::matchFor(...)?->markConverted($bookingId)`. Only then does the
attribution/abandonment reporting the table was built for actually work.
