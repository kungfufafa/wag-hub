<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateway_messages', function (Blueprint $table): void {
            $table->string('message_type', 16)->default('text')->after('body');
            $table->text('attachment')->nullable()->after('message_type');
        });
    }

    public function down(): void
    {
        Schema::table('gateway_messages', function (Blueprint $table): void {
            $table->dropColumn(['message_type', 'attachment']);
        });
    }
};
