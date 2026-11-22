<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_applications', function (Blueprint $table): void {
            // Optional display details for the admin panel; neither affects routing or credentials.
            $table->string('website_url', 2048)->nullable()->after('slug');
            $table->string('logo_path')->nullable()->after('website_url');
        });
    }

    public function down(): void
    {
        Schema::table('client_applications', function (Blueprint $table): void {
            $table->dropColumn(['website_url', 'logo_path']);
        });
    }
};
