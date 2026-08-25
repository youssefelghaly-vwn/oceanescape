<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every Stripe webhook we accept, recorded before it is acted on.
 *
 * WHY THIS TABLE IS THE WHOLE IDEMPOTENCY STRATEGY FOR WEBHOOKS
 *
 * Stripe guarantees AT-LEAST-ONCE delivery, not exactly-once. The same event will
 * arrive again if our response is slow, if it returns a 5xx, or occasionally for no
 * reason at all. Handling `checkout.session.completed` twice must not mark a booking
 * paid twice, email the guest twice, or fire two Lodgify writes.
 *
 * The mechanism is a unique index on `stripe_event_id` plus insert-before-process:
 * we INSERT the event first, and a duplicate-key violation is the signal that this
 * event has already been seen. That is atomic at the database level, so it holds even
 * when two deliveries land on two workers in the same millisecond — which a
 * check-then-act `if (!exists)` would not.
 *
 * Keeping the payload is also what lets a failed handler be replayed from our own
 * records rather than asking Stripe to redeliver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_webhook_events', function (Blueprint $table) {
            $table->id();

            // evt_... — the idempotency key. This unique index IS the guarantee.
            $table->string('stripe_event_id')->unique();

            $table->string('type')->index();          // checkout.session.completed, ...
            $table->string('api_version')->nullable();

            // received | processed | ignored | failed
            $table->string('status')->default('received');

            $table->json('payload');

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();

            /*
             * Denormalised link back to what the event touched, so an admin can answer
             * "what happened to this booking?" without grepping JSON.
             */
            $table->foreignId('booking_payment_id')->nullable()
                ->constrained('booking_payments')->nullOnDelete();

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
    }
};
