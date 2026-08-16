<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_stay_requests', function (Blueprint $table) {
            $table->id();

            // Human-friendly handle for phone and email conversations.
            $table->string('reference', 16)->unique();

            // --- company ---
            $table->string('company_name');
            $table->string('industry')->nullable();
            $table->string('website')->nullable();
            $table->string('tax_number')->nullable();

            // --- contact ---
            $table->string('contact_name');
            $table->string('job_title')->nullable();
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('company_website_url')->nullable();

            // --- the stay ---
            $table->date('check_in')->nullable();
            $table->date('check_out')->nullable();
            /*
             * Corporate enquiries frequently arrive before dates are fixed
             * ("some week in October"). Storing that intent separately keeps
             * check_in/check_out honest rather than filled with placeholders.
             */
            $table->boolean('dates_flexible')->default(false);
            $table->string('flexible_note')->nullable();

            $table->unsignedSmallInteger('guests_count');
            $table->unsignedSmallInteger('cottages_count');
            $table->unsignedSmallInteger('nights')->nullable();

            $table->string('purpose')->nullable();   // retreat, project crew, relocation...
            $table->decimal('budget_per_night', 10, 2)->nullable();
            $table->string('currency', 3)->default('CAD');

            $table->boolean('needs_invoice')->default(false);
            $table->boolean('needs_meeting_space')->default(false);
            $table->boolean('pets')->default(false);

            $table->text('message')->nullable();

            // --- workflow ---
            $table->string('status')->default('new')->index();
            $table->text('internal_notes')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('contacted_at')->nullable();
            $table->timestamp('quoted_at')->nullable();
            $table->timestamp('closed_at')->nullable();

            // --- provenance, useful for spam triage and attribution ---
            $table->string('source')->nullable();
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent', 512)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
            $table->index('check_in');
            $table->index('email');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_stay_requests');
    }
};