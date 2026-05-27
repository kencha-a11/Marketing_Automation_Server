<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('gcash_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            // Strict enforcement: Makes it database-impossible to insert duplicates
            $table->string('gcash_ref', 13)->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gcash_registrations');
    }
};
