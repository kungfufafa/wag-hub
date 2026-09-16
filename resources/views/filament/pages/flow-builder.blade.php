@php $graphs = $this->graphs(); @endphp

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

<script>
    window.flowBuilder = (config) => ({
        editor: null,
        seq: 0,

        init() {
            if (typeof Drawflow === 'undefined') {
                console.error('Drawflow belum termuat.');
                return;
            }
            this.editor = new Drawflow(this.$refs.canvas);
            this.editor.reroute = true;
            this.editor.start();
            this.loadFrom(config.definition);

            this.$wire.on('flow-loaded', (payload) => {
                const def = Array.isArray(payload) ? payload[0]?.definition : payload?.definition;
                this.loadFrom(def);
            });
        },

        loadFrom(def) {
            try {
                this.editor.clear();
            } catch (e) { /* fresh editor */ }

            let data = null;
            try {
                data = (typeof def === 'string' && def !== '') ? JSON.parse(def) : def;
            } catch (e) { data = null; }

            if (data && data.drawflow && Object.keys(data.drawflow.Home?.data || {}).length) {
                this.editor.import(data);
                this.seq = Math.max(0, ...Object.keys(data.drawflow.Home.data).map(Number)) ;
                return;
            }

            // Empty flow: seed a trigger node so the canvas is never blank.
            this.addNode('trigger');
        },

        templates(type) {
            const t = {
                trigger: { in: 0, out: 1, html: `
                    <div class="fbn"><div class="fbn-h fbn-trigger">▶ Pemicu</div>
                    <label>Tipe</label><select df-trigger_type><option value="keyword">Kata kunci</option><option value="welcome">Sapaan (kontak pertama)</option></select>
                    <label>Kata kunci</label><input df-keywords placeholder="halo, menu, mulai"></div>`,
                    data: { trigger_type: 'keyword', keywords: 'halo, menu' } },
                message: { in: 1, out: 1, html: `
                    <div class="fbn"><div class="fbn-h fbn-message">💬 Pesan</div>
                    <textarea df-text placeholder="Isi pesan..."></textarea></div>`,
                    data: { text: '' } },
                condition: { in: 1, out: 2, html: `
                    <div class="fbn"><div class="fbn-h fbn-condition">🔀 Kondisi</div>
                    <label>Kata kunci (cocok → jalur 1)</label><input df-keywords placeholder="beli, pesan">
                    <div class="fbn-outs"><span>1 · cocok</span><span>2 · tidak</span></div></div>`,
                    data: { keywords: '' } },
                menu: { in: 1, out: 0, html: `
                    <div class="fbn"><div class="fbn-h fbn-menu">📋 Menu</div>
                    <label>Header</label><textarea df-header placeholder="Silakan pilih:"></textarea>
                    <label>Opsi — key|label|reply/handoff|balasan</label>
                    <textarea df-options placeholder="1|Jam operasional|reply|Kami buka 08-17&#10;2|Ke agen|handoff|Menghubungkan..."></textarea>
                    <label>Footer</label><input df-footer placeholder="Ketik angka pilihan."></div>`,
                    data: { header: 'Silakan pilih:', options: '', footer: '' } },
                ai: { in: 1, out: 1, html: `
                    <div class="fbn"><div class="fbn-h fbn-ai">🤖 Jawab AI</div>
                    <small>Menjawab pertanyaan bebas dari Knowledge Base.</small></div>`,
                    data: {} },
                handoff: { in: 1, out: 0, html: `
                    <div class="fbn"><div class="fbn-h fbn-handoff">🙋 Serahkan ke Agen</div>
                    <textarea df-message placeholder="Menghubungkan ke agen kami..."></textarea></div>`,
                    data: { message: 'Baik, Anda akan dibantu agen kami sebentar lagi.' } },
            };
            return t[type];
        },

        addNode(type) {
            const tpl = this.templates(type);
            if (!tpl) return;
            const n = this.seq++;
            const x = 40 + (n % 4) * 250;
            const y = 30 + Math.floor(n / 4) * 230;
            this.editor.addNode(type, tpl.in, tpl.out, x, y, type, { ...tpl.data }, tpl.html, false);
        },

        save() {
            const data = this.editor.export();
            this.$wire.set('definition', JSON.stringify(data));
            this.$wire.save();
        },
    });
</script>

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
