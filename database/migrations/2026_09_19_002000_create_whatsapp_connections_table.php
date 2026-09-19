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
            $table->foreignId('client_application_id')->constrained('client_applications')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('slug', 80);
            $table->string('type', 32);
            $table->string('status', 32)->default('setup_required');
            $table->boolean('is_default')->default(false);
            $table->foreignId('provider_account_id')->nullable()->constrained('provider_accounts')->nullOnDelete();
            $table->foreignId('routing_policy_id')->nullable()->constrained('routing_policies')->nullOnDelete();
            $table->foreignId('number_check_policy_id')->nullable()->constrained('routing_policies')->nullOnDelete();
            $table->json('capabilities')->nullable();
            $table->json('setup_state')->nullable();
            $table->string('last_error_code', 80)->nullable();
            $table->text('last_error_message')->nullable();
            $table->string('recommended_action', 120)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['client_application_id', 'slug']);
            $table->index(['client_application_id', 'is_default']);
            $table->index(['client_application_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_connections');
    }
};
