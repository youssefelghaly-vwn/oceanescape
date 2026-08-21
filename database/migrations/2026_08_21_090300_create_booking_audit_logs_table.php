<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail for the booking and payment lifecycle.
 *
 * WHY A TABLE AND NOT JUST A LOG FILE
 * Log files rotate, get shipped elsewhere, and cannot be joined against a booking in
 * an admin screen. When a guest asks "why was I charged this?" or "I paid, where is my
 * booking?", the answer has to be reconstructible months later by a non-engineer. Both
 * are written: this table for the queryable record, the `booking` log channel for the
 * incident tail (and because it survives the database being the thing that broke).
 *
 * DELIBERATELY IMMUTABLE. There is no updated_at and nothing in the application updates
 * or deletes a row here. An audit trail that can be edited is not an audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_audit_logs', function (Blueprint $table) {
            $table->id();

            /*
             * Both nullable and both nullOnDelete: the trail must outlive the records it
             * describes, otherwise deleting a booking destroys the evidence of what was
             * done to it.
             */
            $table->foreignId('booking_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignId('booking_payment_id')->nullable()
                ->constrained('booking_payments')->nullOnDelete();

            // Dotted event name, e.g. booking.created / lodgify.marked_booked /
            // payment.succeeded / payment.amount_mismatch
            $table->string('event')->index();

            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();

            // system | guest | admin | stripe | lodgify
            $table->string('actor_type')->default('system');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();

            /*
             * Structured detail. MUST NOT contain card data, full Stripe secrets, or
             * anything that would be unsafe in an admin screen — see
             * App\Services\Booking\BookingAuditor, which whitelists what goes in here.
             */
            $table->json('context')->nullable();

            $table->ipAddress('ip_address')->nullable();

            // created_at only. No updated_at: rows are never modified.
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['booking_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_audit_logs');
    }
};
