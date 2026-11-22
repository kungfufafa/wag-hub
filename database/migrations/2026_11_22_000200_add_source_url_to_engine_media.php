<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engine_media', function (Blueprint $table): void {
            // Where the hub fetched the file from when a message was sent with a media URL;
            // an idempotent replay compares it instead of downloading again.
            $table->string('source_url', 2048)->nullable()->after('sha256');
        });
    }

    public function down(): void
    {
        Schema::table('engine_media', function (Blueprint $table): void {
            $table->dropColumn('source_url');
        });
    }
};
