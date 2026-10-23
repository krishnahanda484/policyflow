<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_state_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('policy_request_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->string('reason');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['policy_request_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_state_history');
    }
};
