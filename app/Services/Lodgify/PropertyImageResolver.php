<?php

namespace App\Services\Lodgify;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Resolves a property's FULL photo gallery.
 *
 * WHY THIS EXISTS
 * Lodgify's Public API does not expose the photo gallery on any plan. The
 * property endpoint returns a single `image_url` (the cover) and nothing else,
 * so the gallery has to come from somewhere Lodgify does publish it, or from
 * assets you control.
 *
 * STRATEGIES, tried in order until one yields more than the cover:
 *
 *   1. manifest  — images you list yourself (config or JSON in storage).
 *                  Fully reliable, gives you ordering + alt text + WebP, and
 *                  survives any Lodgify API change. Recommended for production.
 *
 *   2. local     — files dropped in public/assets/cottages/{propertyId}/.
 *                  Zero config: filename order is display order.
 *
 *   3. scrape    — pulls the public Lodgify-hosted rental page and harvests the
 *                  gallery URLs from its markup. No API key needed and always
 *                  current, but it depends on Lodgify's page structure, so treat
 *                  it as convenience rather than infrastructure.
 *
 *   4. api       — whatever the API gave us (the cover). Always the last resort.
 *
 * Results are cached, so a scrape costs one request per property per TTL.
 */
class PropertyImageResolver
{
    /**
     * Bump when the resolution logic or strategy list changes, so an old
     * single-image result can't linger for the full 6h TTL.
     */
    protected const CACHE_VERSION = 'v2';

    public function __construct(protected LodgifyClient $client) {}

    /**
     * @param  string[] $apiImages images already extracted from the API payload
     * @return string[] absolute image URLs, cover first
     */
    public function resolve(int $propertyId, string $slug, array $apiImages = []): array
    {
        $ttl = (int) config('lodgify.cache.images', 21600); // 6h

        // Key includes the strategy list, so reordering strategies in .env takes
        // effect immediately instead of after the TTL expires.
        $fingerprint = substr(md5(implode(',', $this->strategies())), 0, 8);
        $cacheKey    = self::CACHE_VERSION . ":images:{$propertyId}:{$fingerprint}";

        $cached = $this->cacheStore()->get($cacheKey);
        if (is_array($cached) && !empty($cached['images'])) {
            $resolved = $cached;
        } else {
            $resolved = null;
            foreach ($this->strategies() as $strategy) {
                $images = match ($strategy) {
                    'api_v3'   => $this->fromV3Gallery($propertyId),
                    'manifest' => $this->fromManifest($propertyId),
                    'local'    => $this->fromLocalFiles($propertyId),
                    'scrape'   => $this->fromPublicPage($propertyId, $slug),
                    'api'      => $apiImages,
                    default    => [],
                };

                // Only accept a strategy that beats the single cover image.
                if (count($images) > 1) {
                    $resolved = ['source' => $strategy, 'images' => $images];
                    break;
                }
            }

            /*
             * DO NOT CACHE a single-image result.
             *
             * A caller working from a thin payload (the /v2/properties list,
             * which carries only `image_url`) would otherwise poison the cache
             * for six hours and starve a later caller that has the full gallery.
             * Only a real gallery is worth persisting.
             */
            if ($resolved !== null) {
                $this->cacheStore()->put($cacheKey, $resolved, $ttl);
            } else {
                $resolved = ['source' => 'api', 'images' => $apiImages];
            }
        }

        $images = $resolved['images'] ?? [];

        // Keep the API cover first if it isn't already in the list — it is the
        // image the owner explicitly chose in the dashboard.
        $cover = $apiImages[0] ?? null;
        if ($cover && !$this->containsSameAsset($images, $cover)) {
            array_unshift($images, $cover);
        }

        return array_values(array_unique($images));
    }

    /**
     * Tagged store when the driver supports it, so LodgifyRepository::flushCache()
     * clears resolved galleries along with everything else.
     */
    protected function cacheStore()
    {
        $tag    = (string) config('lodgify.cache_tag', 'lodgify');
        $driver = config('cache.default');
        return in_array($driver, ['redis', 'memcached'], true)
            ? Cache::tags([$tag])
            : Cache::store();
    }

    /** Which source won, for the debug route. */
    public function resolveWithSource(int $propertyId, string $slug, array $apiImages = []): array
    {
        $out = [];
        foreach ($this->strategies() as $strategy) {
            $images = match ($strategy) {
                'api_v3'   => $this->fromV3Gallery($propertyId),
                'manifest' => $this->fromManifest($propertyId),
                'local'    => $this->fromLocalFiles($propertyId),
                'scrape'   => $this->fromPublicPage($propertyId, $slug),
                'api'      => $apiImages,
                default    => [],
            };
            $out[$strategy] = ['count' => count($images), 'images' => array_slice($images, 0, 20)];
        }
        return $out;
    }

