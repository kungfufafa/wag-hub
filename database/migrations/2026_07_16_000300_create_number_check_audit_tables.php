<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_check_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('client_application_id')
                ->constrained('client_applications')
                ->restrictOnDelete();
            $table->foreignId('api_credential_id')
                ->constrained('api_credentials')
                ->restrictOnDelete();
            $table->foreignId('routing_policy_id')
                ->nullable()
                ->constrained('routing_policies')
                ->restrictOnDelete();
            $table->foreignId('resolved_provider_account_id')
                ->nullable()
                ->constrained('provider_accounts')
                ->restrictOnDelete();
            $table->string('correlation_id', 160);
            $table->text('recipient');
            $table->char('recipient_hash', 64);
            $table->char('recipient_last4', 4);
            $table->string('route_key', 80);
            $table->string('status', 32);
            $table->boolean('registered')->nullable();
            $table->string('last_error_code', 120)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(
                ['client_application_id', 'status', 'created_at'],
                'number_checks_app_status_created_index',
            );
            $table->index(['recipient_hash', 'created_at']);
            $table->index('correlation_id');
        });

        Schema::create('number_check_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('number_check_request_id')
                ->constrained('number_check_requests')
                ->restrictOnDelete();
            $table->foreignId('provider_account_id')
                ->constrained('provider_accounts')
                ->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('status', 32);
            $table->boolean('registered')->nullable();
            $table->unsignedInteger('http_status')->nullable();
            $table->string('reason_code', 120)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at');
            $table->timestamps();

            $table->unique(['number_check_request_id', 'sequence']);
            $table->index(['provider_account_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_check_attempts');
        Schema::dropIfExists('number_check_requests');
    }
};
