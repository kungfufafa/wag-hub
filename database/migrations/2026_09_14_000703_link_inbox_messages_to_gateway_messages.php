<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_messages', function (Blueprint $table): void {
            $table->foreignId('gateway_message_id')->nullable()->after('inbox_conversation_id')
                ->constrained('gateway_messages')->nullOnDelete();
            $table->index('gateway_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('inbox_messages', function (Blueprint $table): void {
            $table->dropForeign(['gateway_message_id']);
            $table->dropIndex(['gateway_message_id']);
            $table->dropColumn('gateway_message_id');
        });
    }
};
