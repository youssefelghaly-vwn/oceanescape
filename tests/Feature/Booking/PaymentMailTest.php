<?php

namespace Tests\Feature\Booking;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Mail\PaymentLinkMail;
use App\Models\Booking;
use App\Models\BookingPayment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The two payment emails.
 *
 * They are separate templates because they are separate messages. The deposit mail has to
 * say the dates are NOT held and will be released when the link expires; the balance mail
 * goes to someone whose stay is already confirmed, where nothing is at risk and saying so
 * would be alarming and wrong. These tests pin that difference, since a single shared
 * template would pass a "does it mention the amount" check while telling half the guests
 * something untrue.
 */
class PaymentMailTest extends TestCase
{
    use RefreshDatabase;

    private function booking(array $overrides = []): Booking
    {
        return Booking::factory()->create(array_merge([
            'cottage_name' => 'Sea Glass Cottage',
            'guest_name' => 'Alex Morgan',
            'guest_email' => 'alex@example.test',
            'currency' => 'CAD',
            'total_cents' => 90000,
            'deposit_cents' => 22500,
            'balance_cents' => 67500,
        ], $overrides));
    }

    private function payment(Booking $booking, PaymentType $type, int $cents): BookingPayment
    {
        return BookingPayment::factory()->for($booking)->create([
            'type' => $type,
            'amount_cents' => $cents,
            'currency' => 'CAD',
            'link_expires_at' => now()->addHours(48),
        ]);
    }

    private function render(Booking $booking, BookingPayment $payment): string
    {
        return (new PaymentLinkMail($booking, $payment))->render();
    }

    #[Test]
    public function the_deposit_mail_says_the_dates_are_not_held_yet(): void
    {
        $booking = $this->booking();
        $html = $this->render($booking, $this->payment($booking, PaymentType::Deposit, 22500));

        $this->assertStringContainsString('225.00 CAD', $html);
        $this->assertStringContainsString('Deposit due now', $html);
        // The sentence the whole email exists for.
        $this->assertStringContainsString('release the dates', $html);
        // And it promises the balance mail that does in fact follow.
        $this->assertStringContainsString('675.00 CAD', $html);
    }

    #[Test]
    public function the_balance_mail_reassures_rather_than_warns(): void
    {
        $booking = $this->booking(['status' => BookingStatus::DepositPaid]);

        $this->payment($booking, PaymentType::Deposit, 22500)->forceFill([
            'status' => PaymentStatus::Paid,
            'amount_received_cents' => 22500,
            'paid_at' => now(),
        ])->save();

        $html = $this->render($booking->fresh(), $this->payment($booking, PaymentType::Balance, 67500));

        $this->assertStringContainsString('675.00 CAD', $html);
        $this->assertStringContainsString('confirmed', $html);
        $this->assertStringContainsString('already paid', $html);

        // Nothing is at risk on a confirmed booking, so the deposit's warning must not leak.
        $this->assertStringNotContainsString('release the dates', $html);
    }

    #[Test]
    public function a_single_full_payment_does_not_promise_a_balance_email(): void
    {
        /*
         * A stay inside `full_payment_within_days` is charged once. Telling that guest a
         * balance link is coming would have them waiting for an email that never arrives.
         */
        $booking = $this->booking([
            'requires_full_payment' => true,
            'deposit_cents' => 90000,
            'balance_cents' => 0,
        ]);

        $html = $this->render($booking, $this->payment($booking, PaymentType::Full, 90000));

        $this->assertStringContainsString('900.00 CAD', $html);
        $this->assertStringContainsString('Due now', $html);
        $this->assertStringNotContainsString('balance of', $html);
    }

    #[Test]
    public function both_carry_the_reference_and_a_working_signed_link(): void
    {
        $booking = $this->booking();

        foreach ([[PaymentType::Deposit, 22500], [PaymentType::Balance, 67500]] as [$type, $cents]) {
            $payment = $this->payment($booking, $type, $cents);
            $html = $this->render($booking, $payment);

            $this->assertStringContainsString($booking->reference, $html);
            // Our own signed route, never a raw Stripe URL — the link has to be revocable.
            $this->assertStringContainsString('/pay/'.$payment->token, $html);
            $this->assertStringContainsString('signature=', $html);
            $this->assertStringNotContainsString('checkout.stripe.com', $html);

            $payment->delete();   // one row per (booking, type)
        }
    }

    #[Test]
    public function the_subject_says_which_payment_it_is(): void
    {
        $booking = $this->booking();

        $deposit = new PaymentLinkMail($booking, $this->payment($booking, PaymentType::Deposit, 22500));
        $this->assertStringContainsString('deposit due', $deposit->envelope()->subject);

        $balance = new PaymentLinkMail($booking, $this->payment($booking, PaymentType::Balance, 67500));
        $this->assertStringContainsString('balance', $balance->envelope()->subject);
    }

    #[Test]
    public function neither_mail_mentions_a_stored_card(): void
    {
        // The copy promises the opposite, and it has to stay true in both templates.
        $booking = $this->booking();

        foreach ([[PaymentType::Deposit, 22500], [PaymentType::Balance, 67500]] as [$type, $cents]) {
            $payment = $this->payment($booking, $type, $cents);

            $this->assertStringContainsString(
                'never see or store your card details',
                $this->render($booking, $payment),
            );

            $payment->delete();
        }
    }
}
