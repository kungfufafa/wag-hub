<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('is_enabled')->default(false);
            $table->unsignedInteger('cooldown_seconds')->default(300);
            $table->string('smtp_host')->nullable();
            $table->unsignedSmallInteger('smtp_port')->nullable();
            $table->string('smtp_username')->nullable();
            $table->text('smtp_password')->nullable();
            $table->string('smtp_encryption', 16)->nullable();
            $table->string('smtp_from_address')->nullable();
            $table->string('smtp_from_name')->nullable();
            $table->text('telegram_bot_token')->nullable();
            $table->timestamps();
        });

        Schema::create('user_alert_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('telegram_enabled')->default(false);
            $table->string('telegram_chat_id')->nullable();
            $table->boolean('email_enabled')->default(false);
            $table->string('email_address')->nullable();
            $table->timestamps();
        });

        Schema::create('alert_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('provider_account_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('channel', 16);
            $table->string('from_status', 24);
            $table->string('to_status', 24);
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('circuit_open_until')->nullable();
            $table->string('error_summary', 500)->nullable();
            $table->string('status', 32);
            $table->text('failure_reason')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['provider_account_id', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['channel', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_deliveries');
        Schema::dropIfExists('user_alert_preferences');
        Schema::dropIfExists('alert_settings');
    }
};
