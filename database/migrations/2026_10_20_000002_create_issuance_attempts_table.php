<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issuance_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number');
            // Same key on every attempt so the insurer can de-duplicate on its side too.
            $table->string('idempotency_key', 64);
            // IN_FLIGHT | ISSUED | PENDING | TEMPORARY_FAILURE | TIMEOUT | REJECTED
            $table->string('outcome', 30)->default('IN_FLIGHT');
            $table->string('provider_reference')->nullable();
            $table->json('response')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['policy_request_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issuance_attempts');
    }
};
