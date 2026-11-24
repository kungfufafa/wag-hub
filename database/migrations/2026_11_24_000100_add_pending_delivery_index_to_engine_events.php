<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engine_events', function (Blueprint $table): void {
            // Lets the transport tick reach the few undelivered events without scanning retained history.
            $table->index(['delivered_at', 'failed_at'], 'engine_events_pending_delivery_index');
        });
    }

    public function down(): void
    {
        Schema::table('engine_events', function (Blueprint $table): void {
            $table->dropIndex('engine_events_pending_delivery_index');
        });
    }
};
