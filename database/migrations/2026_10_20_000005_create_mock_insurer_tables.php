<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storage for the FAKE insurer. In real life this lives in another company's database;
 * separate tables make it obvious that PolicyFlow only talks to it through InsurerGateway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mock_insurer_policies', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 64)->unique(); // provider-side de-duplication
            $table->string('provider_reference')->unique();
            $table->string('policy_number')->unique();
            $table->string('status', 20); // PENDING | ISSUED
            $table->string('scenario', 30);
            $table->timestamps();
        });

        Schema::create('mock_insurer_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_id', 100)->unique();
            $table->string('provider_reference');
            $table->string('type', 30);
            $table->json('payload');
            $table->unsignedTinyInteger('times_to_deliver')->default(1);
            $table->unsignedTinyInteger('times_delivered')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mock_insurer_events');
        Schema::dropIfExists('mock_insurer_policies');
    }
};
