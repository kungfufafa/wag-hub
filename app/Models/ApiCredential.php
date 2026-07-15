<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiCredential extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'uuid',
        'client_application_id',
        'name',
        'token_hash',
        'token_prefix',
        'abilities',
        'last_used_at',
        'expires_at',
        'revoked_at',
    ];

    protected $hidden = [
        'token_hash',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'abilities' => 'encrypted:array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Create a credential while keeping the plaintext token outside persistence.
     *
     * @param  list<string>  $abilities
     */
    public static function issue(
        ClientApplication $application,
        string $name,
        array $abilities,
    ): IssuedApiCredential {
        $plainTextToken = 'wgh_'.Str::random(64);
        $credential = static::query()->create([
            'client_application_id' => $application->getKey(),
            'name' => $name,
            'token_hash' => hash('sha256', $plainTextToken),
            'token_prefix' => substr($plainTextToken, 0, 16),
            'abilities' => array_values(array_unique($abilities)),
        ]);

        return new IssuedApiCredential($credential, $plainTextToken);
    }

    public function clientApplication(): BelongsTo
    {
        return $this->belongsTo(ClientApplication::class)->withTrashed();
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && (bool) $this->clientApplication?->is_active;
    }

    public function hasAbility(string $ability): bool
    {
        return in_array($ability, $this->abilities ?? [], true);
    }
}
