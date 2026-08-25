<?php

namespace Tests\Feature\Admin;

use App\Models\Booking;
use App\Models\BookingAuditLog;
use App\Models\BookingPayment;
use App\Models\User;
use App\Services\Booking\BookingAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin audit screens.
 *
 * The trail contains every guest's email address, IP and the money they were charged, so the
 * access tests are not a formality: this is the most sensitive screen in the admin area, and
 * it is gated by `auth` then `admin` in that order — a signed-out visitor goes to login, a
 * signed-in non-admin gets a flat 403.
 */
class BookingAuditAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function bookingWithTrail(): Booking
    {
        $booking = Booking::factory()->awaitingDeposit()->create([
            'cottage_name' => 'Sea Glass Cottage',
            'guest_email' => 'alex@example.test',
        ]);

        $payment = BookingPayment::factory()->for($booking)->linkSent()->create();

        $auditor = app(BookingAuditor::class);
        $auditor->record('booking.created', $booking, context: ['total' => '900.00 CAD'], actorType: 'guest');
        $auditor->recordTransition($booking, 'booking.awaiting_deposit', 'pending_lodgify', 'awaiting_deposit');
        $auditor->record('payment.created', $booking, $payment, ['type' => 'deposit']);

        return $booking;
    }

    // ------------------------------------------------------------- access

    #[Test]
    public function a_signed_out_visitor_is_sent_to_login(): void
    {
        $this->get('/admin/audits')->assertRedirect(route('login'));
    }

    #[Test]
    public function a_signed_in_non_admin_is_refused(): void
    {
        // 403, not a redirect: they are authenticated, they are simply not allowed.
        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get('/admin/audits')
            ->assertForbidden();
    }

    #[Test]
    public function a_non_admin_cannot_read_one_bookings_trail_either(): void
    {
        // The per-booking page carries the same data, so it needs the same gate — an easy
        // thing to secure on the index and forget on the detail route.
        $booking = $this->bookingWithTrail();

        $this->actingAs(User::factory()->create(['is_admin' => false]))
            ->get(route('admin.audits.show', $booking))
            ->assertForbidden();
    }

    // -------------------------------------------------------------- index

    #[Test]
    public function the_index_lists_every_event_with_its_booking(): void
    {
        $booking = $this->bookingWithTrail();

        $this->actingAs($this->admin())
            ->get('/admin/audits')
            ->assertOk()
            ->assertSee('booking.created')
            ->assertSee('booking.awaiting_deposit')
            ->assertSee('payment.created')
            ->assertSee($booking->reference)
            ->assertSee('Sea Glass Cottage')
            // The status change is the point of a transition row.
            ->assertSee('pending_lodgify')
            ->assertSee('awaiting_deposit');
    }

    #[Test]
    public function the_index_is_reachable_from_the_admin_sidebar(): void
    {
        // Regression guard of the same shape as BookButtonTargetTest: a screen nobody can
        // navigate to is a screen nobody uses.
        $this->actingAs($this->admin())
            ->get(route('admin.messages.index'))
            ->assertOk()
            ->assertSee('Audit trail')
            ->assertSee(route('admin.audits.index'), escape: false);
    }

    #[Test]
    public function the_group_filter_narrows_to_one_prefix(): void
    {
        $this->bookingWithTrail();

        $this->actingAs($this->admin())
            ->get('/admin/audits?group=payment')
            ->assertOk()
            ->assertSee('payment.created')
            ->assertDontSee('booking.created');
    }

    #[Test]
    public function search_finds_a_trail_by_booking_reference_payment_reference_or_email(): void
    {
        $booking = $this->bookingWithTrail();
        $payment = $booking->payments()->firstOrFail();

        $other = Booking::factory()->create(['cottage_name' => 'Driftwood Cottage']);
        app(BookingAuditor::class)->record('booking.created', $other);

        $admin = $this->admin();

        foreach ([$booking->reference, $payment->reference, 'alex@example.test'] as $term) {
            $this->actingAs($admin)
                ->get('/admin/audits?q='.urlencode($term))
                ->assertOk()
                ->assertSee($booking->reference)
                ->assertDontSee($other->reference);
        }
    }

    #[Test]
    public function the_needs_a_human_filter_surfaces_only_the_events_that_do(): void
    {
        /*
         * The one filter that is a call to action. `payment.amount_mismatch` means money is
         * in Stripe for an amount we did not ask for and the booking was NOT confirmed —
         * it must never be buried among routine rows.
         */
        $booking = $this->bookingWithTrail();
        $payment = $booking->payments()->firstOrFail();

        app(BookingAuditor::class)->recordFailure('payment.amount_mismatch', $booking, $payment, [
            'requested_cents' => 22500,
            'captured_cents' => 100,
        ]);

        $this->actingAs($this->admin())
            ->get('/admin/audits?attention=1')
            ->assertOk()
            ->assertSee('payment.amount_mismatch')
            ->assertSee('needs a human')
            ->assertDontSee('payment.created');
    }

    #[Test]
    public function context_is_shown_but_secrets_never_are(): void
    {
        $booking = Booking::factory()->create();

        app(BookingAuditor::class)->record('payment.created', $booking, context: [
            'amount' => '225.00 CAD',
            'api_key' => 'sk_live_should_never_render',
            'card_number' => '4242424242424242',
        ]);

        $response = $this->actingAs($this->admin())->get('/admin/audits')->assertOk();

        // Useful detail renders; the scrubbed values are already `[redacted]` in the column,
        // so the screen cannot leak what was never stored.
        $response->assertSee('225.00 CAD')
            ->assertSee('[redacted]')
            ->assertDontSee('sk_live')
            ->assertDontSee('4242');
    }

    #[Test]
    public function the_screen_offers_no_way_to_change_anything(): void
    {
        /*
         * Audit rows reject updates and deletes at the model, so any form here would be an
         * affordance that cannot work. Asserted rather than trusted, because "add a delete
         * button" is a natural-seeming request.
         */
        $this->bookingWithTrail();

        $html = $this->actingAs($this->admin())->get('/admin/audits')->assertOk()->getContent();

        preg_match_all('/<form[^>]*>/i', $html, $matches);

        foreach ($matches[0] as $tag) {
            if (stripos($tag, 'method="POST"') === false) {
                continue;   // the GET filter form
            }

            // The layout's sign-out is the only POST form allowed on this page.
            $this->assertStringContainsString(route('logout'), $tag, "unexpected POST form: {$tag}");
        }

        // No PATCH/PUT/DELETE spoofing either.
        $this->assertStringNotContainsString('_method', $html);
    }

    // --------------------------------------------------------------- show

    #[Test]
    public function one_bookings_trail_reads_oldest_first(): void
    {
        $booking = $this->bookingWithTrail();

        $html = $this->actingAs($this->admin())
            ->get(route('admin.audits.show', $booking))
            ->assertOk()
            ->assertSee($booking->reference)
            ->assertSee('booking.created')
            ->assertSee('payment.created')
            ->getContent();

        /*
         * Order is the whole point of this page: it is what turns a pile of rows into an
         * explanation. Creation must appear before the payment it led to.
         */
        $this->assertLessThan(
            strpos($html, 'payment.created'),
            strpos($html, 'booking.created'),
            'the trail must read in the order events happened'
        );
    }

    #[Test]
    public function the_trail_page_shows_the_money_and_the_lodgify_link(): void
    {
        $booking = $this->bookingWithTrail();

        $this->actingAs($this->admin())
            ->get(route('admin.audits.show', $booking))
            ->assertOk()
            ->assertSee($booking->total()->format())
            ->assertSee($booking->lodgify_booking_id)
            // AwaitingDeposit does not hold the dates, and the page has to say so.
            ->assertSee('dates not held');
    }

    #[Test]
    public function a_trail_survives_its_booking_being_deleted(): void
    {
        /*
         * Both foreign keys are nullOnDelete precisely so the trail outlives what it
         * describes. The index must therefore render a row whose booking is gone rather
         * than blowing up on a null relation.
         */
        $booking = $this->bookingWithTrail();

        $booking->payments()->delete();
        $booking->forceDelete();

        $this->assertGreaterThan(0, BookingAuditLog::count());

        $this->actingAs($this->admin())
            ->get('/admin/audits')
            ->assertOk()
            ->assertSee('booking.created');
    }

    #[Test]
    public function an_unknown_booking_is_a_404_not_an_error(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/audits/999999')
            ->assertNotFound();
    }
}
