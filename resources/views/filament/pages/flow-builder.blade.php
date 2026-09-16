<x-filament-panels::page>
@php $graphs = $this->graphs(); @endphp
{{-- Single wrapper so the page slot has exactly one root element. --}}
<div class="fb-root">
{{-- Drawflow (visual node editor) + builder logic. --}}
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/drawflow@0.0.60/dist/drawflow.min.css">
<script src="https://cdn.jsdelivr.net/npm/drawflow@0.0.60/dist/drawflow.min.js"></script>
<script src="{{ asset('js/flow-builder.js') }}"></script>

{{-- Reactive toolbar (Livewire): choose account, name the flow, pick a saved flow. --}}
<div class="fb-bar">
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
        <input type="text" wire:model.blur="graphName" placeholder="Contoh: Alur CS utama" class="fb-input">
    </label>
    <label class="fb-check"><input type="checkbox" wire:model="graphActive"> Aktif</label>
    <button type="button" class="fb-btn" wire:click="newGraph">+ Flow baru</button>
    @if ($this->graphId)
        <button type="button" class="fb-btn fb-btn-danger" wire:click="deleteGraph" wire:confirm="Hapus flow ini?">Hapus flow</button>
    @endif

    @if ($graphs->isNotEmpty())
        <div class="fb-chips">
            @foreach ($graphs as $graph)
                <button type="button" wire:click="selectGraph({{ $graph->id }})"
                        class="fb-chip @if ($this->graphId === $graph->id) is-active @endif">
                    {{ $graph->name }}@unless ($graph->is_active) <em>(off)</em>@endunless
                </button>
            @endforeach
        </div>
    @endif
</div>

{{-- Canvas stage (wire:ignore so Alpine/Drawflow init once — no duplicate nodes). --}}
<div class="fb-stage" wire:ignore x-data="flowBuilder({ definition: @js($this->definition) })" x-init="init()">
    <div class="fb-canvas-toolbar">
        <span class="fb-group-label">Tambah node</span>
        <button type="button" class="fb-node-btn fb-n-trigger" @click="addNode('trigger')">▶ Pemicu</button>
        <button type="button" class="fb-node-btn fb-n-message" @click="addNode('message')">💬 Pesan</button>
        <button type="button" class="fb-node-btn fb-n-condition" @click="addNode('condition')">🔀 Kondisi</button>
        <button type="button" class="fb-node-btn fb-n-menu" @click="addNode('menu')">📋 Menu</button>
        <button type="button" class="fb-node-btn fb-n-ai" @click="addNode('ai')">🤖 Jawab AI</button>
        <button type="button" class="fb-node-btn fb-n-handoff" @click="addNode('handoff')">🙋 Ke Agen</button>

        <span class="fb-spacer"></span>

        <div class="fb-zoom">
            <button type="button" @click="zoomOut()" title="Perkecil">−</button>
            <button type="button" @click="zoomReset()" title="Reset zoom">⤢</button>
            <button type="button" @click="zoomIn()" title="Perbesar">+</button>
        </div>
        <button type="button" class="fb-tool-btn" @click="removeSelected()" title="Hapus node terpilih">🗑 Hapus node</button>
        <button type="button" class="fb-tool-btn" @click="clearCanvas()" title="Bersihkan kanvas">Bersihkan</button>
        <button type="button" class="fb-tool-btn fb-tool-save" @click="save()">💾 Simpan</button>
    </div>

    <div class="fb-canvas">
        <div id="drawflow" x-ref="canvas"></div>
    </div>

    <p class="fb-hint">Seret dari titik <b>output</b> sebuah node ke titik <b>input</b> node lain untuk menyambungkan. Klik node lalu <b>Hapus node</b> untuk menghapus. Geser latar untuk menggeser kanvas.</p>
</div>

