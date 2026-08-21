# Page & Endpoint Flows — Complete Trace

Every route in `routes/web.php`, traced end to end: **URL → middleware → controller
method → service methods → upstream HTTP calls → cache keys → DTOs → Blade view →
Alpine component → follow-up XHR.**

There is no `routes/api.php`. The `/api/*` routes are declared in `routes/web.php` and
therefore run inside the **`web` middleware group** (cookies, session, CSRF verification,
`ShareErrorsFromSession`). They exist solely to feed the page's own Alpine components.

## Conventions used below

- **Cache key** columns show the *logical* key. `LodgifyRepository::rememberArray()`
  rewrites it as:
  - tagged drivers (`redis`, `memcached`): tag `lodgify`, key `v3:<logical-key>`
  - all other drivers (including the default `database`): key `lodgify:v3:<logical-key>`
- `safe(ctx, fn)` = the failure-isolation wrapper: catches everything, logs at `warning`,
  appends to `$lastErrors`, returns `null`.
- TTL names refer to `config/lodgify.php` → `cache.*`.

## TTL reference

| Config key | Env var | Default |
|---|---|---|
| `lodgify.cache.properties_list` | `LODGIFY_CACHE_PROPERTIES` | 3600 s |
| `lodgify.cache.property_detail` | `LODGIFY_CACHE_PROPERTY_DETAIL` | 3600 s |
| `lodgify.cache.availability` | `LODGIFY_CACHE_AVAILABILITY` | 300 s |
| `lodgify.cache.quote` | `LODGIFY_CACHE_QUOTE` | 60 s |
| `lodgify.cache.rate_settings` | `LODGIFY_CACHE_RATE_SETTINGS` | 3600 s |
| `lodgify.cache.images` | `LODGIFY_CACHE_IMAGES` | 21600 s (6 h) |
| `lodgify.reservations` | `LODGIFY_CACHE_RESERVATIONS` | 300 s |
| `services.google.cache_ttl` | `GOOGLE_REVIEWS_CACHE` | 21600 s (6 h) |

---

# Route map at a glance

| Method | URI | Middleware | Handler | Name |
|---|---|---|---|---|
| GET | `/` | web | `HomeController@index` | `home` |
| GET | `/cottages` | web | `CottageController@index` | `cottages.index` |
| GET | `/cottage/{slug}` | web | `CottageController@show` | `cottage.show` |
| GET | `/availability` | web | `AvailabilityController@search` | `availability.search` |
| GET | `/api/availability/month` | `throttle:60,1` | `AvailabilityController@month` | `api.availability.month` |
| GET | `/api/cottage/{slug}/rates` | `throttle:120,1` | `RateController@month` | `api.cottage.rates` |
| GET | `/api/cottage/{slug}/quote` | `throttle:120,1` | `RateController@quote` | `api.cottage.quote` |
| GET | `/api/cottage/{slug}/addons` | `throttle:60,1` | `RateController@addons` | `api.cottage.addons` |
| GET | `/book/{slug}` | `throttle:30,1` | `BookingRedirectController` (invokable) | `booking.redirect` |
| GET | `/booking/details/{slug}` | `throttle:60,1` | `BookingController@details` | `booking.details` |
| POST | `/booking` | `throttle:booking-create` | `BookingController@store` | `booking.store` |
| GET | `/booking/submitted` | web | `BookingController@submitted` | `booking.submitted` |
| GET | `/pay/{token}` | `signed`, `throttle:payment-page` | `PaymentController@show` | `booking.pay` |
| GET | `/pay/{token}/success` | `throttle:payment-page` | `PaymentController@success` | `booking.pay.success` |
| GET | `/pay/{token}/cancelled` | `throttle:payment-page` | `PaymentController@cancelled` | `booking.pay.cancelled` |
| POST | `/webhooks/stripe` | `throttle:stripe-webhook`, **CSRF-exempt** | `Webhooks\StripeWebhookController@handle` | `webhooks.stripe` |
| GET | `/things-to-do` | web | `Route::view('pages.things-to-do')` | `things-to-do` |
| GET | `/privacy-and-policy` | web | `Route::view('pages.privacy-and-policy')` | `privacy` |
| GET | `/gallery` | web | `GalleryController@index` | `gallery` |
| GET | `/reviews` | web | `ReviewController@index` | `reviews` |
| GET | `/contact` | web | `ContactController@create` | `contact` |
| POST | `/contact` | `throttle:6,1` | `ContactController@store` | `contact.store` |
| GET | `/business-stays` | web | `BusinessStayController@create` | `business-stays.create` |
| POST | `/business-stays` | `throttle:6,1` | `BusinessStayController@store` | `business-stays.store` |
| GET | `/business-stays/thank-you` | web | `BusinessStayController@thanks` | `business-stays.thanks` |
| GET | `/share-your-photos` | web | `GuestPhotoController@create` | `photos.create` |
| POST | `/share-your-photos` | `throttle:3,1` | `GuestPhotoController@store` | `photos.store` |
| GET | `/login` | `guest` | `Auth\LoginController@create` | `login` |
| POST | `/login` | `guest`,`throttle:10,1` | `Auth\LoginController@store` | `login.store` |
| POST | `/logout` | `auth` | `Auth\LoginController@destroy` | `logout` |
| GET | `/register` | `guest` | `Auth\RegisterController@create` | `register` |
| POST | `/register` | `guest`,`throttle:6,1` | `Auth\RegisterController@store` | `register.store` |
| GET | `/forgot-password` | `guest` | `Auth\ForgotPasswordController@create` | `password.request` |
| POST | `/forgot-password` | `guest`,`throttle:5,1` | `Auth\ForgotPasswordController@store` | `password.email` |
| GET | `/reset-password/{token}` | `guest` | `Auth\ResetPasswordController@create` | `password.reset` |
| POST | `/reset-password` | `guest`,`throttle:5,1` | `Auth\ResetPasswordController@store` | `password.update` |
| GET | `/verify-email` | `auth` | closure → `auth.verify-email` | `verification.notice` |
| GET | `/verify-email/{id}/{hash}` | `auth`,`signed` | closure → `$request->fulfill()` | `verification.verify` |
| POST | `/verify-email/send` | `auth`,`throttle:6,1` | closure | `verification.send` |
| GET | `/my-stays` | `auth`,`verified` | `ProfileController@index` | `profile.index` |
| GET | `/my-stays/{id}` | `auth`,`verified` | `ProfileController@show` | `profile.show` |
| GET | `/account` | `auth`,`verified` | `ProfileController@edit` | `account.edit` |
| PATCH | `/account` | `auth`,`verified` | `ProfileController@update` | `account.update` |
| PUT | `/account/password` | `auth`,`verified` | `ProfileController@updatePassword` | `account.password` |
| GET | `/admin` | `auth`,`admin` | closure → redirect | — |
| GET | `/admin/business-stays` | `auth`,`admin` | `Admin\BusinessStayRequestController@index` | `admin.business-stays.index` |
| GET | `/admin/business-stays/{businessStayRequest}` | `auth`,`admin` | `…@show` | `admin.business-stays.show` |
| PATCH | `/admin/business-stays/{businessStayRequest}` | `auth`,`admin` | `…@update` | `admin.business-stays.update` |
| DELETE | `/admin/business-stays/{businessStayRequest}` | `auth`,`admin` | `…@destroy` | `admin.business-stays.destroy` |
| GET | `/admin/messages` | `auth`,`admin` | `Admin\ContactMessageController@index` | `admin.messages.index` |
| GET | `/admin/messages/{contactMessage}` | `auth`,`admin` | `…@show` | `admin.messages.show` |
| PATCH | `/admin/messages/{contactMessage}` | `auth`,`admin` | `…@update` | `admin.messages.update` |
| DELETE | `/admin/messages/{contactMessage}` | `auth`,`admin` | `…@destroy` | `admin.messages.destroy` |
| GET | `/admin/photos` | `auth`,`admin` | `Admin\GuestPhotoController@index` | `admin.photos.index` |
| GET | `/admin/photos/{guestPhoto}/file` | `auth`,`admin` | `…@file` | `admin.photos.file` |
| PATCH | `/admin/photos/{guestPhoto}/approve` | `auth`,`admin` | `…@approve` | `admin.photos.approve` |
| PATCH | `/admin/photos/{guestPhoto}/reject` | `auth`,`admin` | `…@reject` | `admin.photos.reject` |
| DELETE | `/admin/photos/{guestPhoto}` | `auth`,`admin` | `…@destroy` | `admin.photos.destroy` |
| GET | `/admin/checkouts` | `auth`,`admin` | `Admin\CheckoutIntentController@index` | `admin.checkouts.index` |
| GET | `/admin/reservations` | `auth`,`admin` | `Admin\ReservationController@index` | `admin.reservations.index` |
| POST | `/admin/reservations/refresh` | `auth`,`admin` | `…@refresh` | `admin.reservations.refresh` |
| GET | `/admin/reservations/{id}` | `auth`,`admin` | `…@show` | `admin.reservations.show` |
| GET | `/debug/lodgify` + 6 sub-routes | **local/staging only** | `DebugController@*` | — |
| GET | `/up` | — | framework health closure | — |

---

# 1. Home — `GET /`

