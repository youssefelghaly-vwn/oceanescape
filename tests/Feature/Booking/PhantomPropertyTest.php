<?php

namespace Tests\Feature\Booking;

use App\Services\Lodgify\LodgifyRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Properties that /v2/properties lists but /v2/properties/{id} 404s.
 *
 * Observed in production: Lodgify's list endpoint advertises ids that the detail endpoint
 * rejects with `"The property with identifier N does not exists"` (code 997) — deleted or
 * orphaned records still in the index.
 *
 * Three separate bugs came out of that, and these tests pin all three:
 *   1. safe() returned null, and Cache::remember treats a cached null as a miss, so the
 *      404 was re-fetched on EVERY request rather than once per TTL.
 *   2. A 404 was logged at error level, burying real faults.
 *   3. allCottages() fell back to the thin list entry, producing a cottage with no
 *      room id — visible on the site, impossible to price or book.
 */
class PhantomPropertyTest extends TestCase
{
    use RefreshDatabase;

    private const NOT_EXISTS = [
        'message' => 'The property with identifier 739349 does not exists',
        'code' => 997,
        'correlation_id' => '0HNNV1VJR46G0:00000003',
        'event_id' => null,
    ];

    /** A complete-enough detail payload for a property that does exist. */
    private function realProperty(int $id): array
    {
        return [
            'id' => $id,
            'name' => "Cottage {$id}",
            'currency_code' => 'CAD',
            'rooms' => [['id' => 800000 + $id % 1000, 'name' => 'Main', 'max_people' => 6]],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Real cache, so the negative-caching behaviour is actually exercised.
        config()->set('cache.default', 'array');
        config()->set('lodgify.merge_room_data', false);   // keep the fake surface small
    }

    #[Test]
    public function a_phantom_property_is_dropped_rather_than_half_rendered(): void
    {
        Http::fake([
            '*/v2/properties?*' => Http::response(['items' => [
                ['id' => 738423, 'name' => 'Sea Glass', 'image_url' => 'https://x/y.jpg'],
                ['id' => 739349, 'name' => 'Phantom',   'image_url' => 'https://x/z.jpg'],
            ]], 200),
            '*/v2/properties/738423' => Http::response($this->realProperty(738423), 200),
            '*/v2/properties/739349' => Http::response(self::NOT_EXISTS, 404),
        ]);

        $cottages = app(LodgifyRepository::class)->allCottages();

        // The good one survives; the phantom is gone entirely.
        $this->assertCount(1, $cottages);
        $this->assertSame(738423, $cottages->first()->id);
        $this->assertNotNull(
            $cottages->first()->primaryRoomId(),
            'a surviving cottage must be bookable'
        );
    }

    #[Test]
    public function the_404_is_cached_so_it_is_not_re_fetched_every_request(): void
    {
        /*
         * The log-noise bug. A cached null is indistinguishable from a miss, so returning
         * null from safe() meant asking Lodgify again on every single page load — the same
         * 404 appearing several times a second.
         */
        Http::fake([
            '*/v2/properties?*' => Http::response(['items' => [['id' => 739349, 'name' => 'Phantom']]], 200),
            '*/v2/properties/739349' => Http::response(self::NOT_EXISTS, 404),
        ]);

        $repo = app(LodgifyRepository::class);

        $repo->allCottages();
        $repo->allCottages();
        $repo->allCottages();

        // One list call, one detail call. Not three of each.
        Http::assertSentCount(2);
    }

    #[Test]
    public function it_reports_which_ids_are_phantom(): void
    {
        Http::fake([
            '*/v2/properties?*' => Http::response(['items' => [
                ['id' => 739349, 'name' => 'Phantom A'],
                ['id' => 739357, 'name' => 'Phantom B'],
            ]], 200),
            '*/v2/properties/739349' => Http::response(self::NOT_EXISTS, 404),
            '*/v2/properties/739357' => Http::response(self::NOT_EXISTS, 404),
        ]);

        $repo = app(LodgifyRepository::class);
        $repo->allCottages();

        $this->assertEqualsCanonicalizing([739349, 739357], $repo->phantomProperties());
    }

    #[Test]
    public function a_transient_failure_still_falls_back_to_the_list_entry(): void
    {
        /*
         * The distinction that matters. A 500 or a timeout is NOT "this does not exist", so
         * the old degraded-but-visible behaviour is still right there — better a cottage
         * with a cover image than no cottage while Lodgify is unwell.
         */
        Http::fake([
            '*/v2/properties?*' => Http::response(['items' => [
                ['id' => 738423, 'name' => 'Sea Glass', 'image_url' => 'https://x/y.jpg'],
            ]], 200),
            '*/v2/properties/738423' => Http::response(['message' => 'upstream boom'], 500),
        ]);

        $cottages = app(LodgifyRepository::class)->allCottages();

        $this->assertCount(1, $cottages, 'a 5xx must not remove the cottage');
        $this->assertSame('Sea Glass', $cottages->first()->name);
        $this->assertSame([], app(LodgifyRepository::class)->phantomProperties());
    }

    #[Test]
    public function a_transient_failure_is_not_negatively_cached(): void
    {
        // A 5xx must be retried, unlike a 404. Caching it would hide a recovery.
        Http::fake([
            '*/v2/properties?*' => Http::response(['items' => [['id' => 738423, 'name' => 'Sea Glass']]], 200),
            '*/v2/properties/738423' => Http::response(['message' => 'boom'], 500),
        ]);

        $repo = app(LodgifyRepository::class);
        $repo->allCottages();
        $repo->allCottages();

        /*
         * List cached once; the failing detail attempted on both calls AND retried each
         * time, because a 5xx IS worth retrying — 1 + (2 + 2) = 5. Contrast the 404 above,
         * which is not retried at all.
         */
        Http::assertSentCount(5);
    }

    #[Test]
    public function all_phantoms_yields_no_cottages_rather_than_broken_ones(): void
    {
        Http::fake([
            '*/v2/properties?*' => Http::response(['items' => [
                ['id' => 739349, 'name' => 'Phantom A'],
                ['id' => 739357, 'name' => 'Phantom B'],
            ]], 200),
            '*/v2/properties/*' => Http::response(self::NOT_EXISTS, 404),
        ]);

        $this->assertTrue(app(LodgifyRepository::class)->allCottages()->isEmpty());
    }

    #[Test]
    public function a_401_is_not_retried_either(): void
    {
        // A bad API key will not become a good one on the second attempt.
        Http::fake([
            '*/v2/properties?*' => Http::response(['message' => 'Unauthorized'], 401),
        ]);

        try {
            app(LodgifyRepository::class)->allCottages();
        } catch (\Throwable) {
            // listProperties() is not wrapped in safe(), so this surfaces. Not the point
            // of this test.
        }

        Http::assertSentCount(1);
    }

    #[Test]
    public function a_429_is_retried(): void
    {
        // Rate limiting is exactly what a backoff retry is for.
        Http::fake([
            '*/v2/properties?*' => Http::response(['items' => [['id' => 738423, 'name' => 'Sea Glass']]], 200),
            '*/v2/properties/738423' => Http::response(['message' => 'Too many requests'], 429),
        ]);

        app(LodgifyRepository::class)->allCottages();

        // 1 list + 2 attempts on the throttled detail.
        Http::assertSentCount(3);
    }
}
