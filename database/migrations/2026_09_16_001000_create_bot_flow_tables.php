<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A guided menu flow (Fonnte/cekat-style): the bot posts a numbered
        // menu and reacts to the customer's choice, per provider account.
        Schema::create('bot_flows', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('provider_account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            // welcome | keyword
            $table->string('trigger_type', 32)->default('keyword');
            $table->json('keywords')->nullable();
            $table->text('header');
            // list of { key, label, action: reply|handoff, reply }
            $table->json('options');
            $table->text('footer')->nullable();
            $table->text('fallback_reply')->nullable();
            $table->integer('session_ttl_minutes')->default(10);
            $table->timestamps();

            $table->index(['provider_account_id', 'is_active', 'priority']);
        });

        // Per-chat conversation state so the bot knows which flow a customer
        // is currently navigating.
        Schema::create('bot_conversation_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_account_id')->constrained()->cascadeOnDelete();
            $table->string('chat_key');
            $table->foreignId('bot_flow_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['provider_account_id', 'chat_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_conversation_states');
        Schema::dropIfExists('bot_flows');
    }
};