**Route** `routes/web.php:27` → `HomeController@index` → `pages.home`
**Layout** `x-website-layout` with `transparentNav` (full-bleed hero)

### Server flow

```
HomeController@index
  └─ try: LodgifyRepository::cottagesWithOpenings(windowsPerCottage: 2)
       ├─ allCottages()
       │    ├─ rememberArray('properties:all:raw', 3600)
       │    │    └─ LodgifyClient::listProperties()
       │    │         GET api.lodgify.com/v2/properties      [X-ApiKey]
       │    └─ for each list entry, if lodgify.hydrate_property_details:
       │         cottageRaw($id)
       │           ├─ rememberArray("property:{$id}:raw", 3600)
       │           │    └─ safe → LodgifyClient::getProperty($id)
       │           │         GET /v2/properties/{id}
       │           └─ if lodgify.merge_room_data and rooms[0].id exists:
       │                rememberArray("room:{$id}:{$roomId}:raw", 3600)
       │                  └─ safe → LodgifyClient::getRoomInfo($id,$roomId)
       │                       GET /v1/properties/{id}/rooms/{roomId}
       │                then mergePropertyAndRoom()
       │           → mapCottage(merged) → App\DTO\Cottage
       │                └─ PropertyImageResolver::resolve($id,$slug,$apiImages)
       │                     strategies: manifest → local → api  (config)
       │                     cache "v2:images:{id}:{fingerprint}" TTL 21600
       └─ for each Cottage: freeWindows($c, today, today+90d)
            └─ cottageAvailability($c, min(from, today))
                 rememberArray("avail:{id}:{start}:{end}", 300)
                   ├─ if prefer_authenticated_availability:
                   │    safe → getAvailability()  GET /v2/availability/{id}
                   │            → normaliseAvailabilityPeriods()
                   └─ else / on failure:
                        safe → getPublicCalendar()
                               GET checkout.lodgify.com/api/v1/checkout/calendar
                               [browser headers, Referer/Origin spoofed for Cloudflare]

  └─ catch Throwable → Log::error('home.index failed')
       └─ fallback: allCottages()->map(fn($c) => ['cottage'=>$c,'windows'=>[]])
            └─ catch → collect()   (renders an empty page rather than a 500)
```

### View data

| Variable | Type |
|---|---|
| `$listings` | `Collection<array{cottage: Cottage, windows: array<{start,end,nights,min_stay}>}>` |

`freeWindows()` returns only windows where `nights >= min_stay` — i.e. only windows that
are actually bookable, so the "NEXT OPEN …" ticker never advertises an unbookable gap.

### Client flow
`pages/home.blade.php` renders `<x-booking-search>`, which mounts the Alpine
`bookingSearch` component. On `init()` it calls `ensureMonths(cursor)` →
`GET /api/availability/month?start=YYYY-MM-01` (see §5).

### Cold-cache cost
With 6 cottages: **1** (`listProperties`) + **6** (`getProperty`) + **6** (`getRoomInfo`)
+ **6** (`getAvailability`) = **19 sequential upstream calls** before first byte.
See [`04-caching-database-performance.md` §5](04-caching-database-performance.md).

---

# 2. Cottage listing — `GET /cottages`

**Route** `routes/web.php:28` → `CottageController@index` → `pages.cottages`

Identical service path to the home page but `windowsPerCottage: 3`, plus it exposes a
degradation flag:

```php
$listings = $this->lodgify->cottagesWithOpenings(windowsPerCottage: 3);
$degraded = !empty($this->lodgify->lastErrors());
```

`$degraded` is also forced `true` in the `catch` branch. The view uses it to render a
soft "some availability could not be loaded" notice instead of failing.

| Variable | Type |
|---|---|
| `$listings` | `Collection<array{cottage: Cottage, windows: array}>` |
| `$degraded` | `bool` |

Because both pages share the same cache keys, whichever page is hit first warms the other.

---

# 3. Cottage detail — `GET /cottage/{slug}`

**Route** `routes/web.php:29` → `CottageController@show`
**View** `pages/cottage.blade.php` (1,116 lines — the largest view; hero gallery, amenity
grid, map embed, rates calendar, sticky booking panel, add-ons, policies)

### Query parameters (all optional, read directly off the query string)

| Param | Handling |
|---|---|
| `arrival`, `departure` | passed through as-is; only used to pre-fetch a quote |
| `adults` | `max(1, (int) …)`, default 2 |
| `children` | `max(0, (int) …)`, default 0 |
| `pets` | `max(0, (int) …)`, default 0 |

> Note: unlike `AvailabilityController@search`, this method does **not** run
> `$request->validate()`. `arrival`/`departure` go straight into
> `LodgifyRepository::quote()`, which interpolates them into a cache key and a
> Lodgify query string. See [`03-security.md` §F6](03-security.md).

### Server flow

```
CottageController@show(Request, string $slug)

 1. $cottage = LodgifyRepository::cottageBySlug($slug)
      └─ allCottages()->firstWhere('slug', $slug)
         ⚠ hydrates EVERY cottage to resolve one slug (see perf doc §5.2)
      └─ null → throw NotFoundHttpException  → 404

 2. try: $windows = array_slice(
             freeWindows($cottage, today, today + lodgify.availability_window_days),
             0, 8)
      catch → Log::warning('cottage.show windows failed'); $degraded = true

 3. try: $seasons   = seasons($cottage)
           └─ rememberArray("seasons:{id}", 3600 /* rate_settings */)
                ├─ safe → getRateSettings()  GET /v2/rates/settings?houseId={id}
                └─ on empty → safe → getPublicRates()
                        GET checkout.lodgify.com/api/v1/checkout/{id}
                └─ mapSeasons() → Collection<RateSeason>
         $priceFrom = $seasons->filter(nightly !== null)->min('nightly')
      catch → Log::warning('cottage.show seasons failed')   (no degraded flag)

 4. if ($arrival && $departure):
      try: $quote = quote($cottage->id, $arrival, $departure, $adults, $children, $pets)
             └─ rememberArray("quote:v2:{id}:{arr}:{dep}:{a}:{c}:{p}", 60)
                  ├─ cottage($cottageId)          (for the currency)
                  ├─ safe → getQuote()  GET /v2/quote/{id}   → _source = 'v2'
                  └─ on empty → safe → getPublicCheckoutPrice()
                        GET checkout.lodgify.com/api/v1/checkout/price
                                                              → _source = 'public'
      catch → Log::warning('cottage.show quote failed')

 5. $degraded ||= !empty(lastErrors())
```

### View data

| Variable | Type | Notes |
|---|---|---|
| `$cottage` | `App\DTO\Cottage` | readonly DTO; helpers `primaryRoomId()`, `galleryPayload()`, `largeImage()`, `altFor()`, `mapEmbedUrl()`, `directionsUrl()`, `amenityCount()`, `locationLine()` |
| `$windows` | `array` (max 8) | bookable free windows |
| `$seasons` | `Collection<RateSeason>` | seasonal rate bands |
| `$quote` | `?array` | raw repository quote, only when both dates given |
| `$priceFrom` | `?float` | cheapest seasonal nightly |
| `$arrival` `$departure` `$adults` `$children` `$pets` | scalars | echoed into the Alpine config |
| `$degraded` | `bool` | soft-failure banner |

### Client flow — this is the most interactive page in the app

`pages/cottage.blade.php:21` mounts `cottageCalendar` with:

```js
x-data="cottageCalendar({
    slug:      '…',
    ratesUrl:  '{{ route('api.cottage.rates',  $cottage->slug) }}',
    quoteUrl:  '{{ route('api.cottage.quote',  $cottage->slug) }}',
    addonsUrl: '{{ route('api.cottage.addons', $cottage->slug) }}',
    bookUrl:   '{{ route('booking.redirect',   $cottage->slug) }}',
    …arrival, departure, adults, children, pets, currency, maxGuests, petsAllowed
})"
```

`init()` (`cottage-calendar.js:37`) does three things in order:

1. `cursor = startOfMonth(arrival || today)` — open on the month being searched
2. `ensureMonths(cursor)` → **`GET {ratesUrl}?start=YYYY-MM-01&months=2`** (§6)
3. `fetchAddons()` → **`GET {addonsUrl}`** (§8)
4. if both dates present → `fetchQuote()` → **`GET {quoteUrl}?…`** (§7)

Thereafter: changing a date or a guest stepper re-runs `fetchQuote()`; paging the
calendar re-runs `ensureMonths()` (results memoised in a client-side `cache` object
keyed by month); pressing **Book** navigates to `bookUrl` with the current selection as
query parameters (§9).

Line 40 separately mounts `imageLightbox({ images: @js($cottage->galleryPayload()) })`
for the hero gallery — no XHR, the payload is inlined at render time.

`pages/cottage.blade.php:561` is the **only** unescaped output in the entire view layer:

```blade
{!! \Illuminate\Support\Str::of($cottage->description)
        ->stripTags('<p><br><strong><em><ul><ol><li><h3><h4>') !!}
```

The source is the property description from Lodgify (owner-authored). See
[`03-security.md` §F3](03-security.md) for why the tag allowlist alone is not sufficient.

---

# 4. Availability search — `GET /availability`

**Route** `routes/web.php:30` → `AvailabilityController@search` → `pages.availability-results`

### Validation

