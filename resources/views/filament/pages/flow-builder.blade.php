<x-filament-panels::page>
@php $graphs = $this->graphs(); @endphp
{{-- Single wrapper so the page slot has exactly one root element. --}}
<div class="fb-root">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/drawflow@0.0.60/dist/drawflow.min.css">
<script src="https://cdn.jsdelivr.net/npm/drawflow@0.0.60/dist/drawflow.min.js"></script>
<script src="{{ asset('js/flow-builder.js') }}"></script>

{{-- Toolbar in a native Filament section. --}}
<x-filament::section>
    <div class="fb-bar">
        <label class="fb-field">
            <span>Akun / nomor</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="providerId">
                    <option value="">Pilih akun</option>
                    @foreach ($this->providers() as $provider)
                        <option value="{{ $provider->id }}">{{ $provider->name }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
        <label class="fb-field fb-grow">
            <span>Nama flow</span>
            <x-filament::input.wrapper>
                <x-filament::input type="text" wire:model.blur="graphName" placeholder="Contoh: Alur CS utama" />
            </x-filament::input.wrapper>
        </label>
        <label class="fb-check">
            <x-filament::input.checkbox wire:model="graphActive" /> Aktif
        </label>
        <x-filament::button type="button" color="gray" icon="heroicon-o-plus" wire:click="newGraph">Flow baru</x-filament::button>
        @if ($this->graphId)
            <x-filament::button type="button" color="danger" icon="heroicon-o-trash" wire:click="deleteGraph" wire:confirm="Hapus flow ini?">Hapus</x-filament::button>
        @endif
    </div>

    @if ($graphs->isNotEmpty())
        <div class="fb-chips">
            <span class="fb-chips-label">Flow tersimpan:</span>
            @foreach ($graphs as $graph)
                <button type="button" wire:click="selectGraph({{ $graph->id }})"
                        class="fb-chip @if ($this->graphId === $graph->id) is-active @endif">
                    {{ $graph->name }}@unless ($graph->is_active) <em>(off)</em>@endunless
                </button>
            @endforeach
        </div>
    @endif
</x-filament::section>

{{-- Canvas in a native Filament section (padding neutralised for full-bleed). --}}
<x-filament::section>
    <div class="fb-stage" wire:ignore x-data="flowBuilder()" x-init="init()" data-definition="{{ $this->definition }}">
        <div class="fb-canvas-toolbar">
            <span class="fb-group-label">Tambah</span>
            <x-filament::button type="button" size="xs" color="gray" x-on:click="addNode('trigger')">▶ Pemicu</x-filament::button>
            <x-filament::button type="button" size="xs" color="gray" x-on:click="addNode('message')">💬 Pesan</x-filament::button>
            <x-filament::button type="button" size="xs" color="gray" x-on:click="addNode('condition')">🔀 Kondisi</x-filament::button>
            <x-filament::button type="button" size="xs" color="gray" x-on:click="addNode('menu')">📋 Menu</x-filament::button>
            <x-filament::button type="button" size="xs" color="gray" x-on:click="addNode('ai')">🤖 AI</x-filament::button>
            <x-filament::button type="button" size="xs" color="gray" x-on:click="addNode('handoff')">🙋 Agen</x-filament::button>

            <span class="fb-spacer"></span>

            <x-filament::button type="button" size="xs" color="gray" x-on:click="zoomOut()">−</x-filament::button>
            <x-filament::button type="button" size="xs" color="gray" x-on:click="zoomReset()">Reset</x-filament::button>
            <x-filament::button type="button" size="xs" color="gray" x-on:click="zoomIn()">+</x-filament::button>
            <x-filament::button type="button" size="xs" color="gray" icon="heroicon-o-trash" x-bind:disabled="selected===null" x-on:click="removeSelected()">Hapus node</x-filament::button>
            <x-filament::button type="button" size="xs" color="gray" x-on:click="clearCanvas()">Bersihkan</x-filament::button>
            <x-filament::button type="button" size="xs" color="primary" icon="heroicon-o-check" x-on:click="save()">Simpan</x-filament::button>
        </div>

        <div class="fb-workarea">
            <div class="fb-canvas"><div id="drawflow" x-ref="canvas"></div></div>

            <aside class="fb-inspector">
                <template x-if="selected === null">
                    <div class="fb-insp-empty">
                        <div class="fb-insp-empty-ic">⚙️</div>
                        <p>Klik sebuah node untuk mengedit propertinya di sini.</p>
                    </div>
                </template>

                <div x-show="selected !== null" x-cloak>
                    <h3 class="fb-insp-title" x-text="inspTitle()"></h3>

                    <div x-show="inspType === 'trigger'" class="fb-insp-body">
                        <label class="fb-insp-field"><span>Tipe pemicu</span>
                            <select x-model="insp.trigger_type" @change="apply()" class="fb-input">
                                <option value="keyword">Kata kunci</option>
                                <option value="welcome">Sapaan (kontak pertama)</option>
                            </select>
                        </label>
                        <label class="fb-insp-field" x-show="insp.trigger_type === 'keyword'"><span>Kata kunci (pisah koma)</span>
                            <input type="text" x-model="insp.keywords" @input="apply()" class="fb-input" placeholder="halo, menu, mulai">
                        </label>
                    </div>

                    <div x-show="inspType === 'message'" class="fb-insp-body">
                        <label class="fb-insp-field"><span>Isi pesan</span>
                            <textarea x-model="insp.text" @input="apply()" rows="5" class="fb-input"></textarea>
                        </label>
                    </div>

                    <div x-show="inspType === 'condition'" class="fb-insp-body">
                        <label class="fb-insp-field"><span>Kata kunci — jika cocok, ambil jalur 1</span>
                            <input type="text" x-model="insp.keywords" @input="apply()" class="fb-input" placeholder="beli, pesan, order">
                        </label>
                        <p class="fb-insp-note">Jalur <b>1 · cocok</b> dan <b>2 · tidak</b> ada di sisi kanan node.</p>
                    </div>

                    <div x-show="inspType === 'menu'" class="fb-insp-body">
                        <label class="fb-insp-field"><span>Teks pembuka</span>
                            <textarea x-model="insp.header" @input="apply()" rows="2" class="fb-input"></textarea>
                        </label>
                        <div class="fb-insp-field">
                            <span>Pilihan</span>
                            <template x-for="(opt, i) in insp.options" :key="i">
                                <div class="fb-opt">
                                    <div class="fb-opt-row">
                                        <input type="text" x-model="opt.key" @input="apply()" class="fb-input fb-opt-key" placeholder="1">
                                        <input type="text" x-model="opt.label" @input="apply()" class="fb-input fb-opt-label" placeholder="Judul opsi">
                                        <button type="button" class="fb-opt-del" @click="removeOption(i)" title="Hapus opsi">×</button>
                                    </div>
                                    <select x-model="opt.action" @change="apply()" class="fb-input">
                                        <option value="reply">Balas teks</option>
                                        <option value="handoff">Serahkan ke agen</option>
                                    </select>
                                    <textarea x-model="opt.reply" @input="apply()" rows="2" class="fb-input" x-bind:placeholder="opt.action === 'handoff' ? 'Pesan sebelum diserahkan' : 'Isi balasan'"></textarea>
                                </div>
                            </template>
                            <button type="button" class="fb-opt-add" @click="addOption()">+ Tambah opsi</button>
                        </div>
                        <label class="fb-insp-field"><span>Teks penutup</span>
                            <input type="text" x-model="insp.footer" @input="apply()" class="fb-input" placeholder="Ketik angka pilihan.">
                        </label>
                    </div>

                    <div x-show="inspType === 'ai'" class="fb-insp-body">
                        <p class="fb-insp-note">Node ini menjawab pertanyaan bebas pelanggan dari <b>Knowledge Base</b> akun ini. Kelola isinya di menu <b>Knowledge Base (AI)</b>.</p>
                    </div>

                    <div x-show="inspType === 'handoff'" class="fb-insp-body">
                        <label class="fb-insp-field"><span>Pesan saat diserahkan ke agen</span>
                            <textarea x-model="insp.message" @input="apply()" rows="4" class="fb-input"></textarea>
                        </label>
                    </div>
                </div>
            </aside>
        </div>

        <p class="fb-hint">Seret dari titik <b>output</b> (kanan) sebuah node ke titik <b>input</b> (kiri) node lain untuk menyambungkan. Klik node untuk mengeditnya, lalu <b>Simpan</b>.</p>
    </div>
</x-filament::section>

<style>
    [x-cloak] { display: none !important; }
    .fb-root { display: flex; flex-direction: column; gap: 1.5rem; }

    /* Toolbar layout inside the native section. */
    .fb-bar { display: flex; gap: 0.75rem; align-items: flex-end; flex-wrap: wrap; }
    .fb-field { display: grid; gap: 0.25rem; font-size: 0.75rem; font-weight: 500; color: var(--gray-500); }
    .fb-field.fb-grow { flex: 1; min-width: 12rem; }
    .fb-check { display: flex; align-items: center; gap: 0.4rem; font-size: 0.85rem; min-height: 2.25rem; }
    .fb-chips { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; margin-top: 0.85rem; padding-top: 0.85rem; border-top: 1px solid var(--gray-100); }
    .dark .fb-chips { border-top-color: rgb(255 255 255 / 0.08); }
    .fb-chips-label { font-size: 0.75rem; color: var(--gray-500); }
    .fb-chip { padding: 0.3rem 0.7rem; border-radius: 999px; border: 1px solid var(--gray-300); background: transparent; font-size: 0.8rem; cursor: pointer; color: var(--gray-700); }
    .dark .fb-chip { border-color: var(--gray-700); color: var(--gray-300); }
    .fb-chip.is-active { border-color: var(--primary-600); color: var(--primary-600); font-weight: 600; background: color-mix(in oklab, var(--primary-500) 10%, transparent); }

    /* Full-bleed canvas inside the section. */
    .fi-section:has(.fb-stage) .fi-section-content-ctn,
    .fi-section:has(.fb-stage) .fi-section-content { padding: 0; overflow: hidden; }
    .fb-stage { display: flex; flex-direction: column; }
    .fb-canvas-toolbar { display: flex; align-items: center; gap: 0.4rem; flex-wrap: wrap; padding: 0.65rem 0.85rem; border-bottom: 1px solid var(--gray-100); }
    .dark .fb-canvas-toolbar { border-bottom-color: rgb(255 255 255 / 0.08); }
    .fb-group-label { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--gray-500); }
    .fb-spacer { flex: 1; }

    .fb-workarea { display: grid; grid-template-columns: minmax(0, 1fr) 20rem; }
    @media (max-width: 900px) { .fb-workarea { grid-template-columns: 1fr; } }
    .fb-canvas { position: relative; }
    #drawflow { width: 100%; height: 70vh; min-height: 30rem; background-color: var(--gray-50, #f8fafc); background-image: radial-gradient(var(--gray-300, #cbd5e1) 1.2px, transparent 1.2px); background-size: 20px 20px; }
    .dark #drawflow { background-color: #0b1220; background-image: radial-gradient(#334155 1.2px, transparent 1.2px); }

    .fb-inspector { border-left: 1px solid var(--gray-100); padding: 1.1rem; overflow-y: auto; max-height: 70vh; }
    .dark .fb-inspector { border-left-color: rgb(255 255 255 / 0.08); }
    @media (max-width: 900px) { .fb-inspector { border-left: 0; border-top: 1px solid var(--gray-100); max-height: none; } }
    .fb-insp-empty { display: grid; place-items: center; text-align: center; gap: 0.5rem; height: 100%; color: var(--gray-500); padding: 2rem 0.5rem; }
    .fb-insp-empty-ic { font-size: 1.6rem; opacity: 0.7; }
    .fb-insp-title { font-size: 0.95rem; font-weight: 700; color: var(--gray-950); margin-bottom: 0.85rem; }
    .dark .fb-insp-title { color: #fff; }
    .fb-insp-body { display: grid; gap: 0.8rem; }
    .fb-insp-field { display: grid; gap: 0.3rem; font-size: 0.72rem; font-weight: 600; color: var(--gray-500); }
    .fb-insp-note { font-size: 0.78rem; color: var(--gray-500); line-height: 1.5; }

    /* Inputs matched to Filament controls. */
    .fb-input { width: 100%; min-height: 2.25rem; border: 1px solid var(--gray-300); border-radius: 0.5rem; padding: 0.4rem 0.6rem; background: var(--mky-surface, #fff); color: var(--gray-950); font-size: 0.8rem; box-shadow: 0 1px 2px 0 rgb(0 0 0 / 0.05); }
    .fb-input:focus { outline: none; border-color: var(--primary-500); box-shadow: 0 0 0 1px var(--primary-500); }
    .dark .fb-input { border-color: var(--gray-700); background: rgb(255 255 255 / 0.05); color: var(--gray-100); box-shadow: none; }

    .fb-opt { border: 1px solid var(--gray-200); border-radius: 0.6rem; padding: 0.55rem; display: grid; gap: 0.45rem; margin-bottom: 0.45rem; background: var(--gray-50); }
    .dark .fb-opt { border-color: var(--gray-700); background: rgb(255 255 255 / 0.03); }
    .fb-opt-row { display: flex; gap: 0.4rem; align-items: center; }
    .fb-opt-key { max-width: 3.2rem; text-align: center; }
    .fb-opt-label { flex: 1; }
    .fb-opt-del { width: 2rem; height: 2rem; flex: none; border: 1px solid var(--gray-300); border-radius: 0.5rem; background: #fee2e2; color: #991b1b; cursor: pointer; font-size: 1rem; line-height: 1; }
    .dark .fb-opt-del { border-color: var(--gray-700); background: rgb(153 27 27 / 0.25); color: #fca5a5; }
    .fb-opt-add { margin-top: 0.1rem; padding: 0.45rem 0.7rem; border-radius: 0.5rem; border: 1px dashed var(--gray-300); background: transparent; font-size: 0.8rem; font-weight: 600; cursor: pointer; color: var(--primary-600); }
    .dark .fb-opt-add { border-color: var(--gray-600); }

    .fb-hint { padding: 0.6rem 0.85rem; font-size: 0.75rem; color: var(--gray-500); border-top: 1px solid var(--gray-100); }
    .dark .fb-hint { border-top-color: rgb(255 255 255 / 0.08); }

    /* Compact node cards. */
    .drawflow .drawflow-node { border-radius: 0.7rem; border: 1px solid #d7dee8; box-shadow: 0 3px 10px rgb(15 23 42 / 0.10); padding: 0; width: 190px; background: #fff; }
    .dark .drawflow .drawflow-node { border-color: #334155; background: #111a2b; }
    .drawflow .drawflow-node.selected { background: #fff; border-color: var(--primary-500, #059669); box-shadow: 0 0 0 2px var(--primary-500, #059669), 0 4px 14px rgb(15 23 42 / 0.16); }
    .dark .drawflow .drawflow-node.selected { background: #111a2b; }
    .fbn { font-size: 0.8rem; }
    .fbn-top { display: flex; align-items: center; gap: 0.4rem; padding: 0.5rem 0.65rem; border-top-left-radius: 0.7rem; border-top-right-radius: 0.7rem; color: #fff; font-weight: 700; }
    .fbn-ic { font-size: 0.9rem; line-height: 1; }
    .fbn-title { font-size: 0.82rem; }
    .fbn-sub { padding: 0.5rem 0.65rem; color: #475569; font-size: 0.75rem; line-height: 1.35; word-break: break-word; }
    .dark .fbn-sub { color: #cbd5e1; }
    .fbn-trigger .fbn-top { background: #059669; }
    .fbn-message .fbn-top { background: #2563eb; }
    .fbn-condition .fbn-top { background: #d97706; }
    .fbn-menu .fbn-top { background: #7c3aed; }
    .fbn-ai .fbn-top { background: #0891b2; }
    .fbn-handoff .fbn-top { background: #db2777; }

    .drawflow .connection .main-path { stroke: #94a3b8; stroke-width: 2.5px; }
    .drawflow .drawflow-node .input, .drawflow .drawflow-node .output { height: 14px; width: 14px; border: 2px solid #94a3b8; background: #fff; }
    .drawflow .drawflow-node .output:hover, .drawflow .drawflow-node .input:hover { border-color: var(--primary-600, #059669); background: var(--primary-600, #059669); }
</style>
</div>{{-- /fb-root --}}
</x-filament-panels::page>
