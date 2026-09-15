<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inbox_messages', function (Blueprint $table): void {
            $table->text('attachment')->nullable()->after('kind');
        });
    }

    public function down(): void
    {
        Schema::table('inbox_messages', function (Blueprint $table): void {
            $table->dropColumn('attachment');
        });
    }
};
