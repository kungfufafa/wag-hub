<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_accounts', function (Blueprint $table): void {
            // Live pairing state for self-hosted engines (WAHA/Baileys NOWEB).
            // unknown|stopped|starting|scan_qr|working|failed
            $table->string('session_status', 32)->default('unknown')->after('health_status');
            // Low-sensitivity display metadata (connected number, push name, engine).
            $table->json('session_meta')->nullable()->after('session_status');
            $table->timestamp('session_synced_at')->nullable()->after('session_meta');
        });
    }

    public function down(): void
    {
        Schema::table('provider_accounts', function (Blueprint $table): void {
            $table->dropColumn(['session_status', 'session_meta', 'session_synced_at']);
        });
    }
};
