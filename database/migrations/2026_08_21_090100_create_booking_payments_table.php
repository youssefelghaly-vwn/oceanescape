<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per payment we ask a guest for: the deposit, the balance, or — for a
 * last-minute stay — a single full payment.
 *
 * IDEMPOTENCY IS ENFORCED BY THE SCHEMA, NOT BY CAREFUL CODE
 *
 *   unique(booking_id, type)          one deposit and one balance per booking, ever.
 *                                    A retried link request updates the existing row.
 *   unique(idempotency_key)           the key we hand Stripe. Replaying a session
 *                                    creation returns the original session rather
 *                                    than charging twice.
 *   unique(stripe_checkout_session_id)
 *   unique(stripe_payment_intent_id)  a webhook can never attach one Stripe object
 *                                     to two payment rows.
 *
 * Getting these wrong means double-charging a guest, so they are constraints rather
 * than conventions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();

            $table->string('reference', 16)->unique();

            /*
             * Random, unguessable path segment for the payment link. NOT the id:
             * ids are sequential, and a payment URL that can be enumerated is a
             * payment URL that leaks who is staying where and for how much.
             * The route is additionally signed and expiring.
             */
            $table->string('token', 64)->unique();

            $table->string('type');       // deposit | balance | full
            $table->string('status')->default('pending');

            $table->unsignedInteger('amount_cents');
            $table->char('currency', 3);

            /*
             * What Stripe actually captured, from the webhook. Stored separately from
             * amount_cents and compared on receipt: if they disagree we do NOT mark the
             * booking paid, because either the session was tampered with or we built it
             * wrong, and both need a human.
             */
            $table->unsignedInteger('amount_received_cents')->nullable();

            $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->string('stripe_charge_id')->nullable();
            $table->string('stripe_customer_id')->nullable();

            $table->string('idempotency_key', 64)->unique();

            // --- link lifecycle ---
            $table->timestamp('link_sent_at')->nullable();
            $table->unsignedSmallInteger('link_send_count')->default(0);
            $table->timestamp('link_expires_at')->nullable();

            // --- outcome ---
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->string('failure_reason')->nullable();

            $table->unsignedInteger('refunded_cents')->default(0);
            $table->timestamp('refunded_at')->nullable();

            /*
             * Whether we managed to report this payment back to Lodgify. Best effort by
             * design (config booking.record_payments_in_lodgify) — the money is taken
             * and the reservation is already Booked, so a failure here is an admin
             * follow-up, never a guest-facing error.
             */
            $table->timestamp('recorded_in_lodgify_at')->nullable();
            $table->text('lodgify_record_error')->nullable();

            $table->timestamps();

            // One deposit and one balance per booking. The core anti-double-charge rule.
            $table->unique(['booking_id', 'type']);

            // Drives the expiry sweeper.
            $table->index(['status', 'link_expires_at']);
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_payments');
    }
};
