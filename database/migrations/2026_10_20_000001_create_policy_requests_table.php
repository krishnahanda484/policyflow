<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_requests', function (Blueprint $table) {
            $table->id();

            // Intake data (fabricated customer data only).
            $table->string('client_request_id', 100);
            $table->string('agent_id', 64);
            $table->string('customer_name');
            $table->date('date_of_birth');
            $table->string('destination', 20);
            $table->date('trip_start');
            $table->date('trip_end');
            $table->string('plan_code', 20);
            $table->unsignedBigInteger('sum_insured');

            // Immutable premium snapshot (enforced in the model, see PolicyRequest::booted()).
            $table->unsignedSmallInteger('travel_days');
            $table->unsignedSmallInteger('age_on_trip_start');
            $table->decimal('base_daily_rate', 10, 2);
            $table->decimal('age_multiplier', 5, 2);
            $table->decimal('destination_multiplier', 5, 2);
            $table->decimal('coverage_multiplier', 5, 2);
            $table->decimal('premium', 12, 2);
            $table->char('currency', 3)->default('INR');

            // Issuance workflow.
            $table->string('status', 30)->index();
            $table->string('mock_scenario', 30)->default('SUCCESS'); // development-only
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->string('provider_reference')->nullable();
            $table->string('policy_number')->nullable();
            $table->json('last_response')->nullable();
            $table->text('last_error')->nullable();
            $table->boolean('failure_retryable')->default(false);
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            // Duplicate-intake protection lives in the database, not only in PHP.
            $table->unique(['agent_id', 'client_request_id'], 'policy_requests_agent_client_unique');
            // A provider reference / policy number can belong to one request only (many NULLs are allowed).
            $table->unique('provider_reference');
            $table->unique('policy_number');
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_requests');
    }
};
