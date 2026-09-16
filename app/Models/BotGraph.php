<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A node-based bot logic graph built in the visual Flow Builder. The
 * {@see $definition} is the canvas export consumed by {@see \App\Services\BotGraphEngine}.
 */
class BotGraph extends Model
{
    use HasFactory;
    use HasUuids;

    protected $fillable = [
        'uuid',
        'provider_account_id',
        'name',
        'is_active',
        'priority',
        'definition',
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
            'definition' => 'array',
        ];
    }

    public function providerAccount(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class);
    }

    /**
     * Normalised node map from the canvas export, keyed by node id:
     * [ id => { type, data:[], outputs: [outputKey => targetId] } ].
     *
     * @return array<string, array{type: string, data: array<string, mixed>, outputs: array<string, string>}>
     */
    public function nodes(): array
    {
        $raw = data_get($this->definition, 'drawflow.Home.data', []);

        if (! is_array($raw)) {
            return [];
        }

        $nodes = [];

        foreach ($raw as $id => $node) {
            if (! is_array($node)) {
                continue;
            }

            $outputs = [];

            foreach ($node['outputs'] ?? [] as $outputKey => $output) {
                $target = data_get($output, 'connections.0.node');

                if ($target !== null) {
                    $outputs[(string) $outputKey] = (string) $target;
                }
            }

            $nodes[(string) $id] = [
                'type' => (string) ($node['name'] ?? ''),
                'data' => is_array($node['data'] ?? null) ? $node['data'] : [],
                'outputs' => $outputs,
            ];
        }

        return $nodes;
    }

    /**
     * The id of the entry (trigger) node, if any.
     */
    public function triggerNodeId(): ?string
    {
        foreach ($this->nodes() as $id => $node) {
            if ($node['type'] === 'trigger') {
                return $id;
            }
        }

        return null;
    }
}
