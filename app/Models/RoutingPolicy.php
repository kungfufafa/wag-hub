<?php

namespace App\Models;

use App\Models\Concerns\ReleasesUniqueIdentifierOnSoftDelete;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RoutingPolicy extends Model
{
    use HasFactory;
    use HasUuids;
    use ReleasesUniqueIdentifierOnSoftDelete;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'client_application_id',
        'operation',
        'name',
        'key',
        'purpose',
        'is_default',
        'is_active',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function uniqueIdentifierColumn(): string
    {
        return 'key';
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function clientApplication(): BelongsTo
    {
        return $this->belongsTo(ClientApplication::class)->withTrashed();
    }

    public function steps(): HasMany
    {
        return $this->hasMany(RoutingStep::class)->orderBy('position');
    }

    public function gatewayMessages(): HasMany
    {
        return $this->hasMany(GatewayMessage::class);
    }

    public function numberCheckRequests(): HasMany
    {
        return $this->hasMany(NumberCheckRequest::class);
    }
}