```php
$validated = $request->validate([
    'arrival'   => ['sometimes','nullable','date_format:Y-m-d'],
    'departure' => ['sometimes','nullable','date_format:Y-m-d','after:arrival'],
    'adults'    => ['sometimes','integer','min:1','max:20'],
    'children'  => ['sometimes','integer','min:0','max:20'],
    'pets'      => ['sometimes','integer','min:0','max:10'],
]);
```

Dates are **optional by design** — dateless, the page becomes a browsable list of every
cottage with its next openings, which is a better destination than an empty form for
anyone arriving from a nav link.

### Party filter

```php
$fitsParty = fn ($c) => ($c->maxGuests === 0 || $c->maxGuests >= $adults + $children)
                     && ($pets === 0 || $c->petFriendly);
```

`maxGuests === 0` means "unknown", and is treated as *passes* rather than *fails* — the
same "missing data is not a negative answer" principle used throughout the availability
code.

### Branch A — no dates

```
cottagesWithOpenings(windowsPerCottage: 3)   → $browse
  └─ filter($fitsParty) → values()
```

### Branch B — both dates (the three-tier fallback)

```
TIER 1  $exact = cottagesFreeFor($arrival, $departure)->filter($fitsParty)
          └─ allCottages() then per cottage:
               cottageAvailability($c, min($arrival, today))
               walk arrival..departure-1; every night must have isAvailable
               empty calendar → EXCLUDE (never claim bookable without data)

TIER 2  $nearby = nearbyMatches($arrival, $departure, lodgify.nearby_window_days=14)
          └─ nights = departure - arrival
             for offset 1..14, for delta in [-offset, +offset]:
                 skip if the shifted arrival is in the past
                 cottagesFreeFor(shifted arrival, shifted arrival + nights)
             groupBy cottage id → keep the smallest offset_days → sort
          └─ reject cottages already in $exact
          └─ filter($fitsParty)

TIER 3  only if $exact->isEmpty() || $nearby->isEmpty():
        $alternatives = alternativeStays($arrival, $departure,
                                         lodgify.alternative_window_days=30)
          └─ per cottage, freeWindows(arrival-30d … departure+30d)
             score each window by [proximity-to-requested-arrival, -nights]
             keep the single best window per cottage
             departure = last free night + 1 day
          └─ reject cottages already in $exact or $nearby
          └─ filter($fitsParty)
```

Tier 2 is the expensive one: worst case **28 iterations × `cottagesFreeFor()`**. Every
inner call hits the *same* `avail:{id}:{start}:{end}` cache entries (the start date is
normalised to `min($arrival, today)`), so after the first cottage-loop it is pure
in-process array work — but the first pass on a cold cache is 6 upstream availability
calls plus full catalogue hydration.

### View data

| Variable | Type |
|---|---|
| `$hasDates` | `bool` |
| `$arrival` `$departure` | `?string` |
| `$nights` | `?int` — `Carbon::diffInDays` |
| `$adults` `$children` `$pets` `$guests` | `int` |
| `$exact` | `Collection<Cottage>` |
| `$nearby` | `Collection<array{cottage,arrival,departure,offset_days}>` |
| `$alternatives` | `Collection<array{cottage,arrival,departure,nights,offset_days}>` |
| `$browse` | `Collection<array{cottage,windows}>` |
| `$degraded` | `bool` |

---

# 5. `GET /api/availability/month` — aggregate calendar JSON

**Route** `routes/web.php:32`, `throttle:60,1` → `AvailabilityController@month`
**Consumer** the `bookingSearch` Alpine component, i.e. the pages that render
`<x-booking-search>`: `pages/home.blade.php` (twice), `pages/cottages.blade.php` (twice)
and `pages/availability-results.blade.php`

### Contract

```
Request   ?start=YYYY-MM-DD          (required, date_format:Y-m-d)

Response  200 application/json
          Cache-Control: public, max-age=60          ⚠ see security doc §F1
{
  "start":    "2026-09-01",
  "days": {
    "2026-09-01": { …AvailabilityDay::toArray() },
    …
  },
  "degraded": false,
  "notes":    []          // lastErrors(), local/staging only; [] in production
}
```

### Server flow

```
validate start
try:
  aggregateAvailability($start)
    └─ rememberArray("avail:aggregate:{$start}:raw", 300)
         ├─ allCottages()                     (may trigger full hydration)
         ├─ if 0 cottages → ['total'=>0,'days'=>[],'errors'=>['no cottages …']]
         └─ foreach cottage:
              cottageAvailability($cottage, $start)     ← per-cottage cache
              empty → push error, `continue` (does NOT count toward total)
              else  → $succeeded++ and fold into $days[$date]:
                        available++            if isAvailable
                        min_stay = min(…)      over available days only
                        ci |= isCheckInAvailable
                        co |= isCheckOutAvailable
         └─ return ['total' => $succeeded, 'days' => …, 'errors' => …]

  $this->lastErrors = $agg['errors']
  map each day → new AvailabilityDay(date, totalCottages: $total,
                                     availableCount, minStay, ci, co)
catch Throwable:
  Log::error('availability.month failed')
  return 200 with days: [], degraded: true          ← never a 5xx
```

**The `$succeeded` counter is the important correctness detail.** `totalCottages` is the
number of cottages we actually got data for, not the number that exist. Without it, a
single failed fetch would make every day look "1 of 6 available" → the UI would paint
partial availability as near-fully-booked. The inline comment says exactly this.

`AvailabilityDay` then derives display state: `isFullyBooked()`, `isLimited($threshold)`
(default from `lodgify.limited_threshold` = 2), `isFullyAvailable()`.

### Client flow (`booking-search.js:54`)

```js
const res = await fetch(`${this.availabilityUrl}?start=${key}-01`, { … });
```

Months are memoised in a component-local `cache` object, so paging back and forth in the
date picker issues each month's request at most once per page view.

---

# 6. `GET /api/cottage/{slug}/rates` — priced calendar JSON

**Route** `routes/web.php:37`, `throttle:120,1` → `RateController@month`

### Contract

```
Request   ?start=YYYY-MM-DD   (required)
          &months=1..3        (optional, default 2)

Response  200 application/json
          Cache-Control: public, max-age=60
{
  "cottage": { "id":…, "slug":…, "name":…, "currency":"CAD" },
  "start":   "2026-09-01",
  "end":     "2026-10-31",
  "days":    { "2026-09-01": { …RateDay::toArray() }, … },
  "rules": {
    "max_guests":     6,        // from the Cottage DTO
    "pets_allowed":   true,     // from the Cottage DTO
    "check_in_hour":  14,       // from Lodgify rate_settings
    "check_out_hour": 12,
    "vat":            0.0,
    "vat_exclusive":  false,
    "currency":       "CAD"
  },
  "degraded": false,
  "notes":    []
}
```

The `rules` block is the reason this endpoint exists in this shape: it lets the calendar
enforce **Lodgify's own** constraints in the UI (min/max stay, occupancy, check-in hours)
instead of letting a guest assemble an invalid selection and only discovering it at quote
time. `rulesFor()` sources every value from Lodgify — **nothing is hardcoded**.

### Server flow

```
validate start (required, Y-m-d) and months (1..3)
$cottage = cottageBySlug($slug)   → 404 via NotFoundHttpException if missing

$start = Carbon::parse(start)->startOfMonth()
$end   = $start->addMonths($months)->endOfMonth()

try:
  rateCalendar($cottage, $start, $end)
    └─ rateCalendarRaw()
         rememberArray("ratesraw:{id}:{start}:{end}", 300 /* availability TTL */)
           └─ safe → getRatesCalendar()  GET /v2/rates/calendar
                     query built by ratesCalendarQuery() using
                     lodgify.rates_param_style (pascal | pascal_property |
                     camel | camel_property | snake | current)
                     ⚠ Lodgify's rate endpoints use PascalCase unlike the rest
                       of v2, and answer a bare HTTP 400 on any misnamed field.
                       Discover the working style with
                       /debug/lodgify/probe/rates/{propertyId}
           └─ normaliseRateCalendar()
                calendar_items[] where date === null && is_default → default rate
                price and min_stay are NESTED inside prices[], not on the item
                → { days: {...}, default: {...}, settings: {...} }
    └─ merge availability from cottageAvailability() so a day can be
       priced-but-unbookable; unpriceable days get price = null and the UI
       omits the figure rather than inventing one
  rateSettings($cottage, …)   ← re-reads the SAME cached ratesraw entry
  → 200 with days + rules
catch Throwable:
  Log::error('cottage rates month failed')
  → 200 with days: [], degraded: true, rules: rulesFor($cottage, [])
```

Note that `rateSettings()` is deliberately a second read of the same cache entry rather
than a second HTTP call — `rateCalendarRaw()` returns `days`, `default` **and**
`settings` from one fetch.

### Client flow (`cottage-calendar.js:80`)

```js
const res = await fetch(`${this.ratesUrl}?start=${startMonthKey}-01&months=${months}`, …);
```
Response merged into `this.cache` and `this.rules`; the grid renders price chips per day
and disables days that violate `rules`.

---

# 7. `GET /api/cottage/{slug}/quote` — live price JSON

**Route** `routes/web.php:42`, `throttle:120,1` → `RateController@quote`

This is the most intricate endpoint in the application, because it normalises **two
completely different upstream payload shapes** into one frontend contract.

