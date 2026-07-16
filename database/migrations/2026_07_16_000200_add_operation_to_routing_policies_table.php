<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routing_policies', function (Blueprint $table): void {
            $table->dropUnique('routing_policies_scope_unique');
            $table->string('operation', 24)->default('message')->after('client_application_id');
            $table->unique(
                ['client_application_id', 'operation', 'key', 'purpose'],
                'routing_policies_scope_unique',
            );
            $table->index(
                ['operation', 'key', 'purpose', 'is_active'],
                'routing_policies_operation_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('routing_policies', function (Blueprint $table): void {
            $table->dropIndex('routing_policies_operation_index');
            $table->dropUnique('routing_policies_scope_unique');
            $table->dropColumn('operation');
            $table->unique(
                ['client_application_id', 'key', 'purpose'],
                'routing_policies_scope_unique',
            );
        });
    }
};