<style>
    .fb-root { display: flex; flex-direction: column; gap: 0.75rem; }

    .fb-bar { display: flex; gap: 0.6rem; align-items: flex-end; flex-wrap: wrap; padding: 0.85rem; border: 1px solid var(--mky-border, #e5e7eb); border-radius: 0.75rem; background: var(--mky-surface, #fff); }
    .fb-field { display: grid; gap: 0.2rem; font-size: 0.75rem; color: var(--gray-500); }
    .fb-field.fb-grow { flex: 1; min-width: 12rem; }
    .fb-input { min-height: 2.5rem; border: 1px solid var(--gray-300); border-radius: 0.5rem; padding: 0.4rem 0.6rem; background: var(--mky-surface, #fff); color: var(--gray-950); font-size: 0.875rem; }
    .fb-input:focus { outline: none; border-color: var(--primary-500); box-shadow: 0 0 0 1px var(--primary-500); }
    .dark .fb-input { border-color: var(--gray-700); background: rgb(255 255 255 / 0.05); color: var(--gray-100); }
    .fb-check { display: flex; align-items: center; gap: 0.35rem; font-size: 0.85rem; min-height: 2.5rem; }
    .fb-btn { min-height: 2.5rem; padding: 0 0.9rem; border-radius: 0.5rem; border: 1px solid var(--gray-300); background: var(--mky-surface, #fff); font-size: 0.85rem; font-weight: 600; cursor: pointer; }
    .dark .fb-btn { border-color: var(--gray-700); background: rgb(255 255 255 / 0.05); color: var(--gray-100); }
    .fb-btn-danger { background: #fee2e2; border-color: #fecaca; color: #991b1b; }
    .fb-chips { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; flex-basis: 100%; margin-top: 0.15rem; }
    .fb-chip { padding: 0.3rem 0.7rem; border-radius: 999px; border: 1px solid var(--gray-300); background: var(--mky-surface, #fff); font-size: 0.8rem; cursor: pointer; }
    .dark .fb-chip { border-color: var(--gray-700); background: rgb(255 255 255 / 0.05); color: var(--gray-100); }
    .fb-chip.is-active { border-color: var(--primary-600); box-shadow: 0 0 0 1px var(--primary-600); color: var(--primary-600); font-weight: 600; }

    .fb-stage { border: 1px solid var(--mky-border, #e5e7eb); border-radius: 0.75rem; overflow: hidden; background: var(--mky-surface, #fff); }
    .fb-canvas-toolbar { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; padding: 0.55rem 0.7rem; border-bottom: 1px solid var(--mky-border, #e5e7eb); background: var(--gray-50); }
    .dark .fb-canvas-toolbar { background: rgb(255 255 255 / 0.03); }
    .fb-group-label { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--gray-500); margin-right: 0.15rem; }
    .fb-spacer { flex: 1; }
    .fb-node-btn { padding: 0.4rem 0.6rem; border-radius: 0.5rem; border: 1px solid var(--gray-200); background: var(--mky-surface, #fff); font-size: 0.8rem; font-weight: 600; cursor: pointer; white-space: nowrap; }
    .dark .fb-node-btn { border-color: var(--gray-700); background: rgb(255 255 255 / 0.05); color: var(--gray-100); }
    .fb-node-btn:hover { border-color: var(--primary-500); }
    .fb-zoom { display: inline-flex; border: 1px solid var(--gray-300); border-radius: 0.5rem; overflow: hidden; }
    .dark .fb-zoom { border-color: var(--gray-700); }
    .fb-zoom button { min-width: 2.1rem; height: 2.1rem; border: 0; background: var(--mky-surface, #fff); font-size: 1rem; cursor: pointer; }
    .fb-zoom button + button { border-left: 1px solid var(--gray-200); }
    .dark .fb-zoom button { background: rgb(255 255 255 / 0.05); color: var(--gray-100); }
    .fb-tool-btn { padding: 0.4rem 0.7rem; border-radius: 0.5rem; border: 1px solid var(--gray-300); background: var(--mky-surface, #fff); font-size: 0.8rem; font-weight: 600; cursor: pointer; }
    .dark .fb-tool-btn { border-color: var(--gray-700); background: rgb(255 255 255 / 0.05); color: var(--gray-100); }
    .fb-tool-save { background: var(--primary-600); border-color: var(--primary-600); color: #fff; }

    .fb-canvas { position: relative; }
    #drawflow { width: 100%; height: 74vh; min-height: 30rem; background-color: #f8fafc; background-image: radial-gradient(#cbd5e1 1.2px, transparent 1.2px); background-size: 20px 20px; }
    .dark #drawflow { background-color: #0b1220; background-image: radial-gradient(#1f2937 1.2px, transparent 1.2px); }
    .fb-hint { padding: 0.55rem 0.7rem; font-size: 0.75rem; color: var(--gray-500); border-top: 1px solid var(--mky-border, #e5e7eb); }

    /* Node cards */
    .drawflow .drawflow-node { border-radius: 0.7rem; border: 1px solid #e5e7eb; box-shadow: 0 2px 8px rgb(0 0 0 / 0.08); padding: 0; width: 210px; background: #fff; }
    .drawflow .drawflow-node.selected { box-shadow: 0 0 0 2px var(--primary-500, #059669), 0 2px 10px rgb(0 0 0 / 0.12); }
    .fbn { font-size: 0.78rem; color: #111827; padding: 0 0.6rem 0.6rem; }
    .fbn label { display: block; margin-top: 0.4rem; font-size: 0.66rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; color: #9ca3af; }
    .fbn input, .fbn textarea, .fbn select { width: 100%; margin-top: 0.15rem; border: 1px solid #e5e7eb; border-radius: 0.4rem; padding: 0.32rem 0.45rem; font-size: 0.76rem; background: #fff; color: #111827; }
    .fbn textarea { min-height: 2.6rem; resize: vertical; }
    .fbn textarea.fbn-opts { min-height: 4.2rem; font-family: ui-monospace, monospace; font-size: 0.72rem; }
    .fbn small { display: block; margin-top: 0.35rem; color: #6b7280; }
    .fbn-h { display: flex; align-items: center; gap: 0.35rem; margin: 0 -0.6rem 0.15rem; padding: 0.45rem 0.6rem; border-top-left-radius: 0.7rem; border-top-right-radius: 0.7rem; font-weight: 700; color: #fff; font-size: 0.82rem; }
    .fbn-ic { font-size: 0.85rem; }
    .fbn-outs { display: flex; justify-content: space-between; margin-top: 0.5rem; font-size: 0.66rem; font-weight: 600; }
    .fbn-out-yes { color: #059669; }
    .fbn-out-no { color: #9ca3af; }
    .fbn-trigger { background: #059669; }
    .fbn-message { background: #2563eb; }
    .fbn-condition { background: #d97706; }
    .fbn-menu { background: #7c3aed; }
    .fbn-ai { background: #0891b2; }
    .fbn-handoff { background: #db2777; }
    /* Connection ports & lines */
    .drawflow .connection .main-path { stroke: #94a3b8; stroke-width: 2.5px; }
    .drawflow .drawflow-node .input, .drawflow .drawflow-node .output { height: 14px; width: 14px; border: 2px solid #94a3b8; background: #fff; }
    .drawflow .drawflow-node .output:hover, .drawflow .drawflow-node .input:hover { border-color: var(--primary-600, #059669); }
</style>
</div>{{-- /fb-root --}}
</x-filament-panels::page>
