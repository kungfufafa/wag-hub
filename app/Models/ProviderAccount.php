<?php

namespace App\Models;

use App\Domain\WhatsApp\SessionStatus;
use App\Models\Concerns\GeneratesSlugFromName;
use App\Models\Concerns\ReleasesUniqueIdentifierOnSoftDelete;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProviderAccount extends Model
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
        'driver',
        'configuration',
        'is_active',
        'health_status',
        'session_status',
        'session_meta',
        'session_synced_at',
        'consecutive_failures',
        'circuit_open_until',
        'timeout_seconds',
    ];

    protected $hidden = [
        'configuration',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function uniqueIdentifierColumn(): string
    {
        return 'slug';
    }

    protected function casts(): array
    {
        return [
            'configuration' => 'encrypted:array',
            'is_active' => 'boolean',
            'session_meta' => 'array',
            'session_synced_at' => 'datetime',
            'consecutive_failures' => 'integer',
            'circuit_open_until' => 'datetime',
            'timeout_seconds' => 'integer',
        ];
    }

    /**
     * Whether this account runs on a self-hostable engine that the Hub can
     * pair in-panel (QR/session lifecycle). Only the WAHA driver (which powers
     * our own Baileys/NOWEB engine) exposes a session API today.
     */
    public function supportsSessions(): bool
    {
        return $this->driver === 'waha';
    }

    /**
     * Session created when an app user (HR/CS) links their own number via /engine.
     */
    public function isUserLinkedSession(): bool
    {
        $config = $this->configuration ?? [];

        return filled($config['owned_by_application_id'] ?? null)
            || filled($config['cesa_session_id'] ?? null)
            || str_contains((string) $this->slug, '-sess-');
    }

    public function sessionStatus(): SessionStatus
    {
        return SessionStatus::from($this->session_status ?: SessionStatus::Unknown->value);
    }

    public function routingSteps(): HasMany
    {
        return $this->hasMany(RoutingStep::class);
    }

    public function messageAttempts(): HasMany
    {
        return $this->hasMany(MessageAttempt::class);
    }

    public function acceptedMessages(): HasMany
    {
        return $this->hasMany(GatewayMessage::class, 'accepted_provider_account_id');
    }

    public function numberCheckAttempts(): HasMany
    {
        return $this->hasMany(NumberCheckAttempt::class);
    }

    public function resolvedNumberCheckRequests(): HasMany
    {
        return $this->hasMany(NumberCheckRequest::class, 'resolved_provider_account_id');
    }

    public function inboxConversations(): HasMany
    {
        return $this->hasMany(InboxConversation::class);
    }

    public function autoReplyRules(): HasMany
    {
        return $this->hasMany(AutoReplyRule::class);
    }

    public function botFlows(): HasMany
    {
        return $this->hasMany(BotFlow::class);
    }

    public function knowledgeBaseEntries(): HasMany
    {
        return $this->hasMany(KnowledgeBaseEntry::class);
    }

    public function botGraphs(): HasMany
    {
        return $this->hasMany(BotGraph::class);
    }
}
