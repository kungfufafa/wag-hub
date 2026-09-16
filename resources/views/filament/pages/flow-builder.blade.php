<x-filament-panels::page>
@php $graphs = $this->graphs(); @endphp
{{-- Single wrapper so the page slot has exactly one root element. --}}
<div class="fb-root">
{{-- Drawflow (visual node editor) from CDN. --}}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/drawflow@0.0.60/dist/drawflow.min.css">
<script src="https://cdn.jsdelivr.net/npm/drawflow@0.0.60/dist/drawflow.min.js"></script>

<div class="fb" x-data="flowBuilder({ definition: @js($this->definition) })" x-init="init()">
    <div class="fb-toolbar">
        <div class="fb-toolbar-row">
            <label class="fb-field">
                <span>Akun / nomor</span>
                <select wire:model.live="providerId" class="fb-input">
                    <option value="">Pilih akun</option>
                    @foreach ($this->providers() as $provider)
                        <option value="{{ $provider->id }}">{{ $provider->name }}</option>
                    @endforeach
                </select>
            </label>

            <label class="fb-field fb-grow">
                <span>Nama flow</span>
                <input type="text" wire:model="graphName" placeholder="Contoh: Alur CS utama" class="fb-input">
            </label>

            <label class="fb-check">
                <input type="checkbox" wire:model="graphActive"> Aktif
            </label>

            <button type="button" class="fb-btn fb-btn-primary" @click="save()">Simpan</button>
            <button type="button" class="fb-btn" wire:click="newGraph">Flow baru</button>
            @if ($this->graphId)
                <button type="button" class="fb-btn fb-btn-danger" wire:click="deleteGraph"
                        wire:confirm="Hapus flow ini?">Hapus</button>
            @endif
        </div>

        @if ($graphs->isNotEmpty())
            <div class="fb-chips">
                <span class="fb-chips-label">Flow tersimpan:</span>
                @foreach ($graphs as $graph)
                    <button type="button"
                            wire:click="selectGraph({{ $graph->id }})"
                            class="fb-chip @if ($this->graphId === $graph->id) is-active @endif">
                        {{ $graph->name }}@unless ($graph->is_active) <em>(nonaktif)</em> @endunless
                    </button>
                @endforeach
            </div>
        @endif
    </div>

    <div class="fb-body">
        <div class="fb-palette">
            <span class="fb-palette-label">Tambah node</span>
            <button type="button" class="fb-node-btn fb-n-trigger" @click="addNode('trigger')">▶ Pemicu</button>
            <button type="button" class="fb-node-btn fb-n-message" @click="addNode('message')">💬 Pesan</button>
            <button type="button" class="fb-node-btn fb-n-condition" @click="addNode('condition')">🔀 Kondisi</button>
            <button type="button" class="fb-node-btn fb-n-menu" @click="addNode('menu')">📋 Menu</button>
            <button type="button" class="fb-node-btn fb-n-ai" @click="addNode('ai')">🤖 Jawab AI</button>
            <button type="button" class="fb-node-btn fb-n-handoff" @click="addNode('handoff')">🙋 Ke Agen</button>
            <p class="fb-hint">Seret titik output ke input node lain untuk menyambungkan. Klik kanan node untuk hapus.</p>
        </div>

        <div class="fb-canvas" wire:ignore>
            <div id="drawflow" x-ref="canvas"></div>
        </div>
    </div>
</div>

<script src="{{ asset('js/flow-builder.js') }}"></script>

