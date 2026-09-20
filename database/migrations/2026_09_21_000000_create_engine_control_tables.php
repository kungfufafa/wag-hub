<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engine_integrations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_application_id')->unique()->constrained();
            $table->unsignedInteger('configured_limit')->default(5);
            $table->unsignedInteger('maximum_allowed')->default(10);
            $table->text('webhook_url')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->boolean('webhook_enabled')->default(false);
            $table->unsignedBigInteger('webhook_cursor')->nullable();
            $table->timestamp('webhook_last_success_at')->nullable();
            $table->timestamp('webhook_last_error_at')->nullable();
            $table->text('webhook_last_error')->nullable();
            $table->timestamp('migration_verified_at')->nullable();
            $table->timestamp('last_legacy_request_at')->nullable();
            $table->timestamp('legacy_retired_at')->nullable();
            $table->timestamps();
        });
        Schema::create('engine_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('client_application_id')->constrained();
            $table->string('runner_id')->unique();
            $table->string('name');
            $table->string('external_reference')->nullable();
            $table->string('creation_key', 160);
            $table->string('creation_hash', 64);
            $table->string('status', 24)->default('STOPPED');
            $table->string('desired_state', 16)->default('STOPPED');
            $table->string('auth_method', 16)->nullable();
            $table->text('phone_number')->nullable();
            $table->unsignedBigInteger('revision')->default(1);
            $table->unsignedBigInteger('generation')->default(0);
            $table->unsignedBigInteger('runner_revision')->default(0);
            $table->timestamp('observed_at')->nullable();
            $table->timestamp('authenticated_at')->nullable();
            $table->timestamp('provisioning_expires_at')->nullable();
            $table->boolean('engine_available')->default(false);
            $table->unsignedBigInteger('pending_operation_id')->nullable();
            $table->text('last_error')->nullable();
            $table->text('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['client_application_id', 'creation_key']);
            $table->unique(['client_application_id', 'external_reference']);
            $table->index(['desired_state', 'deleted_at']);
        });
        Schema::create('engine_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('client_application_id')->constrained();
            $table->foreignId('engine_session_id')->constrained();
            $table->string('action', 20);
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('generation');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('payload')->nullable();
            $table->text('result')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('available_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engine_operations');
        Schema::dropIfExists('engine_sessions');
        Schema::dropIfExists('engine_integrations');
    }
};