    /** @return string[] */
    protected function strategies(): array
    {
        $configured = config('lodgify.image_strategies', ['api_v3', 'manifest', 'local', 'scrape', 'api']);
        return is_array($configured) ? $configured : ['api_v3', 'manifest', 'local', 'scrape', 'api'];
    }

    // =========================================================================
    // 0. v3 gallery API  (property.lodgify.com/api/v3/property/{id}/images/all)
    // =========================================================================

    /**
     * The real gallery, from Lodgify's v3 images endpoint.
     *
     * Images are ordered by `orderNumber` so the sequence matches what the owner
     * arranged in the dashboard. Both property-level and room-type-level images
     * are included (a multi-room-type rental puts photos on the room types).
     *
     * @return string[]
     */
    protected function fromV3Gallery(int $propertyId): array
    {
        try {
            $payload = $this->client->getPropertyImages($propertyId);
        } catch (\Throwable $e) {
            Log::warning('Lodgify v3 gallery fetch failed', [
                'property' => $propertyId, 'message' => $e->getMessage(),
            ]);
            return [];
        }

        if (($payload['success'] ?? null) === false || empty($payload['data'])) {
            return [];
        }

        $data = $payload['data'];

        $buckets = [];
        $buckets[] = $data['property']['images'] ?? [];
        foreach ((array) ($data['roomTypes'] ?? []) as $roomType) {
            $buckets[] = $roomType['images'] ?? [];
        }

        $items = [];
        foreach ($buckets as $bucket) {
            foreach ((array) $bucket as $img) {
                if (is_array($img)) {
                    $items[] = $img;
                }
            }
        }

        // Respect the dashboard ordering.
        usort($items, fn ($a, $b) => ($a['orderNumber'] ?? 0) <=> ($b['orderNumber'] ?? 0));

        return collect($items)
            ->map(fn (array $img) => $this->v3ImageUrl($img))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Build a CDN URL for one v3 image record.
     *
     * Prefers an explicit URL field when the payload carries one. Otherwise
     * constructs from the asset `id`, which is the UUID used in the cover URL
     * Lodgify returns on the v2 property endpoint:
     *
     *   //l.icdbcdn.com/oh/223b2f6f-a526-464c-b522-aebda7c7e8e3.png?f=32
     */
    protected function v3ImageUrl(array $img): ?string
    {
        // explicit URL, whatever Lodgify calls it
        foreach (['url', 'imageUrl', 'image_url', 'originalUrl', 'src', 'path', 'fullUrl'] as $key) {
            if (!empty($img[$key]) && is_string($img[$key])) {
                return $this->applySize($this->absolutise($img[$key]));
            }
        }

        $id = $img['id'] ?? null;
        if (!is_string($id) || $id === '') {
            return null;
        }

        // extension, if the payload names the file
        $ext = null;
        foreach (['extension', 'fileExtension', 'format'] as $key) {
            if (!empty($img[$key]) && is_string($img[$key])) {
                $ext = ltrim($img[$key], '.');
                break;
            }
        }
        if ($ext === null) {
            foreach (['fileName', 'filename', 'name'] as $key) {
                if (!empty($img[$key]) && is_string($img[$key])) {
                    $guess = pathinfo($img[$key], PATHINFO_EXTENSION);
                    if ($guess !== '') {
                        $ext = $guess;
                    }
                    break;
                }
            }
        }
        $ext ??= (string) config('lodgify.image_default_extension', 'png');

        $base = rtrim((string) config('lodgify.image_cdn_base', 'https://l.icdbcdn.com/oh'), '/');

        return $this->applySize("{$base}/{$id}.{$ext}");
    }

    /** Apply the configured CDN size preset, if any. */
    protected function applySize(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }
        $size = config('lodgify.image_size_param');
        if ($size === null || $size === '' || !str_contains($url, 'icdbcdn')) {
            return $url;
        }
        if (preg_match('/[?&]f=/', $url)) {
            return preg_replace('/([?&])f=[^&]*/', '$1f=' . rawurlencode((string) $size), $url);
        }
        return $url . (str_contains($url, '?') ? '&' : '?') . 'f=' . rawurlencode((string) $size);
    }

    // =========================================================================
    // 1. Manifest
    // =========================================================================

