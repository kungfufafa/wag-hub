<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('provider_account_id')
                ->constrained('provider_accounts')
                ->cascadeOnDelete();
            $table->string('chat_id', 190);
            $table->string('peer_key', 190);
            $table->string('title', 190);
            $table->string('preview', 500)->default('');
            $table->boolean('is_group')->default(false);
            $table->boolean('last_from_me')->default(false);
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->unique(['provider_account_id', 'chat_id']);
            $table->index(['provider_account_id', 'peer_key']);
            $table->index(['provider_account_id', 'last_message_at']);
        });

        Schema::create('inbox_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inbox_conversation_id')
                ->constrained('inbox_conversations')
                ->cascadeOnDelete();
            $table->string('provider_message_id', 190);
            $table->boolean('from_me');
            $table->text('body');
            $table->string('kind', 24)->default('text');
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->unique(['inbox_conversation_id', 'provider_message_id']);
            $table->index(['inbox_conversation_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_messages');
        Schema::dropIfExists('inbox_conversations');
    }
};
