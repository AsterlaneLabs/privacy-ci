<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_deletion_requests', function (Blueprint $table) {
            $table->string('id', 32)->primary();
            $table->string('subject_type')->index();
            $table->string('subject_id');
            $table->string('status', 32);
            $table->timestamp('requested_at');
            $table->timestamp('execute_after');
            $table->string('requested_via')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolved_reason')->nullable();
            $table->string('policy_fingerprint')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->text('reminders_sent')->nullable();

            // The whole story of one erasure lives on one row: what was there
            // before, and what remained after.
            $table->longText('footprint')->nullable();
            $table->longText('verification')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);

            // The scheduler asks "what is due?" on every tick; without this it
            // table-scans a table that only ever grows.
            $table->index(['status', 'execute_after']);

            // One *open* request per subject, enforced in the database because the
            // application-level check races under concurrency.
            //
            // A plain unique on (subject_type, subject_id, status) would be wrong:
            // a user may request and cancel repeatedly, and the second cancelled
            // row would collide. Partial indexes would solve it on Postgres but
            // not MySQL, so instead this column is populated only while the
            // request is open and NULL otherwise, and NULLs do not collide in a
            // unique index on either engine.
            $table->string('open_key')->nullable()->unique('privacy_one_open_request');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_deletion_requests');
    }
};
