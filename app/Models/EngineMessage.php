<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngineMessage extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'recipient' => 'encrypted', 'payload' => 'encrypted:array',
            'generation' => 'integer', 'processing_at' => 'datetime', 'reconciled_at' => 'datetime', 'expires_at' => 'datetime',
        ];
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(EngineMedia::class, 'engine_media_id');
    }
}
