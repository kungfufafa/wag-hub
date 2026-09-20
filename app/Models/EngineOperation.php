<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngineOperation extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['payload'];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'result' => 'encrypted:array', 'generation' => 'integer', 'available_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(EngineSession::class, 'engine_session_id')->withTrashed();
    }
}
