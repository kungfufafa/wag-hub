<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_applications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->string('slug', 80)->unique();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('rate_limit_per_minute')->default(60);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('api_credentials', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('client_application_id')
                ->constrained('client_applications')
                ->restrictOnDelete();
            $table->string('name', 120);
            $table->char('token_hash', 64)->unique();
            $table->string('token_prefix', 16);
            $table->text('abilities');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['client_application_id', 'name']);
        });

        Schema::create('provider_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name', 120);
            $table->string('slug', 80)->unique();
            $table->string('driver', 24);
            $table->text('configuration');
            $table->boolean('is_active')->default(true);
            $table->string('health_status', 24)->default('unknown');
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('circuit_open_until')->nullable();
            $table->unsignedInteger('timeout_seconds')->default(15);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['driver', 'is_active']);
            $table->index(['health_status', 'circuit_open_until']);
        });

        Schema::create('routing_policies', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('client_application_id')
                ->nullable()
                ->constrained('client_applications')
                ->restrictOnDelete();
            $table->string('name', 120);
            $table->string('key', 80);
            $table->string('purpose', 40)->nullable();
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['client_application_id', 'key', 'purpose'],
                'routing_policies_scope_unique',
            );
            $table->index(['key', 'purpose', 'is_active']);
        });

        Schema::create('routing_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('routing_policy_id')
                ->constrained('routing_policies')
                ->restrictOnDelete();
            $table->foreignId('provider_account_id')
                ->constrained('provider_accounts')
                ->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['routing_policy_id', 'position']);
            $table->unique(['routing_policy_id', 'provider_account_id']);
        });

        Schema::create('gateway_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('client_application_id')
                ->constrained('client_applications')
                ->restrictOnDelete();
            $table->foreignId('routing_policy_id')
                ->nullable()
                ->constrained('routing_policies')
                ->restrictOnDelete();
            $table->foreignId('accepted_provider_account_id')
                ->nullable()
                ->constrained('provider_accounts')
                ->restrictOnDelete();
            $table->string('idempotency_key', 160);
            $table->char('payload_hash', 64);
            $table->string('correlation_id', 160);
            $table->string('client_reference', 160)->nullable();
            $table->text('recipient');
            $table->char('recipient_hash', 64);
            $table->char('recipient_last4', 4);
            $table->longText('body');
            $table->string('purpose', 40);
            $table->string('route_key', 80)->default('default');
            $table->string('mode', 16);
            $table->unsignedTinyInteger('priority');
            $table->string('status', 32);
            $table->text('metadata')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('last_error_code', 120)->nullable();
            $table->text('last_error_message')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('provider_accepted_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('outcome_unknown_at')->nullable();
            $table->timestamp('dead_lettered_at')->nullable();
            $table->timestamps();

            $table->unique(['client_application_id', 'idempotency_key']);
            $table->index(
                ['client_application_id', 'status', 'created_at'],
                'gateway_messages_app_status_created_index',
            );
            $table->index(['recipient_hash', 'created_at']);
            $table->index(['status', 'queued_at']);
        });

        Schema::create('message_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gateway_message_id')
                ->constrained('gateway_messages')
                ->restrictOnDelete();
            $table->foreignId('provider_account_id')
                ->constrained('provider_accounts')
                ->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('status', 32);
            $table->string('delivery_certainty', 16);
            $table->string('retry_disposition', 24);
            $table->unsignedInteger('http_status')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error_code', 120)->nullable();
            $table->text('error_message')->nullable();
            $table->text('response_excerpt')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway_message_id', 'sequence']);
            $table->index(['provider_account_id', 'status', 'created_at']);
        });

        Schema::create('message_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('gateway_message_id')
                ->constrained('gateway_messages')
                ->restrictOnDelete();
            $table->string('type', 64);
            $table->string('source', 32);
            $table->text('data')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['gateway_message_id', 'occurred_at']);
            $table->index(['type', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_events');
        Schema::dropIfExists('message_attempts');
        Schema::dropIfExists('gateway_messages');
        Schema::dropIfExists('routing_steps');
        Schema::dropIfExists('routing_policies');
        Schema::dropIfExists('provider_accounts');
        Schema::dropIfExists('api_credentials');
        Schema::dropIfExists('client_applications');
    }
};
