# Caching, Database, and Performance

- [Part 1 — Caching as implemented](#part-1--caching-as-implemented)
- [Part 2 — Database as implemented](#part-2--database-as-implemented)
- [Part 3 — Measured hot paths](#part-3--measured-hot-paths)
- [Part 4 — Caching improvements](#part-4--caching-improvements)
- [Part 5 — Performance improvements](#part-5--performance-improvements)
- [Part 6 — Prioritised plan](#part-6--prioritised-plan)

---

# Part 1 — Caching as implemented

## 1.1 Driver configuration

| Concern | Config | Default | Table |
|---|---|---|---|
| Cache | `cache.default` ← `CACHE_STORE` | `database` | `cache`, `cache_locks` |
| Session | `session.driver` ← `SESSION_DRIVER` | `database` | `sessions` |
| Queue | `queue.default` ← `QUEUE_CONNECTION` | `database` | `jobs`, `job_batches`, `failed_jobs` |
| Database | `database.default` ← `DB_CONNECTION` | `sqlite` | — |

**All four land on the same SQLite file by default.** That is the single most consequential
infrastructure fact in this document: cache reads/writes, session writes, queue polling and
application queries all contend for one file-level write lock. See [§5.1](#51-move-off-the-single-sqlite-file).

`config/cache.php` also sets `'serializable_classes' => false`, which forbids
unserializing any PHP class from the cache. This is why the codebase's
primitives-only rule matters operationally as well as for correctness — objects
in the cache would not merely be fragile, they would be unreadable.

## 1.2 Three independent caching mechanisms

The codebase has **three separate cache-access implementations**, which is worth knowing
before you try to invalidate anything.

### (a) `LodgifyRepository::rememberArray()` — versioned, optionally tagged

```php
protected function rememberArray(string $key, int $ttl, \Closure $callback): mixed
{
    $tag     = (string) config('lodgify.cache_tag', 'lodgify');
    $driver  = config('cache.default');
    $useTags = in_array($driver, ['redis', 'memcached'], true);

    $versioned    = self::CACHE_VERSION . ':' . $key;          // 'v3:…'
    $store        = $useTags ? Cache::tags([$tag]) : Cache::store();
    $effectiveKey = $useTags ? $versioned : "{$tag}:{$versioned}";

    return $store->remember($effectiveKey, $ttl, function () use ($callback) {
        $value = $callback();
        if (is_object($value)) {
            throw new \LogicException('LodgifyRepository cache callback returned an object…');
        }
        return $value;
    });
}
```

Three properties worth noting:

1. **Version prefix.** `CACHE_VERSION = 'v3'`. Bumping the constant invalidates every
   entry without a deploy-time `cache:clear`. The docblock: *"Bump this whenever the
   cached SHAPE changes."*
2. **Driver-aware tagging.** Tags only when the driver supports them; otherwise it folds
   the tag into the key as a prefix (`lodgify:v3:…`). With the default `database` driver
   **the tagged branch never runs** — see [§4.2](#42-scope-flushcache-on-non-tagging-drivers).
3. **Runtime object guard.** The `LogicException` is what enforces the primitives-only
   invariant in practice rather than by convention.

### (b) `PropertyImageResolver` — the same shape, duplicated

`CACHE_VERSION = 'v2'`, its own `cacheStore()` with the identical driver check, but it
uses `get()`/`put()` rather than `remember()` because it needs to make a decision about
whether the result is worth caching at all:

```php
$fingerprint = substr(md5(implode(',', $this->strategies())), 0, 8);
$cacheKey    = self::CACHE_VERSION . ":images:{$propertyId}:{$fingerprint}";
```

Two good ideas here:

- **The strategy list is fingerprinted into the key**, so reordering
  `LODGIFY_IMAGE_STRATEGIES` in `.env` takes effect immediately instead of after a 6-hour
  TTL.
- **A single-image result is deliberately not cached.** The comment explains why: a caller
  working from the thin `/v2/properties` list payload (which carries only `image_url`)
  would otherwise poison the cache for six hours and starve a later caller that has the
  full gallery. This is a real bug that was found and fixed — and the same class of bug is
  documented again in `allCottages()`, where mapping used to run twice and the first pass
  wrote "1 image" into the cache.

### (c) `ReservationRepository` — hand-rolled, **not tagged**

```php
$key = self::CACHE_VERSION . ':reservations:all';        // 'v1:reservations:all'
Cache::remember($key, (int) config('lodgify.cache.reservations', 300), …);
Cache::remember(self::CACHE_VERSION . ":reservation:{$id}", …);
public function flush(): void { Cache::forget(self::CACHE_VERSION . ':reservations:all'); }
```

No tag, no `lodgify:` prefix. **Consequence:** `LodgifyRepository::flushCache()` does not
clear reservation caches on a tagging driver — the tagged flush only touches keys written
through `rememberArray()`. On the default non-tagging driver it happens to work, because
that path degrades to `Cache::flush()` (everything). So the behaviour of "flush the
Lodgify cache" differs by driver, which is exactly the kind of thing that surprises you
during an incident.

`flush()` also forgets only the list key, not the per-reservation `v1:reservation:{id}`
entries — so `POST /admin/reservations/refresh` refreshes the list but a detail page can
still serve a stale record for up to 300 s.

### (d) `GoogleReviewsService` — plain `Cache::remember`

```php
Cache::remember('google:reviews:' . $this->placeId, config('services.google.cache_ttl'), …)
Cache::remember(/* per-photo URI key */, …)   // resolvePhotoUri()
```

Outside both the version scheme and the tag, so neither `flushCache()` nor a version bump
touches it.

## 1.3 Complete cache-key inventory

Logical keys. On the default `database` driver each is stored as
`lodgify:v3:<key>` (repository), `v2:<key>` (image resolver), `v1:<key>` (reservations),
plus the global `cache.prefix` (`Str::slug(APP_NAME).'-cache-'`).

### `LodgifyRepository` (`CACHE_VERSION = v3`, tag `lodgify`)

| Logical key | TTL config | Default | Written by | Cardinality |
|---|---|---|---|---|
| `properties:all:raw` | `cache.properties_list` | 3600 s | `allCottages()` | **1** |
| `property:{id}:raw` | `cache.property_detail` | 3600 s | `cottageRaw()` | 6 (one per cottage) |
| `room:{id}:{roomId}:raw` | `cache.property_detail` | 3600 s | `cottageRaw()` | 6 |
| `avail:{id}:{start}:{end}` | `cache.availability` | 300 s | `cottageAvailability()` | 6 × distinct date ranges ⚠ |
| `avail:aggregate:{start}:raw` | `cache.availability` | 300 s | `aggregateAvailability()` | 1 per requested start date ⚠ |
| `quote:v2:{id}:{arr}:{dep}:{a}:{c}:{p}[:{addons}]` | `cache.quote` | 60 s | `quote()` | **unbounded** ⚠⚠ |
| `ratesraw:{id}:{start}:{end}` | `cache.availability` | 300 s | `rateCalendarRaw()` | 6 × distinct ranges ⚠ |
| `seasons:{id}` | `cache.rate_settings` | 3600 s | `seasons()` | 6 |
| `addons:v2:{id}` | `cache.property_detail` | 3600 s | `addonsFromApi()` | 6 |

### `PropertyImageResolver` (`CACHE_VERSION = v2`, tag `lodgify`)

| Logical key | TTL | Default | Cardinality |
|---|---|---|---|
| `images:{propertyId}:{strategyFingerprint}` | `cache.images` | 21600 s | 6 × strategy configurations |

### `ReservationRepository` (`CACHE_VERSION = v1`, **untagged**)

| Logical key | TTL | Default | Cardinality |
|---|---|---|---|
| `reservations:all` | `lodgify.reservations` | 300 s | **1** |
| `reservation:{id}` | `lodgify.reservations` | 300 s | one per viewed booking |

### `GoogleReviewsService` (no version, **untagged**)

| Logical key | TTL | Default |
|---|---|---|
| `google:reviews:{placeId}` | `services.google.cache_ttl` | 21600 s |
| per-photo resolved URI | `services.google.cache_ttl` | 21600 s |

### Cardinality is the problem

Four key families embed **request-controlled values** in the key:

| Key family | Variable part | Bound |
|---|---|---|
| `quote:v2:…` | arrival, departure, adults, children, pets, addon ids | **none** |
| `avail:{id}:{start}:{end}` | date range | none |
| `ratesraw:{id}:{start}:{end}` | date range | none |
| `avail:aggregate:{start}:raw` | start date | none |

`RateController::quote()` at least validates `date_format:Y-m-d`, capping the shape of
the values — but the *number* of distinct (arrival, departure, adults, children, pets)
tuples is still combinatorial, and each one writes a row into the `cache` table.

`CottageController::show()` does **not** validate at all (see
[`03-security.md` §F6](03-security.md)), so arbitrary strings reach the `quote:` key.
Against the `database` cache driver, that is an unauthenticated `INSERT` per request into
a table with no eviction beyond expiry — a cheap table-bloat vector, on a route that
carries no `throttle` middleware.

**Fix:** validate, then hash. See [§4.1](#41-hash-the-variable-part-of-every-cache-key).

## 1.4 HTTP-level caching

| Endpoint | Header set |
|---|---|
| `GET /api/availability/month` | `Cache-Control: public, max-age=60` ⚠ |
| `GET /api/cottage/{slug}/rates` | `Cache-Control: public, max-age=60` ⚠ |
| `GET /api/cottage/{slug}/addons` | `Cache-Control: public, max-age=300` ⚠ |
| `GET /api/cottage/{slug}/quote` | `Cache-Control: private, max-age=30` ✅ |
| `GET /admin/photos/{guestPhoto}/file` | `Cache-Control: private, max-age=300` ✅ |

⚠ The three `public` responses are emitted from routes inside the `web` middleware group
and therefore carry a session cookie. This is a **security** finding, not a performance
one — see [`03-security.md` §F1](03-security.md). Fixing it properly (moving `/api/*` out
of the session group) *also* unlocks the performance win, because then a CDN can cache
them for real.

No `ETag` or `Last-Modified` is emitted anywhere, so a browser revalidation after
`max-age` always transfers the full body.

## 1.5 Client-side caching

Both Alpine components memoise fetched months in a component-local object:

```js
// booking-search.js and cottage-calendar.js
cache: {},      // keyed by 'YYYY-MM'
```

So paging back and forth in a date picker issues each month at most once per page view.
This is why the `120,1` throttle on the rates endpoint is not restrictive in practice.

## 1.6 Frontend asset caching

Vite (`vite.config.js`) emits content-hashed filenames into `public/build`, referenced via
`@vite([...])`, so assets are immutably cacheable. Fonts are loaded two ways:

- `layouts/website.blade.php` links Google Fonts directly (Fraunces, Plus Jakarta Sans,
  Space Mono) with `preconnect` hints to `fonts.googleapis.com` and `fonts.gstatic.com`.
- `vite.config.js` *also* configures `bunny('Instrument Sans', …)` via
  `laravel-vite-plugin/fonts`.

That is **two font pipelines**, and `Instrument Sans` does not appear in the layout — so
the Bunny configuration is either unused or serving a font nothing references. Worth
resolving: the direct Google Fonts link is a third-party render-blocking request on every
page, and self-hosting through the Vite font plugin would remove it (and the
`Referrer-Policy` leak noted in [`03-security.md` §F2](03-security.md)).

---

# Part 2 — Database as implemented

## 2.1 Schema overview

Nine application tables plus seven framework tables.

### Framework tables

| Table | Migration | Purpose |
|---|---|---|
| `users` | `0001_01_01_000000` + `2026_08_16_183151` | accounts; `email` unique; `is_admin`, `last_login_at`, `last_login_ip` added later |
| `password_reset_tokens` | `0001_01_01_000000` | `email` primary key |
| `sessions` | `0001_01_01_000000` | `id` PK, `user_id` indexed, `last_activity` indexed |
| `cache` / `cache_locks` | `0001_01_01_000001` | `key` PK, `expiration` indexed |
| `jobs` / `job_batches` / `failed_jobs` | `0001_01_01_000002` | queue (currently unused) |

### Application tables

#### `business_stay_requests` — corporate enquiries
34 columns across company / contact / stay / commercial / workflow / provenance blocks.
Soft deletes.

| Index | Columns |
|---|---|
| unique | `reference` |
| index | `status` |
| composite | `status, created_at` |
| index | `check_in` |
| index | `email` |
| FK | `handled_by → users.id` `nullOnDelete` |

Schema-design note worth preserving: `dates_flexible` + `flexible_note` are stored
*separately* from `check_in`/`check_out` because corporate enquiries frequently arrive
before dates are fixed ("some week in October"). This keeps the date columns honest
instead of filled with placeholders.

#### `contact_messages`
| Index | Columns |
|---|---|
| unique | `reference` |
| index | `status` |
| composite | `status, created_at` |
| index | `email` |
| FK | `handled_by → users.id` `nullOnDelete` |

#### `guest_photos`
| Index | Columns |
|---|---|
| unique | `uuid` |
| index | `status` |
| composite | `status, created_at` |
| composite | `status, sort_order` |
| FK | `reviewed_by → users.id` `nullOnDelete` |

The `disk`/`path` pair encodes the moderation lifecycle: `pending → local` (private,
served only through an authenticated admin route), `approved → public` (web-reachable).

#### `checkout_intents`
| Index | Columns |
|---|---|
| unique | `reference` |
| index | `status` |
| index | `lodgify_booking_id` |
| index | `session_id` |
| composite | `cottage_id, arrival` |
| composite | `status, created_at` |

`addons` is a `json` column; `redirect_url` is `text` (the exact URL sent to Lodgify —
*"invaluable when debugging a checkout that behaved unexpectedly"*).

#### `cottage_availability_days` — **created but never used**
| Index | Columns |
|---|---|
| unique | `property_id, date` |
| index | `date` |

No model, no reader, no writer. See [`01-architecture.md` D2](01-architecture.md). The
unique constraint on `[property_id, date]` is exactly what an `upsert()`-based sync needs,
which is the strongest hint about the original intent — and the basis of the Part 5
recommendation.

## 2.2 Index review

**The indexes are well chosen.** Every one maps to a query the code actually issues:

| Index | Serving query |
|---|---|
| `business_stay_requests.status, created_at` | `Admin\BusinessStayRequestController::index` — `->status(…)->orderBy('created_at', …)` |
| `business_stay_requests.check_in` | the `sort=check_in` branch of the same allowlist |
| `contact_messages.status, created_at` | `Admin\ContactMessageController::index` — `->status(…)->latest()` |
| `guest_photos.status, created_at` | `Admin\GuestPhotoController::index` — `->status(…)->latest()` |
| `guest_photos.status, sort_order` | `GuestPhoto::scopeGalleryOrder()` on the public gallery |
| `checkout_intents.status, created_at` | `Admin\CheckoutIntentController::index` — `->status(…)->latest()` |
| `*.email` | free-text search fall-through, and enquiry lookup by address |
| `sessions.last_activity` | session garbage collection |
| `cache.expiration` | cache pruning |

### Gaps

**(a) `guest_photos` has no index for the public gallery's filtered path.**

```php
// GalleryController::index()
GuestPhoto::approved()
    ->when($request->integer('cottage'), fn ($q,$id) => $q->where('cottage_id',$id))
    ->galleryOrder()          // is_featured DESC, sort_order ASC, created_at DESC
    ->paginate(24);
```

There is no index covering `(status, cottage_id, is_featured, sort_order)`. `status,
sort_order` exists but does not include `cottage_id` or `is_featured`, so the filtered
gallery scans and sorts. Irrelevant at a few hundred photos; add it before it grows:

```php
Schema::table('guest_photos', function (Blueprint $table) {
    $table->index(['status', 'cottage_id', 'is_featured', 'sort_order'],
                  'guest_photos_gallery_idx');
});
```

The `GROUP BY` for the filter chips has the same shape and benefits from the same index:

```php
GuestPhoto::approved()->whereNotNull('cottage_id')
    ->selectRaw('cottage_id, cottage_name, count(*) as total')
    ->groupBy('cottage_id','cottage_name')->orderByDesc('total')->get();
```

**(b) `LIKE '%term%'` search cannot use any index.** Three `scopeSearch()`
implementations use leading-wildcard `LIKE` across up to six columns:

```php
// BusinessStayRequest::scopeSearch — reference, company_name, contact_name, email, phone
// ContactMessage::scopeSearch    — reference, name, email, phone, subject, message
// Admin\GuestPhotoController     — guest_name, guest_email, caption
```

These are full scans by construction. Fine at current volume (tens to hundreds of rows);
if the enquiry tables reach five figures, move to FTS5 (SQLite) or a `FULLTEXT` index
(MySQL). `ContactMessage`'s inclusion of the `message` TEXT column makes it the first to
hurt.

**(c) Soft-deleted rows are not excluded from any index.** All four soft-deleting models
add `deleted_at IS NULL` to every query, but no index includes `deleted_at`. As archived
rows accumulate this degrades the composite indexes. For MySQL/Postgres, partial indexes
solve it cleanly; on SQLite, prepend `deleted_at` to the hottest composites.

## 2.3 N+1 and query-count review

**The admin controllers are clean.** `show()` methods eager-load:

```php
$businessStayRequest->load('handler')   // Admin\BusinessStayRequestController::show
$contactMessage->load('handler')        // Admin\ContactMessageController::show
```

Status counts are a single aggregate rather than a count-per-status loop:

```php
$counts = BusinessStayRequest::query()
    ->selectRaw('status, count(*) as aggregate')
    ->groupBy('status')->pluck('aggregate','status')->all();
$out = ['all' => array_sum($counts)];   // derived, not a second query
```

**Query count per admin index page:** 1 (paginate count) + 1 (paginate rows) + 1
(status counts) = **3**. That is close to optimal for a filtered, counted, paginated list.

**The one place to watch** is `admin/business-stays` and `admin/messages` list views: if
a future template renders `$row->handler->name` per row, that becomes an N+1, because
only `show()` eager-loads. Adding `->with('handler')` to the `index()` query chains is
cheap insurance.

**`Admin\ReservationController::index()` issues zero SQL** — everything comes from
`ReservationRepository`, which is HTTP + cache. It does, however, call the repository
three times per render (`search()`, `filterOptions()`, `stats()`), each of which calls
`all()`. All three hit the same cache entry, so it is one cache read amplified into three
full `Collection` map/filter passes over every reservation. See [§5.4](#54-collapse-the-triple-all-call-in-the-admin-reservations-page).

---

# Part 3 — Measured hot paths

Counted from the code, assuming 6 cottages and the default configuration.

## 3.1 Cold-cache upstream call counts

| Page | Cold upstream HTTP calls | Composition |
|---|---|---|
| `GET /` | **19** | 1 `listProperties` + 6 `getProperty` + 6 `getRoomInfo` + 6 `getAvailability` |
| `GET /cottages` | **19** | identical |
| `GET /cottage/{slug}` | **21+** | 13 catalogue + 1 availability + 1 rate-settings/seasons (+1 fallback) + 1 quote when dates present |
| `GET /availability` (no dates) | **19** | identical to `/cottages` |
| `GET /availability` (with dates) | **19** | tiers 2 and 3 reuse the same `avail:` entries, so no extra upstream calls after the first cottage loop |
| `GET /api/availability/month` | **19** | full catalogue hydration + 6 availability |
| `GET /api/cottage/{slug}/rates` | **15** | 13 catalogue (for the slug lookup) + 1 rates-calendar + 1 availability |
| `GET /api/cottage/{slug}/quote` | **16** | 13 catalogue + 1 rates-calendar (a *different* key from the month view) + 1 quote (+1 fallback) |
| `GET /reviews` | **1** | Google Places, then per-photo URI resolutions |
| `GET /gallery` | **0** | local only |

With `lodgify.timeout` at 15 s and `retries` at 2, a worst-case cold page load has a
theoretical ceiling in the minutes. In practice the observed cost is a slow first paint
after every cache expiry — but the *variance* is the real problem, because there is no
timeout budget for the request as a whole, only per call.

## 3.2 The three structural inefficiencies

### (a) Every slug lookup hydrates the entire catalogue

```php
public function cottageBySlug(string $slug): ?Cottage
{
    return $this->allCottages()->firstWhere('slug', $slug);
}
```

`allCottages()` maps **every** cottage — which means `cottageRaw()` for each, which is
2 HTTP calls per cottage on a cold cache, plus a `PropertyImageResolver::resolve()` call
per cottage (with its own cache lookups and, on a cold image cache, filesystem scans via
the `local` strategy).

Called by: `CottageController::show`, `RateController::month`, `RateController::quote`,
`RateController::addons`, `BookingRedirectController`. So **four of the five interactive
endpoints on the cottage detail page each hydrate all six cottages to find one.**

Even warm, this is ~13 cache reads and 6 DTO constructions (each running amenity
extraction, image normalisation, house-rule parsing) per request — to answer "which
cottage is `oceanview-cottage-836351`?"

### (b) Two different cache keys for the same rate data

```php
// RateController::month()   — month-aligned
$start = Carbon::parse($request->query('start'))->startOfMonth();
$end   = $start->copy()->addMonths($months)->endOfMonth();
// → "ratesraw:{id}:2026-09-01:2026-10-31"

// RateController::quote() → nightlySegments()  — stay-aligned
$this->lodgify->rateCalendar($cottage, $arrival, $departure);
// → "ratesraw:{id}:2026-09-07:2026-09-11"
```

The month key covers the stay range in full, but the keys do not match, so **every first
quote for a given stay is a cache miss and a fresh `/v2/rates/calendar` call** — even
though the page already loaded that exact data seconds earlier for the calendar grid.

### (c) `nearbyMatches()` is O(window × cottages) in-process work

```php
for ($offset = 1; $offset <= $window; $offset++) {          // default 14
    foreach ([-$offset, $offset] as $delta) {               // × 2 = 28 iterations
        foreach ($this->cottagesFreeFor($newArrival, $newDeparture) as $cottage) { … }
    }
}
```

`cottagesFreeFor()` calls `allCottages()` **and** `cottageAvailability()` per cottage.
The availability lookups all hit the same cache entries (the start date is normalised to
`min($arrival, today)`), so there is no upstream amplification — but there are
**28 × `allCottages()` calls**, each rebuilding six DTOs from cached arrays. That is
~168 DTO constructions plus ~364 cache reads for one search request.

## 3.3 Cache stampede risk

None of the three `remember` implementations uses a lock. Under concurrency, N simultaneous
requests for an expired key all execute the callback:

| Key | TTL | Blast radius on expiry |
|---|---|---|
| `avail:aggregate:{start}:raw` | 300 s | N × 19 upstream calls — hit by the search widget on the home page |
| `properties:all:raw` | 3600 s | N × 1, but each miss cascades into the per-cottage hydration |
| `reservations:all` | 300 s | N × up to 40 paged `listBookings` calls |

`reservations:all` is the worst: `ReservationRepository::all()` pages until a short batch
arrives, with a hard stop at page 40. Two admins loading `/admin/reservations` the moment
the TTL lapses can issue 80 upstream requests against a paid API.

`Cache::lock()` (backed by the existing `cache_locks` table) is the direct fix — see
[§4.3](#43-add-stampede-protection).

---

# Part 4 — Caching improvements

## 4.1 Hash the variable part of every cache key

**Problem:** four key families embed unbounded request input (§1.3).

```php
// LodgifyRepository — apply the same pattern to avail:, ratesraw: and quote:
protected function scopedKey(string $prefix, array $parts): string
{
    return $prefix . ':' . substr(hash('xxh128', implode('|', $parts)), 0, 24);
}

public function quote(int $cottageId, string $arrival, string $departure,
                      int $adults = 2, int $children = 0, int $pets = 0,
                      array $addOnIds = []): ?array
{
    $key = $this->scopedKey("quote:v2:{$cottageId}", [
        $arrival, $departure, $adults, $children, $pets, implode(',', $addOnIds),
    ]);
    …
}
```

Gains: bounded key length, no possibility of key-namespace injection from a crafted
value, and uniform key sizes (which matters for the `cache` table's `key` primary key).

Pair it with validation on `CottageController::show()` and a `throttle` on
`/cottage/{slug}` — see [`03-security.md` §F6](03-security.md). The hash is defence in
depth; validation is the actual fix.

## 4.2 Scope `flushCache()` on non-tagging drivers

```php
public function flushCache(): void
{
    $tag    = (string) config('lodgify.cache_tag', 'lodgify');
    $driver = config('cache.default');
    if (in_array($driver, ['redis','memcached'], true)) {
        Cache::tags([$tag])->flush();
        return;
    }
    Cache::flush();                    // ← nukes sessions, rate limiters, everything
}
```

On the default `database` driver this truncates the entire `cache` table: every
`RateLimiter` counter, every password-reset throttle, every unrelated cached value. One
`GET /debug/lodgify/flush` resets every rate limit in the application (see
[`03-security.md` §F8](03-security.md)).

**Fix A — prefix-scoped delete for the database driver:**

```php
public function flushCache(): void
{
    $tag    = (string) config('lodgify.cache_tag', 'lodgify');
    $driver = config('cache.default');

    if (in_array($driver, ['redis','memcached'], true)) {
        Cache::tags([$tag])->flush();
        return;
    }

    if ($driver === 'database') {
        $prefix = (string) config('cache.prefix');
        DB::connection(config('cache.stores.database.connection'))
            ->table(config('cache.stores.database.table', 'cache'))
            ->where('key', 'like', $prefix.$tag.':%')
            ->delete();
        return;
    }

    Log::warning("flushCache(): driver [{$driver}] cannot be scoped; skipping global flush.");
}
```

**Fix B (preferred) — switch to Redis.** The tagged branch already exists in both
`rememberArray()` and `PropertyImageResolver::cacheStore()`; it has simply never been
reachable. One env change makes scoped invalidation work with no code change at all.

While you are there, bring `ReservationRepository` under the same tag so
`flushCache()` means the same thing on every driver:

```php
// ReservationRepository — currently writes untagged, unprefixed keys
protected function store()
{
    $tag = (string) config('lodgify.cache_tag', 'lodgify');
    return in_array(config('cache.default'), ['redis','memcached'], true)
        ? Cache::tags([$tag])
        : Cache::store();
}
```

## 4.3 Add stampede protection

```php
protected function rememberArray(string $key, int $ttl, \Closure $callback): mixed
{
    // … existing key/store resolution …

    if ($cached = $store->get($effectiveKey)) {
        return $cached;
    }

    // Only one process recomputes; the rest wait up to 10s, then get a fresh
    // computation of their own rather than an error. `cache_locks` already exists.
    return Cache::lock("lock:{$effectiveKey}", 15)->block(10, function () use (…) {
        return $store->remember($effectiveKey, $ttl, /* … guarded callback … */);
    });
}
```

Apply to the three high-blast-radius keys at minimum:
`avail:aggregate:{start}:raw`, `properties:all:raw`, `reservations:all`.

Better still for user-facing latency: **stale-while-revalidate.** Serve the expired value
immediately and refresh in the background.

```php
protected function rememberFresh(string $key, int $ttl, int $staleFor, \Closure $cb): mixed
{
    $entry = $store->get($key);           // ['value' => …, 'fresh_until' => ts]

    if ($entry && $entry['fresh_until'] > time()) {
        return $entry['value'];                        // fresh
    }

    if ($entry) {
        // Stale but usable — return now, refresh out of band.
        RefreshCacheEntry::dispatch($key, $ttl, $staleFor);
        return $entry['value'];
    }

    return $this->computeAndStore($key, $ttl, $staleFor, $cb);   // cold: must block
}
```

For availability this is close to free correctness-wise — a 60-second-stale calendar is
already what a 300 s TTL delivers — and it removes the cliff entirely.

## 4.4 Unify the rate-calendar cache key

Fixes §3.2(b): make `nightlySegments()` reuse the month-aligned entry.

```php
// LodgifyRepository — normalise every rate-calendar request to month boundaries
protected function rateCalendarRaw(Cottage $cottage, string $startDate, string $endDate): array
{
    // Snap outward to whole months so a stay range and a calendar range that
    // cover the same days share one cache entry.
    $from = Carbon::parse($startDate)->startOfMonth()->toDateString();
    $to   = Carbon::parse($endDate)->endOfMonth()->toDateString();

    $key = "ratesraw:{$cottage->id}:{$from}:{$to}";
    // … unchanged …
}
```

Callers keep passing exact dates; the *cache* works in months. A quote for
7–11 September now reads the same entry the calendar grid already populated. Cost: a
slightly wider upstream range per fetch, which is strictly cheaper than a second round
trip. Apply the same snapping to `avail:{id}:{start}:{end}`.

## 4.5 Cache the slug→id map

Fixes §3.2(a) without restructuring anything.

```php
public function cottageBySlug(string $slug): ?Cottage
{
    $map = $this->rememberArray(
        'slug:map',
        (int) config('lodgify.cache.properties_list'),
        fn () => $this->allCottages()->mapWithKeys(fn (Cottage $c) => [$c->slug => $c->id])->all()
    );

    $id = $map[$slug] ?? null;

    return $id ? $this->cottage($id) : null;
}
```

Warm-cache cost drops from **~13 cache reads + 6 DTO constructions** to **2 cache reads +
1 DTO construction**. Since four of the five cottage-page endpoints call this, it is the
single cheapest broad win available. Note `LodgifyRepository::uniqueSlug()` appends the
property id, so slugs are stable across renames of a *different* cottage — the map only
needs rebuilding when a cottage is renamed.

## 4.6 Add a request-scoped memo layer

`allCottages()` is called repeatedly within a single request (28× in
`nearbyMatches()`, 3× in an `/availability` search with dates). Each call re-reads the
cache and rebuilds all six DTOs.

```php
/** @var array<string, mixed> request-lifetime memo, on top of the shared cache */
private array $memo = [];

public function allCottages(): Collection
{
    return $this->memo['allCottages'] ??= $this->buildAllCottages();
}
```

Safe because `LodgifyRepository` is a **singleton** and each HTTP request gets a fresh
container. Adding `$this->memo = []` to `flushCache()` keeps the debug route honest.

Expected effect on `/availability?arrival=…&departure=…`: from ~168 DTO constructions and
~364 cache reads down to 6 and ~13.

## 4.7 Add `ETag`/`304` to the JSON endpoints

```php
public function month(Request $request): JsonResponse
{
    // … build $payload …
    $etag = '"'.substr(hash('xxh128', json_encode($payload)), 0, 16).'"';

    if ($request->headers->get('If-None-Match') === $etag) {
        return response()->json(null, 304)->setEtag($etag);
    }

    return response()->json($payload)
        ->setEtag($etag)
        ->header('Cache-Control', 'private, max-age=60');
}
```

Availability changes rarely within a browsing session, so most revalidations become
empty 304s instead of full month payloads.

---

# Part 5 — Performance improvements

## 5.1 Move off the single SQLite file

Today `DB_CONNECTION=sqlite`, `CACHE_STORE=database`, `SESSION_DRIVER=database` and
`QUEUE_CONNECTION=database` all point at one file. Every cache write, every session
touch, every queue poll and every application query serialise behind one write lock.

Concretely: a visitor loading `/cottage/some-cottage?arrival=…&departure=…` writes a
session row **and** up to four cache rows (`quote:`, `ratesraw:`, `avail:`, image) in one
request. Under even light concurrency that is a lock convoy, and it surfaces as
`SQLITE_BUSY` — note that `config/database.php` leaves `busy_timeout`, `journal_mode` and
`synchronous` all `null`, so SQLite runs with defaults (rollback journal, no busy wait).

**Minimum viable fix if SQLite must be retained:**

```php
// config/database.php — sqlite connection
'busy_timeout' => 5000,      // wait 5s instead of failing immediately
'journal_mode' => 'WAL',     // readers no longer block the writer
'synchronous'  => 'NORMAL',
```

WAL alone removes most read/write contention and is the highest-value one-line change in
this document for a SQLite deployment.

**Recommended fix:** Redis for cache + session + queue, MySQL/Postgres for data. That
takes three of the four write streams off the database entirely.

## 5.2 Fix the slug lookup

See [§4.5](#45-cache-the-slugid-map). Highest impact per line of code in the whole document.

## 5.3 Parallelise the per-cottage fan-out

`allCottages()` and `aggregateAvailability()` both loop sequentially over cottages, one
blocking HTTP call at a time. Laravel's HTTP client pools requests:

```php
// LodgifyClient — add a pooled availability fetch
public function getAvailabilityForMany(array $propertyIds, string $start, string $end): array
{
    $responses = Http::pool(fn (Pool $pool) => collect($propertyIds)
        ->map(fn ($id) => $pool->as((string) $id)
            ->withHeaders(['X-ApiKey' => $this->apiKey, 'Accept' => 'application/json'])
            ->timeout($this->timeout)
            ->get("{$this->baseUrl}/v2/availability/{$id}", [
                'start' => $start, 'end' => $end,
            ]))
        ->all());

    // Each entry still needs normaliseAvailabilityPeriods() applied, exactly as
    // the single-property getAvailability() does — the pooled call only replaces
    // the transport, not the mapping.
    return collect($responses)
        ->map(fn ($r, $id) => $r instanceof Response && $r->successful()
            ? $this->normaliseAvailabilityPeriods($r->json() ?? [], $start, $end)
            : null)
        ->all();
}
```

Cold-cache availability fan-out goes from **6 sequential** round trips to **1 parallel
batch** — roughly 6× faster on that leg. Same treatment for the `getProperty` and
`getRoomInfo` loops takes the 19-call cold page from ~19 sequential latencies to ~3
batches.

⚠ Preserve the failure-isolation contract: `Http::pool()` returns
`ConnectionException` instances in place of failed responses, so map each entry
individually and route failures through the same `$lastErrors` accumulator `safe()` uses.
One failing cottage must still degrade only itself.

**This is a good interim step, but §5.6 makes it unnecessary** — a synced read model has
no fan-out to parallelise.

## 5.4 Collapse the triple `all()` call in the admin reservations page

```php
// Admin\ReservationController::index()
$results = $this->reservations->search($filters);      // → all()
'options' => $this->reservations->filterOptions(),     // → all()
'stats'   => $this->reservations->stats(),             // → all()
```

Three full `Collection` passes over every reservation. Memoise inside the repository:

```php
private ?Collection $memo = null;

public function all(bool $fresh = false): Collection
{
    if ($fresh) { $this->memo = null; Cache::forget(…); }
    return $this->memo ??= $this->build();
}
```

Note `ReservationRepository` is **not** registered as a singleton
(see [`01-architecture.md` §1.4](01-architecture.md)), so each controller gets its own
instance — but within one controller the three calls share it, which is what matters here.
Registering it as a singleton in `LodgifyServiceProvider` alongside the others is worth
doing anyway for consistency.

Also fix `flush()` to clear per-reservation keys, not just the list, so
`POST /admin/reservations/refresh` actually refreshes the detail pages too.

## 5.5 Bound `nearbyMatches()`

```php
public function nearbyMatches(string $arrival, string $departure, int $window = 14): Collection
{
    $nights = Carbon::parse($arrival)->diffInDays(Carbon::parse($departure));
    if ($nights < 1) return collect();

    $cottages = $this->allCottages();          // hoisted OUT of the loop
    $matches  = collect();

    for ($offset = 1; $offset <= $window; $offset++) {
        foreach ([-$offset, $offset] as $delta) {
            $newArrival = Carbon::parse($arrival)->addDays($delta);
            if ($newArrival->isPast()) continue;

            foreach ($cottages as $cottage) {
                if ($matches->has($cottage->id)) continue;      // ← already matched: skip
                if ($this->isFreeFor($cottage, $newArrival, $newArrival->copy()->addDays($nights))) {
                    $matches->put($cottage->id, [
                        'cottage' => $cottage, 'arrival' => $newArrival->toDateString(),
                        'departure' => $newArrival->copy()->addDays($nights)->toDateString(),
                        'offset_days' => abs($delta),
                    ]);
                }
            }

            // Every cottage has its best (closest) offset — nothing further to find.
            if ($matches->count() === $cottages->count()) {
                return $matches->sortBy('offset_days')->values();
            }
        }
    }

    return $matches->sortBy('offset_days')->values();
}
```

Three changes, all behaviour-preserving:

- `allCottages()` hoisted out of the loop (28 calls → 1);
- because the loop already walks offsets in ascending order, the **first** match for a
  cottage is its closest — so `$matches->has()` short-circuits instead of collecting
  duplicates and de-duplicating at the end via `groupBy`;
- early return once every cottage has a match.

Worst case drops from ~168 DTO rebuilds to 6, and the typical case exits early.

## 5.6 The structural fix — sync availability and rates locally

This is the change that makes everything above mostly moot, and the table for the first
half of it **already exists** (`cottage_availability_days` — see
[`01-architecture.md` D2](01-architecture.md)).

```php
// app/Console/Commands/SyncCottageAvailability.php
// NOTE: routes/console.php already schedules this class every 30 minutes with
// ->withoutOverlapping()->onOneServer(). The class does not exist yet, which
// currently breaks every artisan invocation — see 01-architecture.md D1.
class SyncCottageAvailability extends Command
{
    protected $signature = 'lodgify:sync-availability';

    public function handle(LodgifyRepository $lodgify): int
    {
        foreach ($lodgify->allCottages() as $cottage) {
            SyncCottageAvailabilityJob::dispatch($cottage->id);
        }
        return self::SUCCESS;
    }
}
```

```php
// app/Jobs/SyncCottageAvailabilityJob.php
public function handle(LodgifyClient $client): void
{
    $from = today()->toDateString();
    $to   = today()->addDays((int) config('lodgify.availability_window_days', 90))->toDateString();

    $rows = $client->getAvailability($this->propertyId, $from, $to);

    // NEVER partial-write: an empty upstream response must not blank the calendar.
    // This is the same principle as `$succeeded` in aggregateAvailability() —
    // missing data is "unknown", not "booked".
    if ($rows === []) {
        Log::warning('Availability sync returned nothing; keeping previous rows', [
            'property' => $this->propertyId,
        ]);
        return;
    }

    DB::transaction(fn () => CottageAvailabilityDay::upsert(
        collect($rows)->map(fn ($d) => [
            'property_id'            => $this->propertyId,
            'date'                   => $d['date'],
            'is_available'           => (bool) ($d['isAvailable'] ?? false),
            'minimal_stay'           => (int) ($d['minimalStay'] ?? 1),
            'is_check_in_available'  => (bool) ($d['isCheckInAvailable'] ?? true),
            'is_check_out_available' => (bool) ($d['isCheckOutAvailable'] ?? true),
            'synced_at'              => now(),
        ])->all(),
        ['property_id', 'date'],              // the unique index already exists
        ['is_available','minimal_stay','is_check_in_available',
         'is_check_out_available','synced_at']
    ));
}
```

Then the aggregate becomes one indexed query:

```php
public function aggregateAvailability(string $start): Collection
{
    $end = Carbon::parse($start)->addDays(config('lodgify.availability_window_days', 90));

    return CottageAvailabilityDay::query()
        ->whereBetween('date', [$start, $end->toDateString()])
        ->selectRaw('date,
                     count(*)                            as total,
                     sum(is_available)                   as available_count,
                     min(nullif(minimal_stay, 0))        as min_stay,
                     max(is_check_in_available)          as ci,
                     max(is_check_out_available)          as co')
        ->groupBy('date')
        ->get()
        ->mapWithKeys(fn ($r) => [$r->date => new AvailabilityDay(
            date: $r->date, totalCottages: (int) $r->total,
            availableCount: (int) $r->available_count,
            minStay: (int) ($r->min_stay ?: 1),
            checkInAllowed: (bool) $r->ci, checkOutAllowed: (bool) $r->co,
        )]);
}
```

### Before and after

| Metric | Today | After |
|---|---|---|
| `/api/availability/month` cold | up to 19 HTTP calls, seconds | 1 indexed `GROUP BY`, single-digit ms |
| `/api/availability/month` warm | 1 cache read + 6 DTO builds | 1 indexed `GROUP BY` |
| Lodgify calls/hour | proportional to traffic ÷ hit rate | **constant** (2 syncs × 6 cottages = 12) |
| Stampede risk | real | none |
| Timeout path | present on every request | none on the read path |
| Staleness | implicit in a TTL | explicit in `synced_at`, observable and alertable |
| `degraded` flag | a per-request accident | a real health signal from `max(synced_at)` |

Add the same for rates (`cottage_rate_days`: `property_id`, `date`, `price`, `min_stay`,
`max_stay`, `season_name`, `currency`, `synced_at`, unique on `[property_id, date]`) and
`RateController::month()` plus `nightlySegments()` become local range scans too.

**Keep live and uncached:** the **quote**. `RateController::quote()` produces the number a
guest is about to pay, and the availability re-check in `BookingRedirectController` guards
a conversion. Both should be as fresh as possible — and once availability and rates are
local, both get the whole request budget to themselves.

## 5.7 Frontend

| Item | Detail |
|---|---|
| **Hero image** | `public/assets/images/hero.png` is a PNG served at full width on the home page. Convert to AVIF/WebP with a `<picture>` fallback — typically an 80–90% byte reduction on a photographic hero, and it is the largest-contentful-paint element. |
| **Resolve the two font pipelines** | `layouts/website.blade.php` links Google Fonts directly (3 families, render-blocking, third-party) while `vite.config.js` configures `bunny('Instrument Sans')` — a font the layout never references. Pick one; self-hosting through Vite removes a third-party round trip *and* the `Referrer-Policy` leak. |
| **Lodgify CDN presets** | `lodgify.image_size_param` is `null` by default, so grid thumbnails use whatever Lodgify sent (`f=32`, a thumbnail) while `image_size_large` is `1600` for the lightbox. Set an explicit grid preset — and verify it renders, because the CDN silently returns the original for unknown presets. |
| **`loading="lazy"` + explicit dimensions** | The gallery paginates 24 photos and `pages/cottage.blade.php` renders a full gallery. Lazy-loading below-fold images and setting width/height (to prevent layout shift) are both cheap. |
| **Defer the addons fetch** | `cottageCalendar.init()` fires `fetchAddons()` unconditionally on page load, but add-ons are only visible once a date range is selected. Move it into the date-selection handler to remove one request from every cottage-page load. |
| **`@vite` on admin pages** | `layouts/admin.blade.php` loads the full `app.js` bundle (Alpine + all three components incl. the 646-line calendar) for pages that only need a nav toggle. A separate `admin.js` entry point would cut the admin JS payload substantially. |

## 5.8 Observability

You cannot tune what you cannot see. In priority order:

1. **Cache hit/miss counters** per key family, so the TTLs can be set from evidence.
2. **Upstream latency histogram** per Lodgify endpoint — the 15 s timeout is a guess.
3. **`$lastErrors` rate as a metric.** `safe()` swallows failures by design, so a
   fully-degraded site currently looks healthy. This is the single most important signal
   the app is missing.
4. **`synced_at` freshness** once §5.6 lands, wired into `/up`.
5. **Slow-query log** on the enquiry tables, to catch the `LIKE '%…%'` scans becoming a
   problem before a human notices.

---

# Part 6 — Prioritised plan

## Tier 1 — do now (hours; large effect)

| # | Change | Effect |
|---|---|---|
| 1 | SQLite `journal_mode=WAL` + `busy_timeout=5000` (§5.1) | Removes most read/write lock contention. Two config lines. |
| 2 | Cache the slug→id map (§4.5) | 4 of 5 cottage-page endpoints stop hydrating all six cottages. |
| 3 | Request-scoped memo on `allCottages()` (§4.6) | Kills the 28× rebuild in `nearbyMatches()`. |
| 4 | `Cache-Control: public` → `private` on the three JSON endpoints (§1.4) | Closes a session-leak vector. Three lines. |
| 5 | Snap rate/availability cache keys to month boundaries (§4.4) | Removes a full upstream call from every first quote. |
| 6 | Scope `flushCache()` on the database driver (§4.2) | Stops one debug GET from resetting every rate limiter. |
| 7 | Hoist `allCottages()` and short-circuit in `nearbyMatches()` (§5.5) | ~168 → 6 DTO rebuilds per dated search. |

## Tier 2 — this sprint (days)

| # | Change | Effect |
|---|---|---|
| 8 | Redis for cache + session + queue (§4.2 Fix B, §5.1) | Tagged invalidation starts working with zero code change; three write streams leave the database. |
| 9 | Stampede locks on the three high-blast keys (§4.3) | Bounds cold-start amplification. |
| 10 | `Http::pool()` for the per-cottage fan-out (§5.3) | ~6× faster cold-cache availability leg. |
| 11 | Validate `CottageController::show()` + hash cache keys + throttle the route (§4.1) | Closes the cache-flooding vector. |
| 12 | Memoise `ReservationRepository::all()`; register it as a singleton (§5.4) | 3 collection passes → 1 on the admin reservations page. |
| 13 | Gallery composite index (§2.2a) | Removes a scan+sort from the filtered public gallery. |
| 14 | Hero image → AVIF/WebP; resolve the font pipelines (§5.7) | Largest single LCP win available. |

## Tier 3 — next (weeks; the structural change)

| # | Change | Effect |
|---|---|---|
| 15 | **Sync availability into `cottage_availability_days`** (§5.6) | Fixes D1 and D2 together. Availability reads become local. Lodgify volume becomes constant. |
| 16 | Add `cottage_rate_days` and sync rates (§5.6) | Rate reads become local; `nightlySegments()` becomes free. |
| 17 | Sync the property catalogue (`cottage_snapshots`) | Removes the last per-request fan-out; slug lookup becomes a single indexed row read. |
| 18 | MySQL/Postgres migration | Real concurrency; partial indexes for the soft-delete gap (§2.2c). |
| 19 | Observability: cache counters, upstream latency, `$lastErrors` rate, `synced_at` in `/up` (§5.8) | Makes every later decision evidence-based instead of guessed. |
| 20 | Stale-while-revalidate for anything still cached (§4.3) | Removes the TTL-expiry latency cliff. |

## Tier 4 — when volume justifies it

| # | Change | Trigger |
|---|---|---|
| 21 | FTS5 / `FULLTEXT` for enquiry search (§2.2b) | Enquiry tables pass ~10k rows |
| 22 | `ETag`/`304` on the JSON endpoints (§4.7) | Bandwidth becomes a cost |
| 23 | CDN in front of the `/api/*` routes | Only after F1 is fixed and the routes are stateless |
| 24 | Separate `admin.js` Vite entry point (§5.7) | Admin page weight becomes a complaint |
| 25 | Data-retention purge job | Regardless of volume — see [`03-security.md` §F13](03-security.md) |
