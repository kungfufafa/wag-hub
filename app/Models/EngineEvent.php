<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EngineEvent extends Model
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
            'data' => 'encrypted:array', 'revision' => 'integer', 'delivery_attempts' => 'integer',
            'occurred_at' => 'datetime', 'expires_at' => 'datetime', 'next_attempt_at' => 'datetime',
            'delivery_locked_until' => 'datetime', 'delivered_at' => 'datetime', 'failed_at' => 'datetime',
        ];
    }
}
