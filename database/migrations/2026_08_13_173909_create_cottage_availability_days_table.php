<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cottage_availability_days', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('property_id');
            $table->unsignedInteger('room_id')->nullable();
            $table->date('date');
            $table->boolean('is_available')->default(false);
            $table->unsignedTinyInteger('minimal_stay')->nullable();
            $table->boolean('is_check_in_available')->default(true);
            $table->boolean('is_check_out_available')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['property_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cottage_availability_days');
    }
};