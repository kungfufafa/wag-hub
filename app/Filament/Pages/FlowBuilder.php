<?php

namespace App\Filament\Pages;

use App\Models\BotGraph;
use App\Models\ProviderAccount;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * n8n-style visual builder for bot logic graphs. The canvas (Drawflow) lives
 * client-side; this page handles selecting, loading, and saving the graph
 * definition consumed by {@see \App\Services\BotGraphEngine}.
 */
class FlowBuilder extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|UnitEnum|null $navigationGroup = 'Automasi';

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationLabel = 'Flow Builder';

    protected static ?string $title = 'Flow Builder';

    protected static ?string $slug = 'flow-builder';

    protected string $view = 'filament.pages.flow-builder';

    protected Width|string|null $maxContentWidth = Width::Full;

    public ?int $providerId = null;

    public ?int $graphId = null;

    public string $graphName = '';

    public bool $graphActive = true;

    public string $definition = '';

    public function mount(): void
    {
        $this->providerId = $this->providers()->first()?->id;
    }

    /**
     * @return Collection<int, ProviderAccount>
     */
    public function providers(): Collection
    {
        return ProviderAccount::query()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, BotGraph>
     */
    public function graphs(): Collection
    {
        if ($this->providerId === null) {
            return collect();
        }

        return BotGraph::query()
            ->where('provider_account_id', $this->providerId)
            ->orderBy('name')
            ->get();
    }

    public function updatedProviderId(): void
    {
        $this->newGraph();
    }

    public function selectGraph(int $id): void
    {
        $graph = BotGraph::query()->find($id);

        if ($graph === null) {
            return;
        }

        $this->graphId = $graph->id;
        $this->providerId = $graph->provider_account_id;
        $this->graphName = $graph->name;
        $this->graphActive = (bool) $graph->is_active;
        $this->definition = json_encode($graph->definition ?: new \stdClass) ?: '';

        $this->dispatch('flow-loaded', definition: $this->definition);
    }

    public function newGraph(): void
    {
        $this->graphId = null;
        $this->graphName = '';
        $this->graphActive = true;
        $this->definition = '';

        $this->dispatch('flow-loaded', definition: '');
    }

    public function save(): void
    {
        $this->validate([
            'providerId' => ['required', 'integer'],
            'graphName' => ['required', 'string', 'max:120'],
            'definition' => ['required', 'string'],
        ], [
            'graphName.required' => 'Beri nama flow dulu.',
            'providerId.required' => 'Pilih akun provider dulu.',
        ]);

        $definition = json_decode($this->definition, true);

        $graph = BotGraph::query()->updateOrCreate(
            ['id' => $this->graphId],
            [
                'provider_account_id' => $this->providerId,
                'name' => $this->graphName,
                'is_active' => $this->graphActive,
                'definition' => is_array($definition) ? $definition : null,
            ],
        );

        $this->graphId = $graph->id;

        Notification::make()->title('Flow disimpan.')->success()->send();
    }

    public function deleteGraph(): void
    {
        if ($this->graphId !== null) {
            BotGraph::query()->whereKey($this->graphId)->delete();
            Notification::make()->title('Flow dihapus.')->success()->send();
        }

        $this->newGraph();
    }

    public function getSubheading(): ?string
    {
        return 'Bangun logika bot secara visual (ala n8n): tarik hubungkan node Pemicu → Kondisi → Pesan → Menu → AI → Agen.';
    }
}
