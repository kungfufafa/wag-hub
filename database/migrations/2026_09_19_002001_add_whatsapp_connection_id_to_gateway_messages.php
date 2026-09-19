<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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
            $table->dropConstrainedForeignId('whatsapp_connection_id');
        });
    }
};
