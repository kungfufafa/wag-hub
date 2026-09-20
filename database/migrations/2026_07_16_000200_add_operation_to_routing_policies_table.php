<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL may use the composite unique index to support the application FK.
        Schema::table('routing_policies', fn (Blueprint $table) => $table->index('client_application_id', 'routing_policies_fk_guard'));
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
        Schema::table('routing_policies', fn (Blueprint $table) => $table->dropIndex('routing_policies_fk_guard'));
    }

    public function down(): void
    {
        Schema::table('routing_policies', fn (Blueprint $table) => $table->index('client_application_id', 'routing_policies_fk_guard'));
        Schema::table('routing_policies', function (Blueprint $table): void {
            $table->dropIndex('routing_policies_operation_index');
            $table->dropUnique('routing_policies_scope_unique');
            $table->dropColumn('operation');
            $table->unique(
                ['client_application_id', 'key', 'purpose'],
                'routing_policies_scope_unique',
            );
        });
        Schema::table('routing_policies', fn (Blueprint $table) => $table->dropIndex('routing_policies_fk_guard'));
    }
};