    /**
     * Images listed in config/lodgify-images.php, or in a JSON file at
     * storage/app/lodgify-images.json (which lets a non-developer edit it).
     *
     * config/lodgify-images.php:
     *   return [
     *       836351 => [
     *           'assets/cottages/cottage1/living.jpg',
     *           'https://l.icdbcdn.com/oh/....png',
     *       ],
     *   ];
     *
     * @return string[]
     */
    protected function fromManifest(int $propertyId): array
    {
        $images = (array) (config("lodgify-images.{$propertyId}") ?? []);

        if ($images === []) {
            $path = (string) config('lodgify.image_manifest_path', 'lodgify-images.json');
            try {
                if (Storage::disk('local')->exists($path)) {
                    $json = json_decode((string) Storage::disk('local')->get($path), true);
                    $images = (array) ($json[$propertyId] ?? $json[(string) $propertyId] ?? []);
                }
            } catch (\Throwable $e) {
                Log::warning('Lodgify image manifest unreadable', ['message' => $e->getMessage()]);
            }
        }

        return collect($images)
            ->filter(fn ($i) => is_string($i) && trim($i) !== '')
            ->map(fn (string $i) => $this->absolutise($i))
            ->values()
            ->all();
    }

    // =========================================================================
    // 2. Local files
    // =========================================================================

    /**
     * Anything in public/assets/cottages/{propertyId}/. Filenames sort
     * naturally, so prefix with 01-, 02- to control gallery order.
     *
     * @return string[]
     */
    protected function fromLocalFiles(int $propertyId): array
    {
        $dir = public_path("assets/cottages/{$propertyId}");
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.{jpg,jpeg,png,webp,avif,JPG,JPEG,PNG,WEBP}', GLOB_BRACE) ?: [];
        natsort($files);

        return collect($files)
            ->map(fn (string $f) => asset("assets/cottages/{$propertyId}/" . basename($f)))
            ->values()
            ->all();
    }

    // =========================================================================
    // 3. Public page scrape
    // =========================================================================

    /**
     * Harvest gallery URLs from the public Lodgify-hosted rental page.
     *
     * The page embeds its images as icdbcdn URLs, both in <img> tags and in the
     * hydration payload, so a regex over the markup is enough — no DOM parser
     * or headless browser required.
     *
     * @return string[]
     */
    protected function fromPublicPage(int $propertyId, string $slug): array
    {
        $url = $this->publicPageUrl($propertyId, $slug);
        if ($url === null) {
            return [];
        }

        try {
            $response = $this->client->fetchPublicPage($url);
        } catch (\Throwable $e) {
            Log::warning('Lodgify public page fetch failed', ['url' => $url, 'message' => $e->getMessage()]);
            return [];
        }

        if ($response === null) {
            return [];
        }

        // icdbcdn asset URLs, with or without a scheme, plus optional query.
        preg_match_all(
            '#(?:https?:)?//[a-z0-9.\-]*icdbcdn\.com/[^\s"\'\\\\<>)]+?\.(?:jpe?g|png|webp|avif)(?:\?[^\s"\'\\\\<>)]*)?#i',
            $response,
            $matches
        );

        $urls = collect($matches[0] ?? [])
            ->map(fn (string $u) => str_replace('\\/', '/', $u))
            ->map(fn (string $u) => $this->absolutise($u))
            ->filter()
            // drop obvious non-gallery assets
            ->reject(fn (string $u) => (bool) preg_match('#/(logo|favicon|icon|avatar|placeholder)#i', $u));

        // Collapse size variants of the same asset: the CDN path before `?` is
        // a stable asset id, so keep one entry per id.
        $byAsset = [];
        foreach ($urls as $u) {
            $id = $this->assetId($u);
            $byAsset[$id] ??= $u;
        }

        return array_values($byAsset);
    }

    protected function publicPageUrl(int $propertyId, string $slug): ?string
    {
        // Per-property override wins, e.g. LODGIFY_PUBLIC_PAGE_836351
        $override = config("lodgify.public_page_overrides.{$propertyId}");
        if (is_string($override) && $override !== '') {
            return $override;
        }

        $base = rtrim((string) config('lodgify.public_site_origin', ''), '/');
        if ($base === '') {
            return null;
        }

        // Lodgify slugs on the public site have no id suffix, so strip ours.
        $publicSlug = preg_replace('/-' . $propertyId . '$/', '', $slug);
        $locale = (string) config('lodgify.public_site_locale', 'en');

        return "{$base}/{$locale}/{$publicSlug}";
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    protected function absolutise(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        if (str_starts_with($url, '//')) {
            return 'https:' . $url;
        }
        if (str_starts_with($url, 'http://')) {
            return 'https://' . substr($url, 7);
        }
        if (str_starts_with($url, 'https://')) {
            return $url;
        }
        // relative path -> our own public dir
        return asset(ltrim($url, '/'));
    }

    /** Stable identifier for a CDN asset, ignoring size/transform params. */
    protected function assetId(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        return strtolower(basename($path));
    }

    protected function containsSameAsset(array $images, string $candidate): bool
    {
        $id = $this->assetId($candidate);
        foreach ($images as $i) {
            if ($this->assetId($i) === $id) {
                return true;
            }
        }
        return false;
    }
}