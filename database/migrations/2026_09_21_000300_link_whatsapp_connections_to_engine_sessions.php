<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table): void {
            // A managed number is exactly one v2 engine session; a session backs at most one connection.
            $table->foreignId('engine_session_id')
                ->nullable()
                ->after('type')
                ->unique()
                ->constrained('engine_sessions')
                ->nullOnDelete();
            $table->string('creation_key', 160)->nullable()->after('engine_session_id');
            $table->char('creation_hash', 64)->nullable()->after('creation_key');

            $table->unique(['client_application_id', 'creation_key']);
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table): void {
            $table->dropUnique(['client_application_id', 'creation_key']);
            $table->dropForeign(['engine_session_id']);
            $table->dropUnique(['engine_session_id']);
            $table->dropColumn(['engine_session_id', 'creation_key', 'creation_hash']);
        });
    }
};
