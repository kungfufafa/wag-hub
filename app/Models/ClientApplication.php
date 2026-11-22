<?php

namespace App\Models;

use App\Models\Concerns\GeneratesSlugFromName;
use App\Models\Concerns\ReleasesUniqueIdentifierOnSoftDelete;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

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
        'website_url',
        'logo_path',
        'is_active',
        'rate_limit_per_minute',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * Disk and folder for uploaded application logos.
     */
    public const LOGO_DISK = 'public';

    public const LOGO_DIRECTORY = 'client-applications/logos';

    protected static function booted(): void
    {
        // A replaced or cleared logo leaves no orphaned file behind.
        static::updated(function (ClientApplication $application): void {
            $previous = $application->getOriginal('logo_path');

            if ($application->wasChanged('logo_path') && filled($previous)) {
                Storage::disk(self::LOGO_DISK)->delete($previous);
            }
        });

        // Soft-deleted applications keep their logo for history; only a force delete removes it.
        static::forceDeleted(function (ClientApplication $application): void {
            if (filled($application->logo_path)) {
                Storage::disk(self::LOGO_DISK)->delete($application->logo_path);
            }
        });

        static::deleting(function (ClientApplication $application): void {
            if ($application->isForceDeleting()) {
                return;
            }

            $application->apiCredentials()
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            $application->routingPolicies
                ->each(fn (RoutingPolicy $policy) => $policy->delete());

            $application->whatsappConnections
                ->each(fn (WhatsAppConnection $connection) => $connection->delete());
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

    public function logoUrl(): ?string
    {
        return filled($this->logo_path)
            ? Storage::disk(self::LOGO_DISK)->url($this->logo_path)
            : null;
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

    public function whatsappConnections(): HasMany
    {
        return $this->hasMany(WhatsAppConnection::class);
    }
}
