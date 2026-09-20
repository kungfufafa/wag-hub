<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EngineSession extends Model
{
    use HasUuids, SoftDeletes;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'STOPPED', 'desired_state' => 'STOPPED', 'revision' => 1, 'generation' => 0, 'runner_revision' => 0, 'engine_available' => false];

    protected $hidden = ['metadata', 'creation_hash', 'creation_key'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return ['phone_number' => 'encrypted', 'metadata' => 'encrypted:array', 'engine_available' => 'boolean', 'revision' => 'integer', 'generation' => 'integer', 'runner_revision' => 'integer', 'observed_at' => 'datetime', 'authenticated_at' => 'datetime', 'provisioning_expires_at' => 'datetime'];
    }

    public function clientApplication(): BelongsTo
    {
        return $this->belongsTo(ClientApplication::class);
    }

    public function pendingOperation(): BelongsTo
    {
        return $this->belongsTo(EngineOperation::class, 'pending_operation_id');
    }
}