### Validation

```php
'arrival'   => ['required','date_format:Y-m-d'],
'departure' => ['required','date_format:Y-m-d','after:arrival'],
'adults'    => ['sometimes','integer','min:1','max:30'],
'children'  => ['sometimes','integer','min:0','max:30'],
'pets'      => ['sometimes','integer','min:0','max:10'],
'addons'    => ['sometimes','string'],          // "155523:1,155524:2"
```

`addons` is parsed to bare ids — the quantity suffix is dropped here and only used later
in the checkout handoff:

```php
$addOnIds = collect(explode(',', $addons))
    ->map(fn ($p) => trim(explode(':', $p)[0] ?? ''))->filter()->values()->all();
```

### Server flow

```
$cottage = cottageBySlug($slug)  → 404 if missing

── local guard, before any upstream call ──
if ($cottage->maxGuests > 0 && $adults + $children > $cottage->maxGuests)
    return 200 { ok:false, reason:'occupancy',
                 message:"This cottage sleeps up to N guests.", max_guests:N }

try:
  $segments = nightlySegments($cottage, $arrival, $departure)
     └─ rateCalendar($cottage, $arrival, $departure)     ⚠ a SECOND rate fetch
        (own cache key: "ratesraw:{id}:{arrival}:{departure}", distinct from the
         month-aligned key used by §6 — so this is a cache MISS on first quote)
     └─ walk arrival..departure-1 (departure night is not charged) and coalesce
        consecutive nights sharing the same (price, seasonName) into runs:
        [{price:100,nights:1,start,end,subtotal:100},
         {price:150,nights:3,start,end,subtotal:450}]

  $raw = LodgifyRepository::quote($cottage->id, $arrival, $departure,
                                  $adults, $children, $pets, $addOnIds)
     └─ rememberArray("quote:v2:{id}:{arr}:{dep}:{a}:{c}:{p}[:{addon-ids}]", 60)
          ├─ safe → getQuote()   GET /v2/quote/{id}    → _source='v2'   (preferred)
          └─ on empty → safe → getPublicCheckoutPrice()
                    GET checkout.lodgify.com/api/v1/checkout/price → _source='public'

  if (!$raw):
     $guestMessage = lastGuestMessage()
     return 200 {
       ok:false,
       reason:  $guestMessage ? 'rejected' : 'error',
       message: $guestMessage ?? "We couldn't price those dates just now…",
       segments: $segments
     }
     ── this distinction matters: "Lodgify says no" (min-stay, closed date,
        occupancy) is actionable copy from an authoritative source; "our request
        failed" must not be dressed up as a claim about the cottage.

  $parsed = $raw['_source'] === 'v2' ? parseV2Quote($raw, $cottage)
                                     : parsePublicQuote($raw, $cottage);
  $parsed['ok'] = true;  $parsed['segments'] = $segments;
  return 200 $parsed   with  Cache-Control: private, max-age=30

catch Throwable → Log::warning + 200 { ok:false, reason:'error', message:… }
```

### The two parsers

**`parseV2Quote()`** — authenticated `/v2/quote`. Line items live at
`room_types[].price_types[].prices[]`, grouped by an integer `type`:

| `type` | Meaning | Destination |
|---|---|---|
| `0` | Room rate | summed into `rental` |
| `1` | Promotion (`is_negative: true`) | `promotions[]`, value negated |
| `2` | Fees | `fees[]` |
| `4` | Taxes | `taxes[]` |
| anything else | unknown | `fees[]` (fail-safe: never silently dropped) |

Also extracts `add_ons[]`, `add_ons_subtotal`, `total_including_vat`,
`scheduled_payments[]` (with `is_current` → `due_now`), `security_deposit`,
`security_deposit_text` and `cancellation_policy_text`. `nightly` is derived as
`rental / nights` — a *display average*, not a rate Lodgify quoted.

**`parsePublicQuote()`** — the Cloudflare-guarded public endpoint. Entirely different
shape, read with `data_get()`: `rentalPrice.{nights,nightlyPrice,total,promotions}`,
`fees.details`, `localTaxes.details`, `totalPrice.{total,amountToPay}`,
`scheduledPayments.payments`. It carries **no** add-ons, security deposit or cancellation
policy, so those normalise to `[]` / `0.0` / `null`.

Both emit the same key set, so `cottage-calendar.js` renders one breakdown either way and
`source` tells you which path answered.

### Client flow (`cottage-calendar.js:363`)
Called on `init()` when both dates are present, and on every subsequent date or guest
change. `ok:false` renders `message` inline under the price panel; `quoteReason`
distinguishes the occupancy/rejected/error cases for styling.

---

# 8. `GET /api/cottage/{slug}/addons` — optional extras JSON

**Route** `routes/web.php:47`, `throttle:60,1` → `RateController@addons`

```
Response  200, Cache-Control: public, max-age=300
{ "currency": "CAD", "addons": [ … ] }
```

```
$cottage = cottageBySlug($slug)  → 404 if missing
try:
  LodgifyRepository::addons($cottage)
    └─ for each strategy in lodgify.addon_strategies (default: api, manifest):
         'api'      → addonsFromApi()
                        rememberArray("addons:v2:{id}", 3600 /* property_detail */)
                          └─ safe → getAddons()
                                 GET /v1/properties/{id}/rates/addons
                        → filter addonIsActive() → mapAddon() (name/price/
                          charge_type/description/image/required/max_quantity,
                          localised via addonTranslation() using
                          lodgify.addons_locale, scaled by addonChargeScaling())
         'manifest' → addonsFromManifest()
                        config("lodgify-addons.{$cottage->id}")
                        ⚠ currently EMPTY — every entry is commented out
       first strategy returning a non-empty array wins
catch → Log::warning + 200 { currency, addons: [] }
```

`config/lodgify-addons.php` carries a standing warning: nothing validates the manifest
against Lodgify, so a stale price there charges the wrong amount. Keep it in sync with
*Lodgify → Rentals → Pricing → Add-ons* or leave it empty and rely on `api`.

### Client flow (`cottage-calendar.js:418`)
`fetchAddons()` runs unconditionally in `init()`. Selected add-ons become the
`addons=id-qty,…` parameter on the Book link (§9) and the `addons=id:qty,…` parameter on
subsequent quote requests (§7).

---

# 9. Checkout handoff — `GET /book/{slug}`

> **Superseded when direct payments are on.** With
> `BOOKING_DIRECT_PAYMENTS=true` the site takes the booking and the money itself via
> `POST /booking`, and this route becomes the fallback path only. See
> [`05-payments-and-booking.md`](05-payments-and-booking.md). Everything below still
> describes the route accurately — it is unchanged, and it is what runs when the flag is
> off.

**Route** `routes/web.php:171`, `throttle:30,1` → `BookingRedirectController::__invoke`
(single-action controller)

This is the **conversion boundary** of the whole application.

### Validation

```php
'arrival'   => ['required','date_format:Y-m-d'],
'departure' => ['required','date_format:Y-m-d','after:arrival'],
'adults'    => ['sometimes','integer','min:1','max:20'],
'children'  => ['sometimes','integer','min:0','max:20'],
'pets'      => ['sometimes','integer','min:0','max:10'],
'addons'    => ['sometimes','nullable','string','max:500'],   // "155688-1,155689-3"
'total'     => ['sometimes','nullable','numeric','min:0'],
```

### Flow

```
 1. $cottage = cottageBySlug($slug)              → 404 if missing

 2. if (!LodgifyCheckout::isConfigured())        // lodgify.checkout_slug is blank
      Log::error('Lodgify checkout slug is not configured; cannot redirect')
      redirect route('cottage.show') with
        checkout_error: 'Online booking is briefly unavailable — please call us…'

 3. $addons = parseAddons($validated['addons'])
      "155688-1,155689-3" → [['id'=>'155688','quantity'=>1],
                             ['id'=>'155689','quantity'=>3]]
      quantity floored at 1; blank ids dropped

 4. RE-CHECK AVAILABILITY (not trusted from the page the guest was looking at)
      try: cottagesFreeFor($arrival,$departure)->contains(id === $cottage->id)
           false → redirect cottage.show?arrival=&departure= with
                   checkout_error: 'Those dates were taken while you were
                                    deciding — here is what is still open.'
      catch → Log::warning('Availability re-check failed before redirect;
                            continuing')
           ── deliberate: a failed check must NOT block a booking. Lodgify
              validates again at checkout, so the worst case is the guest being
              told there by an authoritative source rather than guessed at here.

 5. $url = LodgifyCheckout::urlFor($cottage, $arrival, $departure,
                                   $adults, $children, $pets, $addons)
      → https://checkout.lodgify.com/{checkout_slug}/{cottage->id}/addons
          ?currency=CAD&arrival=…&departure=…&adults=N[&children=][&pets=]
          [&addons=155688-1,155689-3]
      Query string is assembled BY HAND because Lodgify requires a literal comma
      between add-on pairs; http_build_query() would percent-encode it.
      ⚠ formatAddons() passes the guest's chosen quantity ONLY. Lodgify applies
        the add-on's own frequency itself — a PerNight add-on with quantity 3 on
        a 2-night stay was observed billing 3 × 2. Multiplying locally
        double-charges.

 6. RECORD THE INTENT (wrapped in try/catch — "analytics are not worth losing a
    booking over")
      CheckoutIntent::create([
        cottage_id, cottage_name, arrival, departure,
        nights        = Carbon::diffInDays(arrival, departure),
        adults, children, pets,
        quoted_total  = $validated['total'] ?? null,   ← what OUR page showed
        currency      = strtoupper(lodgify.checkout_currency),
        addons        = $addons,                       ← json column
        redirect_url  = $url,                          ← exact URL, for debugging
        status        = 'redirected',
        referrer      = substr(header('referer'), 0, 512),
        utm_source, utm_medium, utm_campaign,          ← straight off the query
        ip_address, user_agent (truncated to 512),
        session_id    = session()->getId(),
      ])
      Model::creating hook assigns reference = 'INT-' . Str::random(6)
      catch → Log::error('Could not record checkout intent')

 7. return redirect()->away($url);          // 302 off-site
```

