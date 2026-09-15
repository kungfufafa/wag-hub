<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateway_messages', function (Blueprint $table): void {
            // API idempotency remains scoped to client_application_id. Inbox
            // submissions have no client application, so they need their own
            // nullable unique key to close the double-submit race.
            $table->uuid('inbox_submission_uuid')->nullable()->after('idempotency_key');
            $table->unique('inbox_submission_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('gateway_messages', function (Blueprint $table): void {
            $table->dropUnique(['inbox_submission_uuid']);
            $table->dropColumn('inbox_submission_uuid');
        });
    }
};
