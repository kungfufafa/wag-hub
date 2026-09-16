<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A knowledge-base entry the AI agent can answer from (per provider account).
 */
class KnowledgeBaseEntry extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'uuid',
        'provider_account_id',
        'title',
        'content',
        'keywords',
        'is_active',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    protected function casts(): array
    {
        return [
            'keywords' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class);
    }

    /**
     * Lower-cased text used for retrieval scoring (title + keywords weigh more).
     */
    public function searchableText(): string
    {
        $keywords = implode(' ', is_array($this->keywords) ? $this->keywords : []);

        // Title and keywords repeated so matches there score higher.
        return mb_strtolower(trim(
            $this->title.' '.$this->title.' '.$keywords.' '.$keywords.' '.$this->content,
        ));
    }
}
