<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Business knowledge the AI agent answers from (FAQ / info), per
        // provider account. Retrieval works without an LLM; when an LLM key is
        // configured the top entries become context for a natural-language answer.
        Schema::create('knowledge_base_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('provider_account_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('content');
            $table->json('keywords')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['provider_account_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_base_entries');
    }
};
