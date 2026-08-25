<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remove the Lodgify hosted-checkout attribution table.
 *
 * WHY IT IS GOING
 * `checkout_intents` existed for exactly one reason: we handed the guest to
 * checkout.lodgify.com and lost sight of them, so we recorded that we had sent them and
 * hoped to match a booking back later. Its own migration said as much — "ATTRIBUTION /
 * ABANDONMENT / WHAT WE SHOWED", and "Deliberately NOT a booking record."
 *
 * The hand-off is gone. Bookings are taken and paid for on this site now, so `bookings`
 * records all three of those things directly and authoritatively. Nothing writes to this
 * table any more, and `markConverted()` / `matchFor()` were never called even when it was
 * live, so every row is a redirect that is recorded as never having converted.
 *
 * ⚠ DESTRUCTIVE. down() restores the schema but not the rows. If those redirect records
 * have any historical value, dump the table before migrating:
 *
 *     php artisan tinker --execute="\Storage::put('checkout_intents.json', \DB::table('checkout_intents')->get()->toJson())"
 */
return new class extends Migration
{
    public function up(): void
    {
        // Drop the FK first: bookings outlives this table.
        if (Schema::hasColumn('bookings', 'checkout_intent_id')) {
            Schema::table('bookings', function (Blueprint $table) {
                $table->dropForeign(['checkout_intent_id']);
                $table->dropColumn('checkout_intent_id');
            });
        }

        Schema::dropIfExists('checkout_intents');
    }

    /**
     * Schema only — the rows are not recoverable from here.
     *
     * Reproduced faithfully so that rolling back leaves a working database rather than a
     * half-shape, even though nothing in the application writes to it any more.
     */
    public function down(): void
    {
        Schema::create('checkout_intents', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 16)->unique();

            $table->unsignedBigInteger('cottage_id');
            $table->string('cottage_name');
            $table->date('arrival');
            $table->date('departure');
            $table->unsignedSmallInteger('nights');
            $table->unsignedSmallInteger('adults')->default(2);
            $table->unsignedSmallInteger('children')->default(0);
            $table->unsignedSmallInteger('pets')->default(0);

            $table->decimal('quoted_total', 10, 2)->nullable();
            $table->char('currency', 3)->default('CAD');
            $table->json('addons')->nullable();

            $table->text('redirect_url');

            $table->string('status')->default('redirected')->index();
            $table->string('lodgify_booking_id')->nullable()->index();
            $table->timestamp('converted_at')->nullable();

            $table->string('referrer', 512)->nullable();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('session_id', 100)->nullable()->index();

            $table->timestamps();

            $table->index(['cottage_id', 'arrival']);
            $table->index(['status', 'created_at']);
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('checkout_intent_id')->nullable()
                ->constrained('checkout_intents')->nullOnDelete();
        });
    }
};
