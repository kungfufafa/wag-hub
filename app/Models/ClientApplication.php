<?php

namespace App\Models;

use App\Models\Concerns\GeneratesSlugFromName;
use App\Models\Concerns\ReleasesUniqueIdentifierOnSoftDelete;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ClientApplication extends Model
{
    use GeneratesSlugFromName;
    use HasFactory;
    use HasUuids;
    use ReleasesUniqueIdentifierOnSoftDelete;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'is_active',
        'rate_limit_per_minute',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected static function booted(): void
    {
        static::deleting(function (ClientApplication $application): void {
            if ($application->isForceDeleting()) {
                return;
            }

            $application->apiCredentials()
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $application->routingPolicies
                ->each(fn (RoutingPolicy $policy) => $policy->delete());
        });
    }

    protected function uniqueIdentifierColumn(): string
    {
        return 'slug';
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'rate_limit_per_minute' => 'integer',
        ];
    }

    public function apiCredentials(): HasMany
    {
        return $this->hasMany(ApiCredential::class);
    }

    public function routingPolicies(): HasMany
    {
        return $this->hasMany(RoutingPolicy::class);
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
