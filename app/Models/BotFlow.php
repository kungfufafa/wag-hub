<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A guided menu flow. When triggered the bot posts {@see renderMenu()} and
 * then interprets the customer's next message against {@see options()}.
 */
class BotFlow extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'uuid',
        'provider_account_id',
        'name',
        'is_active',
        'priority',
        'trigger_type',
        'keywords',
        'header',
        'options',
        'footer',
        'fallback_reply',
        'session_ttl_minutes',
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
            'options' => 'array',
            'session_ttl_minutes' => 'integer',
        ];
    }

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class);
    }

    public function triggeredBy(string $body, bool $isFirstContact): bool
    {
        if ($this->trigger_type === 'welcome') {
            return $isFirstContact;
        }

        $haystack = mb_strtolower(trim($body));

        foreach ($this->keywordList() as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{key: string, label: string, action: string, reply: ?string}>
     */
    public function optionList(): array
    {
        $options = [];

        foreach (is_array($this->options) ? $this->options : [] as $option) {
            if (! is_array($option)) {
                continue;
            }

            $key = trim((string) ($option['key'] ?? ''));
            $label = trim((string) ($option['label'] ?? ''));

            if ($key === '' || $label === '') {
                continue;
            }

            $options[] = [
                'key' => $key,
                'label' => $label,
                'action' => in_array($option['action'] ?? 'reply', ['reply', 'handoff'], true)
                    ? (string) $option['action']
                    : 'reply',
                'reply' => isset($option['reply']) ? (string) $option['reply'] : null,
            ];
        }

        return $options;
    }

    /**
     * Match a customer's message to an option by its key or label.
     *
     * @return array{key: string, label: string, action: string, reply: ?string}|null
     */
    public function matchOption(string $input): ?array
    {
        $needle = mb_strtolower(trim($input));

        foreach ($this->optionList() as $option) {
            if ($needle === mb_strtolower($option['key'])
                || $needle === mb_strtolower($option['label'])) {
                return $option;
            }
        }

        return null;
    }

    public function renderMenu(): string
    {
        $lines = [trim((string) $this->header)];
        $lines[] = '';

        foreach ($this->optionList() as $option) {
            $lines[] = '*'.$option['key'].'.* '.$option['label'];
        }

        if (filled($this->footer)) {
            $lines[] = '';
            $lines[] = trim((string) $this->footer);
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @return list<string>
     */
    private function keywordList(): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $keyword): string => mb_strtolower(trim((string) $keyword)),
            is_array($this->keywords) ? $this->keywords : [],
        ), static fn (string $keyword): bool => $keyword !== ''));
    }
}
