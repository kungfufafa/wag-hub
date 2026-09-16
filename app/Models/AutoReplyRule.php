<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-provider automated reply. The AutoResponder evaluates a provider's
 * active rules (ordered by priority) against each inbound message and sends
 * the first match back through the normal delivery pipeline.
 */
class AutoReplyRule extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'uuid',
        'provider_account_id',
        'name',
        'is_active',
        'priority',
        'match_type',
        'match_mode',
        'keywords',
        'reply_body',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'priority' => 'integer',
            'keywords' => 'array',
        ];
    }

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class);
    }

    /**
     * Decide whether this rule matches an inbound message.
     */
    public function matches(string $body, bool $isFirstContact): bool
    {
        return match ($this->match_type) {
            'welcome' => $isFirstContact,
            'fallback' => true,
            'keyword' => $this->matchesKeyword($body),
            default => false,
        };
    }

    private function matchesKeyword(string $body): bool
    {
        $keywords = array_filter(array_map(
            static fn (mixed $keyword): string => mb_strtolower(trim((string) $keyword)),
            is_array($this->keywords) ? $this->keywords : [],
        ), static fn (string $keyword): bool => $keyword !== '');

        if ($keywords === []) {
            return false;
        }

        $haystack = mb_strtolower(trim($body));
        $exact = $this->match_mode === 'exact';

        foreach ($keywords as $keyword) {
            if ($exact ? $haystack === $keyword : str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
