<?php

namespace Tests\Feature\Booking;

use App\Models\Booking;
use App\Models\BookingPayment;
use App\Services\Payments\StripeGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * "No card saving" is a promise made to the guest in the booking page copy, so it is pinned
 * here rather than left to a comment.
 *
 * A returning guest who pays every season is exactly the case where someone would later
 * reach for a stored card "for convenience". Doing that would change what we are — a site
 * that never sees card data, PCI SAQ A — into something with a payment-method vault. The
 * test is what makes that a deliberate decision rather than a one-line addition.
 */
class NoCardSavingTest extends TestCase
{
    use RefreshDatabase;

    private function payment(): BookingPayment
    {
        return BookingPayment::factory()
            ->for(Booking::factory()->awaitingDeposit())
            ->create();
    }

    #[Test]
    public function the_checkout_session_never_asks_stripe_to_keep_the_card(): void
    {
        $payload = app(StripeGateway::class)->sessionPayload($this->payment());

        /*
         * Each of these is a way to make a card reusable. All four must be absent — and note
         * that `mode: payment` alone is not enough: setup_future_usage works in payment mode
         * precisely so that a one-off charge can still store the method.
         */
        $this->assertArrayNotHasKey('setup_future_usage', $payload);
        $this->assertArrayNotHasKey('customer', $payload);
        $this->assertArrayNotHasKey('customer_creation', $payload);
        $this->assertArrayNotHasKey('saved_payment_method_options', $payload);

        $this->assertArrayNotHasKey('setup_future_usage', $payload['payment_intent_data'] ?? []);

        $this->assertSame('payment', $payload['mode']);
    }

    #[Test]
    public function the_guest_email_is_a_prefill_and_not_a_stored_customer(): void
    {
        $payment = $this->payment();

        $payload = app(StripeGateway::class)->sessionPayload($payment);

        // customer_email prefills Stripe's form; it does not create a Customer object, and
        // is the ONLY guest detail the session carries.
        $this->assertSame($payment->booking->guest_email, $payload['customer_email']);
        $this->assertArrayNotHasKey('customer', $payload);
    }

    #[Test]
    public function metadata_carries_references_only_never_guest_or_card_detail(): void
    {
        // Stripe metadata is visible to anyone with dashboard access, so it holds ids we can
        // look up rather than anything about the person or their card.
        $payload = app(StripeGateway::class)->sessionPayload($this->payment());

        $serialised = strtolower(json_encode($payload['metadata']));

        foreach (['card', 'cvc', 'cvv', 'email', 'phone', 'secret'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialised);
        }
    }

    #[Test]
    public function no_table_has_anywhere_to_put_a_card(): void
    {
        /*
         * A schema-level check, because this is how the promise would actually break: not by
         * someone deciding to store cards, but by a migration adding a `payment_method_id`
         * column that then looks like it is meant to be filled.
         */
        foreach (['users', 'bookings', 'booking_payments'] as $table) {
            foreach (Schema::getColumnListing($table) as $column) {
                foreach (['card', 'cvc', 'cvv', 'payment_method', 'pan'] as $forbidden) {
                    $this->assertStringNotContainsString(
                        $forbidden,
                        strtolower($column),
                        "{$table}.{$column} looks like it stores card data"
                    );
                }
            }
        }
    }
}
