<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EngineMedia extends Model
{
    use HasUuids;

    protected $table = 'engine_media';

    protected $guarded = [];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'size' => 'integer'];
    }
}
