<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();
            // The provider's own event id: the idempotency key for callbacks.
            $table->string('event_id', 100)->unique();
            $table->foreignId('policy_request_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider_reference')->index();
            $table->string('type', 30);
            $table->json('payload');
            // APPLIED | IGNORED_INVALID_TRANSITION | IGNORED_CONFLICT
            $table->string('result', 40)->nullable();
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_events');
    }
};
