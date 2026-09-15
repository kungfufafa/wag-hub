<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateway_messages', function (Blueprint $table): void {
            $table->dropForeign(['client_application_id']);
            $table->foreignId('client_application_id')->nullable()->change();
            $table->foreign('client_application_id')
                ->references('id')
                ->on('client_applications')
                ->restrictOnDelete();
            $table->string('origin', 24)->default('api')->after('mode');
            $table->foreignId('origin_user_id')->nullable()->after('origin')->constrained('users')->nullOnDelete();
            $table->foreignId('pinned_provider_account_id')->nullable()->after('origin_user_id')->constrained('provider_accounts')->nullOnDelete();
            $table->string('inbox_chat_id', 190)->nullable()->after('pinned_provider_account_id');
            $table->index(['origin', 'origin_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('gateway_messages', function (Blueprint $table): void {
            $table->dropForeign(['origin_user_id']);
            $table->dropForeign(['pinned_provider_account_id']);
            $table->dropIndex(['origin', 'origin_user_id', 'created_at']);
            $table->dropColumn(['origin', 'origin_user_id', 'pinned_provider_account_id', 'inbox_chat_id']);
        });
    }
};
