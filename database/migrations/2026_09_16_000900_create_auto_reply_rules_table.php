<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_reply_rules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('provider_account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            // Lower runs first.
            $table->integer('priority')->default(0);
            // welcome | keyword | fallback
            $table->string('match_type', 32)->default('keyword');
            // contains | exact (only for keyword)
            $table->string('match_mode', 16)->nullable();
            $table->json('keywords')->nullable();
            $table->text('reply_body');
            $table->timestamps();

            $table->index(['provider_account_id', 'is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_reply_rules');
    }
};