### The gap in this flow

`CheckoutIntent::markConverted()` and `CheckoutIntent::matchFor()` are both implemented
and **neither is ever called** — no webhook route, no reconciliation job. So:

- every intent stays `status = 'redirected'` forever;
- `CheckoutIntent::converted()->count()` in `Admin\CheckoutIntentController` is
  permanently **0**, and the displayed conversion `rate` is permanently `0.0`;
- every intent eventually ages past `lodgify.checkout_grace_minutes` (90) and is counted
  as `abandoned`.

Closing this is Stage 7 in [`01-architecture.md` §Part 4](01-architecture.md). Note that
the direct-payment flow makes this partly moot for bookings taken on this site — those are
tracked properly in `bookings` — but the table still never records a conversion.

---

# 10. Gallery — `GET /gallery`

**Route** `routes/web.php:82` → `GalleryController@index` → `pages.gallery`
**No Lodgify involvement** — this is purely local, guest-submitted content.

```
GuestPhoto::approved()                                  // status = 'approved'
    ->when($request->integer('cottage'), fn ($q,$id) => $q->where('cottage_id',$id))
    ->galleryOrder()          // is_featured DESC, sort_order ASC, created_at DESC
    ->paginate(24)->withQueryString()

// filter options — only cottages that actually have published photos, so the
// filter never offers a choice that returns nothing
GuestPhoto::approved()->whereNotNull('cottage_id')
    ->selectRaw('cottage_id, cottage_name, count(*) as total')
    ->groupBy('cottage_id','cottage_name')->orderByDesc('total')->get()
```

| Variable | Type |
|---|---|
| `$photos` | `LengthAwarePaginator<GuestPhoto>` |
| `$cottages` | `Collection` of `{cottage_id, cottage_name, total}` |
| `$active` | `int` — the selected filter |

Image URLs come from `GuestPhoto::getUrlAttribute()`, which returns `null` unless
`status === Approved && disk === 'public'`. A template that forgets to check status
therefore renders nothing rather than leaking an unmoderated image.

`pages/gallery.blade.php:44` mounts `imageLightbox` with an inlined `@js(...)` payload
built from `$photos`.

---

# 11. Reviews — `GET /reviews`

**Route** `routes/web.php:166` → `ReviewController@index` → `pages.reviews`

The thinnest controller in the app:

```php
return view('pages.reviews', ['data' => $this->google->fetch()]);
```

### `GoogleReviewsService::fetch()`

```
if (!isConfigured())              // maps_key or place_id empty
    return array_merge($empty, ['error' => configurationHint()])
    ── configurationHint() names WHICH piece is missing, and specifically warns
       that a set GOOGLE_MAPS_API_KEY with no `google` block in config/services.php
       looks identical to an unset key.
    ── note the comment on array_merge vs `+`: `$empty + ['error'=>…]` keeps the
       LEFT value where a key exists in both, and $empty already has error=>null,
       which silently discarded every error message this class produced.

Cache::remember('google:reviews:'.$placeId, services.google.cache_ttl /* 21600 */)
  GET https://places.googleapis.com/v1/places/{placeId}
    headers:
      X-Goog-Api-Key:   {key}
      X-Goog-FieldMask: id,displayName,rating,userRatingCount,
                        googleMapsUri,reviews,photos
      ── the field mask is REQUIRED by Places API (New), and asking for fewer
         fields is also cheaper per call.
  !successful() → Log::warning + { error: 'Could not load reviews right now.' }
  → mapReviews()  (excerpt trimmed to services.google.excerpt_words, default 38)
  → mapPhotos()   (capped at services.google.max_photos, default 12)
       └─ resolvePhotoUri($photoName)  — separately cached per photo
```

**Hard upstream limit:** the Places API returns **at most 5 reviews** per place, chosen
by Google. That is a cap, not a paging limit — there is no parameter to get more. The
full set requires the Google Business Profile API, which needs ownership verification of
the listing. The service docblock states this explicitly and notes that a Business
Profile strategy could be added later with no view changes.

⚠ `services.google.excerpt_words` and `services.google.max_photos` are **not defined** in
`config/services.php`, so both always use their hardcoded fallbacks and the corresponding
env vars are inert. See [`01-architecture.md` D4](01-architecture.md).

| Variable | Shape |
|---|---|
| `$data` | `{ configured: bool, rating: ?float, total: ?int, reviews: [], photos: [], url: ?string, error: ?string }` |

---

# 12. Contact — `GET /contact`, `POST /contact`

**Routes** `routes/web.php:77-80`; POST is `throttle:6,1`

### GET
`ContactController@create` → `view('pages.contact')`. No data, no queries.

### POST — validated by `StoreContactMessageRequest`

| Field | Rules |
|---|---|
| `name` | required, string, max 120 |
| `email` | required, `email:rfc`, max 180 |
| `phone` | nullable, string, max 40 |
| `subject` | nullable, string, max 160 |
| `message` | required, string, min 10, max 4000 |
| `website_url` | **`prohibited`** — honeypot, invisible to people, filled by bots |

Honeypot failures return the deliberately opaque message *"Something went wrong. Please
try again."* rather than revealing the trap.

```php
$message = ContactMessage::create($request->safe()->except('website_url') + [
    'ip_address' => $request->ip(),
    'user_agent' => substr((string) $request->userAgent(), 0, 512),
]);
// Model::creating → reference = 'MSG-' . strtoupper(Str::random(6))

try { /* Mail::to(config('mail.enquiries_to'))->send(new ContactReceived($message)); */ }
catch (\Throwable $e) { Log::error('Contact notification failed', [...]); }

return back()->with('contact_sent', $message->reference);
```

Two things to note:

1. `$request->safe()->except(...)` returns **only validated keys**, which is what makes
   `$guarded = []` on the model non-exploitable here.
2. **The notification email is commented out.** So is the one in `BusinessStayController`.
   No one is emailed when an enquiry arrives — the admin queue at `/admin/messages` is
   currently the *only* delivery mechanism. The try/catch and the "never let a mail
   failure lose the message" comment are in place and correct; the send itself is not
   wired up.

---

# 13. Business stays (corporate enquiries)

**Routes** `routes/web.php:68-75` — `GET /business-stays`, `POST /business-stays`
(`throttle:6,1`), `GET /business-stays/thank-you`

### POST — validated by `StoreBusinessStayRequest`

The richest form in the app: company block (`company_name` required, `industry`,
`website`, `tax_number`), contact block (`contact_name` required, `job_title`, `email`
required, `phone`), stay block (`check_in`/`check_out`, `dates_flexible`,
`flexible_note`, `guests_count` required 1–200, `cottages_count` required 1–20),
commercial block (`purpose`, `budget_per_night` 0–100000, `currency` size:3), needs
(`needs_invoice`, `needs_meeting_space`, `pets`), `message` max 2000, and a
`company_website_url` honeypot.

**`prepareForValidation()`** normalises before rules run: uppercases `currency`
(defaulting to `CAD`) and coerces all four booleans via `$this->boolean(...)`, so an
absent checkbox becomes `false` rather than failing a `boolean` rule.

**`after()` — two cross-field rules that ordinary rules cannot express:**

```php
// 1. Either fixed dates or an explicit "we're flexible". An enquiry with
//    neither leaves nothing to quote against.
if (!$this->filled('check_in') && !$this->boolean('dates_flexible'))
    → error on check_in: 'Add a check-in date, or tick "our dates are flexible".'

// 2. More cottages than guests is almost always a slip. Six cottages sleep ~36,
//    so this is a gentle sanity check, not a hard capacity rule.
if ($this->integer('cottages_count') > $this->integer('guests_count'))
    → error on cottages_count: 'That is more cottages than guests — please double-check.'
```

### Persistence and redirect

```php
$stay = BusinessStayRequest::create($request->validated() + [
    'source' => 'website', 'ip_address' => …, 'user_agent' => …,
]);
```

`BusinessStayRequest::booted()::creating` then:
- assigns `reference` via `generateReference()` — `BS-` + 6 chars, with vowels and
  lookalikes (`O I L 0 1`) substituted out so it survives being read aloud, looped
  against `withTrashed()` until unique;
- derives `nights` from `check_in`/`check_out` when both are present, so the admin list
  can sort and total on it without recomputing per row.

Notification email is commented out (same as §12), then:

```php
return redirect()->route('business-stays.thanks')
                 ->with('business_stay_reference', $stay->reference);
```