<style>
    .fb { display: flex; flex-direction: column; gap: 0.75rem; }
    .fb-toolbar { display: flex; flex-direction: column; gap: 0.6rem; padding: 0.85rem; border: 1px solid var(--mky-border, #e5e7eb); border-radius: 0.75rem; background: var(--mky-surface, #fff); }
    .fb-toolbar-row { display: flex; gap: 0.6rem; align-items: flex-end; flex-wrap: wrap; }
    .fb-field { display: grid; gap: 0.2rem; font-size: 0.78rem; color: var(--gray-500); }
    .fb-field.fb-grow { flex: 1; min-width: 12rem; }
    .fb-input { min-height: 2.5rem; border: 1px solid var(--gray-300); border-radius: 0.5rem; padding: 0.4rem 0.6rem; background: var(--mky-surface, #fff); color: var(--gray-950); font-size: 0.875rem; }
    .dark .fb-input { border-color: var(--gray-700); background: rgb(255 255 255 / 0.05); color: var(--gray-100); }
    .fb-check { display: flex; align-items: center; gap: 0.35rem; font-size: 0.85rem; }
    .fb-btn { min-height: 2.5rem; padding: 0 0.9rem; border-radius: 0.5rem; border: 1px solid var(--gray-300); background: var(--mky-surface, #fff); font-size: 0.85rem; font-weight: 600; cursor: pointer; }
    .dark .fb-btn { border-color: var(--gray-700); background: rgb(255 255 255 / 0.05); color: var(--gray-100); }
    .fb-btn-primary { background: var(--primary-600); border-color: var(--primary-600); color: #fff; }
    .fb-btn-danger { background: #fee2e2; border-color: #fecaca; color: #991b1b; }
    .fb-chips { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; }
    .fb-chips-label { font-size: 0.78rem; color: var(--gray-500); }
    .fb-chip { padding: 0.3rem 0.6rem; border-radius: 999px; border: 1px solid var(--gray-300); background: var(--mky-surface, #fff); font-size: 0.8rem; cursor: pointer; }
    .dark .fb-chip { border-color: var(--gray-700); background: rgb(255 255 255 / 0.05); color: var(--gray-100); }
    .fb-chip.is-active { border-color: var(--primary-600); box-shadow: 0 0 0 1px var(--primary-600); }
    .fb-body { display: grid; grid-template-columns: 12rem minmax(0, 1fr); gap: 0.75rem; }
    @media (max-width: 820px) { .fb-body { grid-template-columns: 1fr; } }
    .fb-palette { display: flex; flex-direction: column; gap: 0.4rem; padding: 0.75rem; border: 1px solid var(--mky-border, #e5e7eb); border-radius: 0.75rem; background: var(--mky-surface, #fff); align-content: start; }
    .fb-palette-label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--gray-500); }
    .fb-node-btn { text-align: left; padding: 0.5rem 0.6rem; border-radius: 0.5rem; border: 1px solid var(--gray-200); background: var(--gray-50); font-size: 0.82rem; font-weight: 600; cursor: pointer; }
    .dark .fb-node-btn { border-color: var(--gray-700); background: rgb(255 255 255 / 0.05); color: var(--gray-100); }
    .fb-hint { margin-top: 0.4rem; font-size: 0.72rem; color: var(--gray-500); line-height: 1.5; }
    .fb-canvas { border: 1px solid var(--mky-border, #e5e7eb); border-radius: 0.75rem; overflow: hidden; background: #f8fafc; }
    #drawflow { width: 100%; height: 70vh; background-image: radial-gradient(#d1d5db 1px, transparent 1px); background-size: 22px 22px; }

    /* Node cards */
    .fbn { min-width: 190px; font-size: 0.78rem; color: #111827; }
    .fbn label { display: block; margin-top: 0.35rem; font-size: 0.68rem; color: #6b7280; }
    .fbn input, .fbn textarea, .fbn select { width: 100%; margin-top: 0.1rem; border: 1px solid #e5e7eb; border-radius: 0.4rem; padding: 0.3rem 0.4rem; font-size: 0.76rem; background: #fff; }
    .fbn textarea { min-height: 3rem; resize: vertical; }
    .fbn-h { margin: -0.2rem -0.2rem 0.3rem; padding: 0.35rem 0.5rem; border-radius: 0.4rem; font-weight: 700; color: #fff; font-size: 0.8rem; }
    .fbn-outs { display: flex; justify-content: space-between; margin-top: 0.4rem; font-size: 0.68rem; color: #6b7280; }
    .fbn-trigger { background: #059669; }
    .fbn-message { background: #2563eb; }
    .fbn-condition { background: #d97706; }
    .fbn-menu { background: #7c3aed; }
    .fbn-ai { background: #0891b2; }
    .fbn-handoff { background: #db2777; }
    .drawflow .drawflow-node { border-radius: 0.6rem; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgb(0 0 0 / 0.1); padding: 0.5rem; }
</style>
</div>{{-- /fb-root --}}
</x-filament-panels::page>
