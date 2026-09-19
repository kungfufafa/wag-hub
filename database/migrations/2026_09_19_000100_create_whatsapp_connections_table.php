<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_connections', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('client_application_id')
                ->constrained('client_applications')
                ->restrictOnDelete();
            $table->string('name', 120);
            $table->string('slug', 80);
            $table->string('type', 32);
            $table->string('status', 32)->default('setup_required');
            $table->boolean('is_default')->default(false);
            $table->foreignId('provider_account_id')
                ->nullable()
                ->constrained('provider_accounts')
                ->nullOnDelete();
            $table->foreignId('routing_policy_id')
                ->nullable()
                ->constrained('routing_policies')
                ->nullOnDelete();
            $table->string('session_id', 48)->nullable();
            $table->string('driver', 24)->nullable();
            $table->json('capabilities')->nullable();
            $table->json('sender_identity')->nullable();
            $table->json('health_summary')->nullable();
            $table->string('next_action', 64)->nullable();
            $table->text('status_detail')->nullable();
            $table->json('provisioning_state')->nullable();
            $table->timestamp('last_successful_send_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['client_application_id', 'slug']);
            $table->index(['client_application_id', 'status']);
            $table->index(['client_application_id', 'is_default']);
        });

        Schema::table('gateway_messages', function (Blueprint $table): void {
            $table->foreignId('whatsapp_connection_id')
                ->nullable()
                ->after('client_application_id')
                ->constrained('whatsapp_connections')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('gateway_messages', function (Blueprint $table): void {
            $table->dropForeign(['whatsapp_connection_id']);
            $table->dropColumn('whatsapp_connection_id');
        });

        Schema::dropIfExists('whatsapp_connections');
    }
};
