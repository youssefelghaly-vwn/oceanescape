<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_photos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // submitter
            $table->string('guest_name');
            $table->string('guest_email');
            $table->string('caption', 300)->nullable();
            $table->unsignedBigInteger('cottage_id')->nullable();   // Lodgify property id
            $table->string('cottage_name')->nullable();
            $table->date('stayed_on')->nullable();

            /*
             * Storage moves on approval:
             *   pending  -> local disk, private, served only through an
             *              authenticated admin route
             *   approved -> public disk, web-reachable
             * Keeping unmoderated uploads off the public disk means a guessed
             * URL cannot expose content nobody has looked at yet.
             */
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100);
            $table->unsignedInteger('size_bytes');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();

            // moderation
            $table->string('status')->default('pending')->index();
            $table->string('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_featured')->default(false);

            // Explicit permission to publish. Legally and ethically we need a
            // record that the guest agreed, not just that they uploaded.
            $table->boolean('consent_given')->default(false);

            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
            $table->index(['status', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_photos');
    }
};