### GET `/business-stays/thank-you`
Reads `session('business_stay_reference')`. If absent (someone landed here directly),
redirects back to `business-stays.create` — there is nothing to confirm.

---

# 14. Guest photo upload

**Routes** `routes/web.php:84-89` — `GET /share-your-photos`,
`POST /share-your-photos` (`throttle:3,1` — *"Uploads are expensive; 3 submissions a
minute is generous for a person and useless to anyone trying to fill the disk."*)

### GET
```php
try { $cottages = $this->lodgify->allCottages(); }
catch (\Throwable $e) { Log::warning('Photo upload page: cottage list unavailable', …); }
return view('pages.photo-upload', ['cottages' => $cottages]);
```
The cottage select degrades to empty rather than breaking the form.

### POST — validated by `StoreGuestPhotoRequest`

```php
'photos'   => ['required','array','min:1','max:10'],
'photos.*' => ['required',
               File::image()->types(['jpg','jpeg','png','webp','heic'])
                            ->max(12 * 1024),                    // 12 MB
               'dimensions:min_width=600,min_height=400'],
'consent_given' => ['accepted'],
'website_url'   => ['prohibited'],
```

`File::image()` inspects the **actual MIME type**, not the extension, so a renamed `.php`
fails validation rather than reaching disk. `consent_given` is `accepted` rather than a
quietly-defaulted checkbox — publishing someone's photo needs a recorded yes.

### Storage flow

```
foreach ($request->file('photos', []) as $file):

    $path = $file->store('guest-photos/pending', 'local');
      ── 'local' disk root = storage_path('app/private'), NOT web-reachable.
         Two reasons: nothing unmoderated is ever reachable by URL, and the
         randomised store() name means the guest's original filename (often a
         device path) never appears in a URL.

    [$width, $height] = $this->dimensions($file->getRealPath());
      ── @getimagesize(); doubles as a second content check — a non-image
         returns false here even if it slipped past validation.

    GuestPhoto::create([
        guest_name, guest_email, caption, cottage_id,
        cottage_name  ← resolved best-effort from LodgifyRepository::cottage($id);
                        a failure is swallowed because a missing name is cosmetic
        stayed_on, disk:'local', path, 
        original_name ← Str::limit(clientOriginalName, 190, '')
        mime, size_bytes, width, height,
        status: GuestPhotoStatus::Pending,
        consent_given: true,
        ip_address, user_agent (512),
    ]);
    // Model::creating → uuid = Str::uuid()
```

Returns `back()->with('photos_uploaded', $saved)`.

---

# 15. Static pages

| Route | Declaration |
|---|---|
| `GET /things-to-do` | `Route::view('/things-to-do', 'pages.things-to-do')->name('things-to-do')` |
| `GET /privacy-and-policy` | `Route::view('/privacy-and-policy', 'pages.privacy-and-policy')->name('privacy')` |

No controller, no queries. Both render inside `x-website-layout`, so they get the shared
nav and footer, but neither includes `<x-booking-search>` — so these two pages issue no
XHR at all.

---

# 16. Authentication

## `GET /login` · `POST /login` — `Auth\LoginController`

`GET` short-circuits an already-authenticated visitor:
`if (Auth::check()) return redirect()->intended($this->homeFor(Auth::user()));`

`POST` (route `throttle:10,1` **plus** an in-controller limiter):

```
validate email (required|string|email), password (required|string)

assertNotRateLimited()
  throttleKey = Str::transliterate(Str::lower(email) . '|' . request->ip())
  RateLimiter::tooManyAttempts(key, 5)
    → ValidationException "Too many attempts. Please try again in N seconds."
  ── five attempts a minute per email+IP: headroom for a typo, far too slow for
     credential stuffing.

Auth::attempt($credentials, $request->boolean('remember'))
  false → RateLimiter::hit(key)
        → ValidationException on 'email':
          "Those credentials don't match our records."
          ── ONE message for both a wrong email and a wrong password. Saying
             "no account with that email" tells an attacker which addresses
             are registered.

true  → RateLimiter::clear(key)
      → $request->session()->regenerate()        // prevents session fixation
      → forceFill(last_login_at: now(), last_login_ip: request->ip())->save()
      → redirect()->intended(homeFor($user))
           admin → admin.business-stays.index
           guest → home
```

## `POST /logout` (`auth`)
`Auth::logout()` → `session()->invalidate()` → `session()->regenerateToken()` →
redirect `home`. All three steps present — logout is complete, not just a guard flip.

## `GET /register` · `POST /register` (`guest`, `throttle:6,1`)

```php
'name'        => ['required','string','max:120'],
'email'       => ['required','string','email:rfc','max:180','unique:users,email'],
'password'    => ['required','confirmed', Rules\Password::defaults()],
'website_url' => ['prohibited'],   // honeypot
// custom message on email.unique steers the user to sign in instead
```

```php
$user = User::create([
    'name' => …, 'email' => strtolower(…),
    'password' => $validated['password'],   // hashed by the model's 'hashed' cast
    'is_admin' => false,                    // never from a public form
]);
event(new Registered($user));               // sends the verification email
Auth::login($user);
return redirect()->route('verification.notice');
```

The controller docblock states the security model plainly: reservations are matched to a
user **by email address**, an email address is not a secret, therefore **email
verification is the security boundary, not a nicety**. Users are created unverified and
`/my-stays` is gated behind `verified`.

## Password reset

**`POST /forgot-password`** (`throttle:5,1`) — always reports success, whatever
`Password::sendResetLink()` returns, because distinguishing "sent" from "no such user"
turns the form into an account-enumeration oracle. The one exception is
`Password::RESET_THROTTLED`, which *is* surfaced — otherwise the user keeps pressing the
button. Any other non-success status is `report()`ed, not shown.

**`POST /reset-password`** (`throttle:5,1`) — `Password::reset()` with
`Rules\Password::defaults()`, and inside the callback:

```php
$user->forceFill([
    'password'       => $request->string('password')->toString(),
    'remember_token' => Str::random(60),   // invalidates "remember me" everywhere
])->save();
event(new PasswordReset($user));
```

Rotating `remember_token` is the correct and frequently-omitted step: without it, a
stolen remember-me cookie survives the password change.

`User::sendPasswordResetNotification()` is overridden to use
`App\Notifications\ResetPasswordNotification`, so the link points at the app's named
route rather than Laravel's default.

## Email verification (`routes/web.php:195-207`)

| Route | Middleware | Action |
|---|---|---|
| `GET /verify-email` | `auth` | renders `auth.verify-email` |
| `GET /verify-email/{id}/{hash}` | `auth`, **`signed`** | `EmailVerificationRequest::fulfill()` → redirect `profile.index` |
| `POST /verify-email/send` | `auth`, `throttle:6,1` | `$request->user()->sendEmailVerificationNotification()` |

`User implements MustVerifyEmail`. The route file carries the rationale in a block
comment marked **"NOT OPTIONAL"**: without verification, anyone could register with a
past guest's address and read their booking history.

---

# 17. Guest profile — "My stays"

All four routes are `['auth','verified']`.

## `GET /my-stays` — `ProfileController@index`

```
$user = $request->user()

try: $reservations = ReservationRepository::forEmail($user->email)
       └─ all()
            Cache::remember('v1:reservations:all', lodgify.reservations /* 300 */)
              page = 1
              do:
                LodgifyClient::listBookings(['page'=>$page,'size'=>50,'stay'=>'All'])
                  GET /v2/reservations/bookings
                  ⚠ query parameter names are BEST GUESSES — Lodgify's v2 is
                    inconsistent (rates/calendar wants PascalCase, /v2/quote wants
                    ASP.NET dot notation). Verify with /debug/lodgify/probe/bookings.
                extractItems($payload)  → [] breaks the loop
                page++
                ── `count` comes back null, so there is no total to page against:
                   keep going while a FULL batch arrives, stop on a short one.
                if page > 40 → Log::warning('Reservation paging hit the safety
                               limit') and break
                               (a paging bug must not loop forever against a paid API)
              while (count($batch) >= 50)
            └─ map → mapReservation() → App\DTO\Reservation
            └─ reject isDeleted   (Lodgify keeps deleted bookings in the feed)
            └─ sortByDesc(arrival)
       └─ filter on lowercased guest email
catch → report($e); $failed = true

$grouped = [
  'current'   => timeframe() === 'current',
  'upcoming'  => timeframe() === 'upcoming', sorted by arrival ASC,
  'past'      => timeframe() === 'past',
  'cancelled' => timeframe() === 'cancelled',
]
$nights = $reservations->reject->isCancelled()->sum(nights)
```

`ReservationRepository::forEmail()` carries a ⚠ contract in its docblock: **callers must
have verified email ownership first.** The `verified` middleware on the route is what
satisfies it.

| Variable | Type |
|---|---|
| `$user` | `App\Models\User` |
| `$grouped` | `array{current,upcoming,past,cancelled: Collection<Reservation>}` |
| `$total` | `int` |
| `$nights` | `int` |
| `$failed` | `bool` |

## `GET /my-stays/{id}` — `ProfileController@show`

```php
$reservation = $this->reservations->find($id);
// Cache::remember("v1:reservation:{$id}", 300) → getBooking($id)
//   GET /v2/reservations/bookings/{id}
//   empty → falls back to all()->firstWhere('id', $id)

if (!$reservation
    || strtolower((string) $reservation->guestEmail) !== strtolower($request->user()->email)) {
    return redirect()->route('profile.index')
                     ->with('profile_error', "We couldn't find that booking on your account.");
}
```

**An ownership check, not just an existence check** — and the comment explains why it has
to be: without comparing the email, the booking id alone would be an access token, and
Lodgify ids are sequential and therefore trivially guessable. The failure message is
deliberately identical for "does not exist" and "not yours", so it is not an oracle.

## `GET /account`, `PATCH /account` — `ProfileController@edit` / `@update`

```php
'name'  => ['required','string','max:120'],
'email' => ['required','email:rfc','max:180','unique:users,email,'.$user->id],

$emailChanged = strtolower($validated['email']) !== strtolower($user->email);
$user->fill(['name' => …, 'email' => strtolower(…)]);
if ($emailChanged) $user->email_verified_at = null;
$user->save();

if ($emailChanged) {
    $user->sendEmailVerificationNotification();
    return redirect()->route('verification.notice');
}
```

Correct and important: changing the email changes **which reservations are visible**, so
verification is reset and the account loses access to `/my-stays` (the `verified`
middleware) until the new address is confirmed. Without this, changing your email to a
past guest's would hand you their booking history.

## `PUT /account/password` — `ProfileController@updatePassword`

```php
'current_password' => ['required','current_password'],
'password'         => ['required','confirmed', Rules\Password::defaults()],
$request->user()->update(['password' => $validated['password']]);
```

`current_password` re-authenticates before the change (defence against a hijacked
session). Note this path does **not** rotate `remember_token`, unlike the reset flow —
see [`03-security.md` §F7](03-security.md).

---

# 18. Admin — business stay requests

`GET|PATCH|DELETE /admin/business-stays[/{businessStayRequest}]`, all `['auth','admin']`,
route-model-bound. Views under `resources/views/admin/business-stays/`.

## `index`

```php
// Sort column is ALLOWLISTED, never taken raw — this is the injection-safe pattern
$sort = in_array($request->query('sort'),
        ['created_at','check_in','guests_count','company_name'], true)
        ? $request->query('sort') : 'created_at';
$dir  = $request->query('dir') === 'asc' ? 'asc' : 'desc';

BusinessStayRequest::query()
    ->search($request->query('q'))     // reference, company_name, contact_name,
                                       // email, phone — LIKE %term%
    ->status($request->query('status'))
    ->when($request->query('view') === 'open', fn ($q) => $q->open())
                                       // status IN (new, contacted, quoted)
    ->orderBy($sort, $dir)
    ->paginate(20)->withQueryString();

$counts = statusCounts();   // one GROUP BY, plus 'all' => array_sum
```

## `show`
`view('admin.business-stays.show', ['stay' => $businessStayRequest->load('handler')])` —
eager-loads the `handled_by` user to avoid an N+1 in the view.

## `update`

```php
'status'         => ['required','string','in:'.implode(',', array_keys(BusinessStayStatus::options()))],
'internal_notes' => ['nullable','string','max:5000'],
```

Milestone stamping — *"stamp the milestone the first time each status is reached, so the
timeline reflects what actually happened rather than the last edit"*:

| Transition | Stamped |
|---|---|
| → `Contacted` and `contacted_at` is null | `contacted_at = now()` |
| → `Quoted` and `quoted_at` is null | `quoted_at = now()` |
| → any `!isOpen()` status (`Won`/`Lost`) and `closed_at` is null | `closed_at = now()` |

`handled_by` is set to the acting user **only if not already set** (`?? $request->user()?->id`)
— first responder keeps ownership.

## `destroy`
`$businessStayRequest->delete()` — **soft delete**, with the comment *"enquiries are
evidence."* `generateReference()` checks `withTrashed()`, so an archived reference is
never reissued.

---

# 19. Admin — contact messages

`GET|PATCH|DELETE /admin/messages[/{contactMessage}]`, `['auth','admin']`.

Structurally parallel to §18, with two differences:

- **`show()` marks the message read as a side effect of opening it** —
  `$contactMessage->markRead()`, which only fires when `status === New` so it can't
  clobber `Replied`/`Archived`. *"Opening it is what 'read' means — no extra click
  required."*
- `update()` stamps `replied_at` on the first transition to `Replied`.

`ContactMessage::scopeSearch()` covers `reference, name, email, phone, subject, message`;
`scopeUnhandled()` is `status IN (new, read)`. `getExcerptAttribute()` collapses
whitespace and truncates to 110 chars for the list view.

---

# 20. Admin — guest photo moderation

`['auth','admin']`. This is the most stateful admin flow, because approval **moves a file
between disks**.

## `index`
Defaults to `status = pending` — *"the queue that needs attention rather than
everything."* Free-text search across `guest_name`, `guest_email`, `caption`;
`paginate(24)`.

## `file` — `GET /admin/photos/{guestPhoto}/file`

```php
abort_unless(Storage::disk($guestPhoto->disk)->exists($guestPhoto->path), 404);
return Storage::disk($guestPhoto->disk)->response(
    $guestPhoto->path, $guestPhoto->original_name,
    ['Content-Type' => $guestPhoto->mime, 'Cache-Control' => 'private, max-age=300']
);
```

A `StreamedResponse` from the **private** disk. Pending uploads are never on the public
disk, so this authenticated route is the only way to view one — which is the point.
`Cache-Control: private` keeps it out of shared caches.

⚠ `Content-Type` is echoed from the stored `mime` column, which came from
`$file->getMimeType()` at upload. See [`03-security.md` §F5](03-security.md).

## `approve` — the promotion transaction

```
validate caption (nullable, max 300), is_featured (sometimes, boolean)

if (status !== Approved):
  try:
    $publicPath = 'guest-photos/' . basename($guestPhoto->path);

    1. COPY   Storage::disk('public')->put($publicPath,
                  Storage::disk($guestPhoto->disk)->get($guestPhoto->path));
    2. UPDATE record → disk:'public', path:$publicPath, status:Approved,
                       rejection_reason:null, reviewed_by, reviewed_at,
                       caption, is_featured
    3. DELETE the private original — ONLY now, and only if it was on 'local'

    ── ordering is deliberate: the record is marked approved only AFTER the copy
       succeeds, so a storage failure can never leave a published photo pointing
       at a file that is not there.
  catch → Log::error('Photo approval failed')
        → back()->withErrors(['photo' => 'Could not publish that photo…'])
else:
  // already approved — just edit caption / featured flag
```

## `reject`

```
if (disk === 'public'):            // it had already been published — pull it back
  try:
    copy public → local 'guest-photos/pending/'.basename(path)
    delete from public
    disk = 'local'; path = $privatePath
  catch → Log::error('Photo unpublish failed')   // continues to the status change

forceFill(status: Rejected, rejection_reason, reviewed_by, reviewed_at,
          is_featured: false)->save()
```

The file **stays on the private disk** rather than being deleted — *"a rejection may need
revisiting, and the record is evidence of what was submitted."*

Note the failure mode: if the unpublish copy throws, the status still flips to `Rejected`
while the file remains on the public disk. `getUrlAttribute()` returns `null` for a
non-approved photo, so it disappears from the site — but the raw file stays
web-reachable at its old URL until someone cleans up.

## `destroy`

```php
$guestPhoto->forceDelete();   // model's `forceDeleted` event deletes the file too
```

`GuestPhoto::booted()` registers `forceDeleted` → `Storage::disk($p->disk)->delete($p->path)`
in a try/catch. Soft deletes intentionally leave the file in place; only a hard delete
purges, so "purging really purges."

---

# 21. Admin — reservations (live from Lodgify)

`['auth','admin']`. Read-only; nothing is written locally.

## `index`

```php
$filters = [ q, email, status, timeframe(default 'all'), property_id, source,
             from, to, unpaid(bool), sort(default 'arrival'), dir(default 'desc') ];

$results = ReservationRepository::search($filters);   // all() then filter in PHP

// Paginated in PHP because the whole set is already in memory: Lodgify's paging
// support is unconfirmed, and six cottages produce a small enough volume that
// this is simpler AND lets filters work across everything.
$paginated = new LengthAwarePaginator(
    $results->forPage($page, 25)->values(), $results->count(), 25, $page,
    ['path' => $request->url(), 'query' => $request->query()]
);
```

The view also receives `filterOptions()` (distinct statuses / sources / properties,
derived from the data itself so the selects can never offer an empty result) and
`stats()` (total, upcoming, current, past, unpaid). Each of those calls `all()` again —
three calls total per page render, all served from the same 300 s cache entry.

## `show`
`find($id)` → `NotFoundHttpException` when missing. **No ownership check** — correct
here, this is the admin surface.

## `refresh` — `POST /admin/reservations/refresh`

```php
$this->reservations->flush();   // Cache::forget('v1:reservations:all')
return back()->with('status', 'Reservations refreshed from Lodgify.');
```

*"When someone is on the phone with a guest who has just booked, waiting for a TTL is not
acceptable."* Note it forgets only the list key, not the per-reservation
`v1:reservation:{id}` entries.

---

# 22. Admin — checkout intents

`GET /admin/checkouts`, `['auth','admin']` → `Admin\CheckoutIntentController@index`

```php
$intents = CheckoutIntent::query()->status($status)   // 'all' is a no-op
              ->latest()->paginate(30)->withQueryString();

$grace     = config('lodgify.checkout_grace_minutes', 90);
$total     = CheckoutIntent::count();
$converted = CheckoutIntent::converted()->count();       // status = 'converted'
$stale     = CheckoutIntent::stale($grace)->count();     // 'redirected' AND older than grace

$stats = [
  'total' => $total, 'converted' => $converted, 'abandoned' => $stale,
  'in_flight' => max(0, $total - $converted - $stale),
  'rate'      => $total > 0 ? round($converted / $total * 100, 1) : null,
];
```

The grace window matters: a guest may take twenty minutes over Lodgify's three checkout
steps, so anything younger than 90 minutes is *in flight*, not abandoned. Abandonment is
computed on read rather than written back, *"so a late webhook can still claim it."*

⚠ As covered in §9, no webhook exists, so `converted` is always 0 and `rate` always 0.0.

---

# 23. Debug routes — local/staging only

```php
if (app()->environment(['local', 'staging'])) {
    Route::prefix('debug/lodgify')->group(function () {
        Route::get('/',                  [DebugController::class, 'lodgify']);
        Route::get('/why',               [DebugController::class, 'why']);
        Route::get('/flush',             [DebugController::class, 'flush']);
        Route::get('/probe/rates/{id}',  [DebugController::class, 'probeRates']);
        Route::get('/probe/photos/{id}', [DebugController::class, 'probePhotos']);
        Route::get('/images/{id}',       [DebugController::class, 'images']);
        Route::get('/raw/{what}/{id?}',  [DebugController::class, 'raw']);
    });
}
```

The environment check happens at **route-registration** time, so in production these
paths do not exist at all (404 from the router, no controller reached). `DebugController`
opens with *"NOT for production. Gate behind auth or remove before shipping."*

| Route | Purpose |
|---|---|
| `/debug/lodgify` | `LodgifyClient::diagnose()` transport health + every mapped cottage + an aggregate-availability summary |
| `/debug/lodgify/raw/{what}/{id?}` | Raw unmapped JSON. `{what}` is **allowlisted** to 19 values; anything else returns 422 with the allowed list. Adds a `shape` block (type, count, top-level keys) — usually the thing you actually need when reconciling a mapper against reality. |
| `/debug/lodgify/why?arrival=&departure=` | `explainAvailability()` — per-cottage, per-night, with the reason a cottage was excluded, so "no results" is never a mystery |
| `/debug/lodgify/probe/rates/{id}` | Tries every `rates_param_style` and reports which one Lodgify accepts |
| `/debug/lodgify/probe/photos/{id}` | Tries every image strategy and reports counts |
| `/debug/lodgify/images/{id}` | `resolveWithSource()` — per-strategy image counts and samples |
| `/debug/lodgify/flush` | `LodgifyRepository::flushCache()` ⚠ a **GET** that mutates state, and on non-tagged drivers this is `Cache::flush()` — see [`03-security.md` §F8](03-security.md) |

Also worth knowing: `AvailabilityController@month` and `RateController@month` include
`lastErrors()` in a `notes` array **only** under `local`/`staging`; in production it is
always `[]`, so upstream error strings never reach a visitor.

---

# 24. Health check — `GET /up`

Registered by `bootstrap/app.php` via `withRouting(health: '/up')`. Framework-provided:
boots the application and returns 200. It does **not** check Lodgify reachability,
database connectivity, or cache health — extending it is a Stage-3 recommendation in
[`01-architecture.md`](01-architecture.md).

---

# Appendix A — Upstream endpoint inventory

Every external URL the application can call, and from where.

## `api.lodgify.com` — authenticated, `X-ApiKey` header (`LodgifyClient::http()`)

| Method | Endpoint | Called by |
|---|---|---|
| GET | `/v2/properties` | `listProperties()`, `ping()`, `diagnose()` |
| GET | `/v2/properties/{id}` | `getProperty()` |
| GET | `/v2/properties/{id}/rooms` | `raw('rooms')` |
| GET | `/v1/properties/{id}` | `getPropertyV1()` |
| GET | `/v1/properties/{id}/rooms/{roomId}` | `getRoomInfo()` — **the full photo gallery with alt text** |
| GET | `/v1/properties/{id}/rates/addons` | `getAddons()` |
| GET | `/v1/properties/{id}/payments` | `getPaymentOptions()` |
| GET | `/v2/availability/{id}` | `getAvailability()` |
| GET | `/v2/rates/settings?houseId={id}` | `getRateSettings()` |
| GET | `/v2/rates/calendar` | `getRatesCalendar()`, `probeRatesCalendar()` — **PascalCase params** |
| GET | `/v2/quote/{id}` | `getQuote()` — ASP.NET dot-notation params |
| GET | `/v2/reservations/bookings` | `listBookings()`, `probeBookings()` |
| GET | `/v2/reservations/bookings/{id}` | `getBooking()` |
| POST | `/v2/reservations/bookings` | `createBooking()` — **defined but never called** |

## `checkout.lodgify.com` — unauthenticated, behind Cloudflare (`LodgifyClient::publicHttp()`)

| Method | Endpoint | Called by |
|---|---|---|
| GET | `/api/v1/checkout/{propertyId}` | `getPublicRates()` |
| GET | `/api/v1/checkout/calendar` | `getPublicCalendar()` |
| GET | `/api/v1/checkout/price` | `getPublicCheckoutPrice()` |

Cloudflare 403s bare Guzzle, so `publicHttp()` sends `BROWSER_HEADERS` (a Chrome 126
User-Agent, `Sec-Fetch-*`, `Accept-Language`) plus `Referer`/`Origin` set to
`lodgify.public_site_origin`. Treat as **best-effort fallback only** — it is undocumented
and can break without notice.

## Other hosts

| Host | Endpoint | Status |
|---|---|---|
| `property.lodgify.com` | `/api/v3/property/{id}/images/all` | Authenticates with a **dashboard session**, not the API key. Without one it returns HTTP 200 with `{"success": false, "statusCode": "HTTP_Unauthorized"}`. A cookie can be pasted into `LODGIFY_DASHBOARD_COOKIE` but it expires and grants far more than photo read — not a production strategy. |
| `rates.lodgify.com` | `/api/v2/rates/addons/property/{id}` | Session-locked; returns 401/403 for every API-key variant. The documented `/v1/properties/{id}/rates/addons` is used instead. |
| `places.googleapis.com` | `/v1/places/{placeId}` | Places API (New). `X-Goog-Api-Key` + mandatory `X-Goog-FieldMask`. Max 5 reviews. |
| `l.icdbcdn.com` | image CDN | Takes an `f=` transform preset. Lodgify hands back `f=32` (a thumbnail); `lodgify.image_size_param` / `image_size_large` override it. Unknown presets silently return the original. |
| `fonts.googleapis.com` / `fonts.gstatic.com` | webfonts | Loaded directly by `layouts/website.blade.php` (Fraunces, Plus Jakarta Sans, Space Mono) |

## Transport settings (all from `config/lodgify.php`)

| Setting | Env | Default |
|---|---|---|
| `timeout` | `LODGIFY_TIMEOUT` | 15 s |
| `retries` | `LODGIFY_RETRIES` | 2 |
| `retry_delay_ms` | `LODGIFY_RETRY_DELAY_MS` | 300 ms |

`http()` and `publicHttp()` both use `->retry($retries, $retryDelay, throw: false)`, so a
failed request returns a `Response` rather than throwing; `assertOk()` then logs
(status, `Server`, `Cf-Ray`, first 500 bytes of body) and raises `LodgifyApiException`,
which carries `status` + `responseBody` and exposes `guestMessage()` for 4xx bodies that
are fit to show a customer.

---

# Appendix B — Alpine component ↔ endpoint map

| Component | File | Mounted by | Endpoints called |
|---|---|---|---|
| `bookingSearch` | `resources/js/components/booking-search.js` | `components/booking-search.blade.php`, included by `pages/home.blade.php` (lines 31, 240), `pages/cottages.blade.php` (lines 29, 154) and `pages/availability-results.blade.php` (line 48) | `GET /api/availability/month?start=` |
| `cottageCalendar` | `resources/js/cottage-calendar.js` | `pages/cottage.blade.php:21` | `GET /api/cottage/{slug}/rates`, `…/quote`, `…/addons`; navigates to `/book/{slug}` |
| `imageLightbox` | `resources/js/image-lightbox.js` | `pages/cottage.blade.php:40`, `pages/gallery.blade.php:44` | none — payload inlined via `@js()` |

All three are registered in `resources/js/app.js` **before** `Alpine.start()`. The file's
comment explains why that ordering is mandatory: `start()` walks the DOM and evaluates
every `x-data` expression in one pass, so a component registered afterwards is invisible
to elements already on the page — the expression throws `<name> is not defined` and no
data object is created, which is why every child expression (`loading`, `grid`, `quote`, …)
then reports as undefined too.

`app.js` also wires a dependency-free scroll-reveal: any `[data-reveal]` element fades up
via `IntersectionObserver` at a 0.15 threshold, with a staggered `animationDelay`, and
`prefers-reduced-motion` handled in `app.css`.
