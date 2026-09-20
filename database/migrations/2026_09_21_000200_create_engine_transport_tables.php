<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engine_media', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('client_application_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('disk', 50);
            $table->string('path');
            $table->string('filename');
            $table->string('mimetype', 150);
            $table->unsignedBigInteger('size');
            $table->char('sha256', 64);
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        Schema::create('engine_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('client_application_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('engine_session_id')->index();
            $table->uuid('session_uuid');
            $table->uuid('runner_id');
            $table->unsignedBigInteger('generation');
            $table->string('idempotency_key', 160);
            $table->char('payload_hash', 64);
            $table->string('type', 20);
            $table->text('recipient');
            $table->longText('payload');
            $table->foreignId('engine_media_id')->nullable()->constrained('engine_media')->nullOnDelete();
            $table->string('transport_status', 30)->default('queued')->index();
            $table->string('receipt_status', 20)->nullable();
            $table->string('provider_message_id', 200)->nullable()->index();
            $table->string('error_code', 100)->nullable();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->unique(['client_application_id', 'idempotency_key'], 'engine_message_idempotency_unique');
        });

        Schema::create('engine_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('source_event_id', 100)->nullable()->unique();
            $table->foreignId('client_application_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('engine_session_id')->nullable()->index();
            $table->uuid('session_uuid');
            $table->string('event', 30);
            $table->unsignedBigInteger('revision')->default(0);
            $table->longText('data');
            $table->timestamp('occurred_at');
            $table->timestamp('expires_at')->index();
            $table->unsignedInteger('delivery_attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('delivery_locked_until')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('delivery_error', 100)->nullable();
            $table->timestamps();
            $table->index(['client_application_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engine_events');
        Schema::dropIfExists('engine_messages');
        Schema::dropIfExists('engine_media');
    }
};
