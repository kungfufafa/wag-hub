<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A visual, node-based bot logic graph (n8n-style). `definition` holds
        // the canvas export: nodes (trigger/message/condition/menu/ai/handoff)
        // and their output connections.
        Schema::create('bot_graphs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('provider_account_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->json('definition')->nullable();
            $table->timestamps();

            $table->index(['provider_account_id', 'is_active', 'priority']);
        });

        Schema::table('bot_conversation_states', function (Blueprint $table): void {
            $table->foreignId('bot_graph_id')->nullable()->after('bot_flow_id')->constrained()->nullOnDelete();
            $table->string('node_id')->nullable()->after('bot_graph_id');
        });
    }

    public function down(): void
    {
        Schema::table('bot_conversation_states', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bot_graph_id');
            $table->dropColumn('node_id');
        });
        Schema::dropIfExists('bot_graphs');
    }
};
