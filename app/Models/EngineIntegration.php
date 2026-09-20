<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EngineIntegration extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['webhook_secret'];

    protected function casts(): array
    {
        return ['configured_limit' => 'integer', 'maximum_allowed' => 'integer', 'webhook_secret' => 'encrypted', 'webhook_enabled' => 'boolean', 'webhook_last_success_at' => 'datetime', 'webhook_last_error_at' => 'datetime', 'migration_verified_at' => 'datetime', 'last_legacy_request_at' => 'datetime', 'legacy_retired_at' => 'datetime'];
    }

    public function clientApplication(): BelongsTo
    {
        return $this->belongsTo(ClientApplication::class);
    }
}
