<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal data with no link to the subject at all, the case the plan calls
 * the hard one. We can find it; we cannot address it from a user id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('source')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscribers');
    }
};
