<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Booking;
use App\Models\BookingPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingPayment>
 */
class BookingPaymentFactory extends Factory
{
    protected $model = BookingPayment::class;

    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'reference' => 'PAY-'.strtoupper($this->faker->unique()->lexify('??????')),
            'token' => bin2hex(random_bytes(32)),
            'type' => PaymentType::Deposit,
            'status' => PaymentStatus::Pending,
            'amount_cents' => 25000,
            'currency' => 'CAD',
            'idempotency_key' => hash('sha256', $this->faker->unique()->uuid()),
            'link_expires_at' => now()->addHours(48),
        ];
    }

    public function deposit(): static
    {
        return $this->state(fn () => ['type' => PaymentType::Deposit]);
    }

    public function balance(): static
    {
        return $this->state(fn () => ['type' => PaymentType::Balance]);
    }

    public function linkSent(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::LinkSent,
            'link_sent_at' => now(),
            'link_send_count' => 1,
            'stripe_checkout_session_id' => 'cs_test_'.$this->faker->unique()->lexify('??????????'),
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attrs) => [
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
            'amount_received_cents' => $attrs['amount_cents'] ?? 25000,
        ]);
    }

    public function expiredLink(): static
    {
        return $this->state(fn () => [
            'status' => PaymentStatus::LinkSent,
            'link_expires_at' => now()->subHour(),
        ]);
    }
}
