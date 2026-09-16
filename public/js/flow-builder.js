// Visual bot Flow Builder (Drawflow) logic. Kept in an external file so the
// HTML template strings below are not parsed by Livewire's root-element
// detection when this lives inside a Filament page component.
window.flowBuilder = (config) => ({
    editor: null,
    seq: 0,
    selected: null,
    ready: false,

    init() {
        // Guard against Alpine re-initialising (the stage is wire:ignore, but
        // be defensive) so nodes never duplicate.
        if (this.editor) return;
        if (typeof Drawflow === 'undefined') {
            console.error('Drawflow belum termuat.');
            return;
        }

        this.editor = new Drawflow(this.$refs.canvas);
        this.editor.reroute = true;
        this.editor.curvature = 0.5;
        this.editor.start();

        this.editor.on('nodeSelected', (id) => { this.selected = id; });
        this.editor.on('nodeUnselected', () => { this.selected = null; });
        this.editor.on('nodeRemoved', () => { this.selected = null; });

        this.loadFrom(config.definition, true);
        this.ready = true;

        this.$wire.on('flow-loaded', (payload) => {
            const def = Array.isArray(payload) ? payload[0]?.definition : payload?.definition;
            this.loadFrom(def, true);
        });
    },

    loadFrom(def, seed) {
        try { this.editor.clear(); } catch (e) { /* fresh editor */ }
        this.selected = null;
        this.seq = 0;

        let data = null;
        try {
            data = (typeof def === 'string' && def !== '') ? JSON.parse(def) : def;
        } catch (e) { data = null; }

        const nodes = data?.drawflow?.Home?.data;
        if (nodes && Object.keys(nodes).length) {
            this.editor.import(data);
            this.seq = Math.max(0, ...Object.keys(nodes).map(Number));
            try { this.editor.zoom_reset(); } catch (e) {}
            return;
        }

        if (seed) this.addNode('trigger');
    },

    templates(type) {
        const t = {
            trigger: { in: 0, out: 1, html: `
                <div class="fbn"><div class="fbn-h fbn-trigger"><span class="fbn-ic">&#9654;</span> Pemicu</div>
                <label>Tipe</label><select df-trigger_type><option value="keyword">Kata kunci</option><option value="welcome">Sapaan (kontak pertama)</option></select>
                <label>Kata kunci</label><input df-keywords placeholder="halo, menu, mulai"></div>`,
                data: { trigger_type: 'keyword', keywords: 'halo, menu' } },
            message: { in: 1, out: 1, html: `
                <div class="fbn"><div class="fbn-h fbn-message"><span class="fbn-ic">&#128172;</span> Pesan</div>
                <textarea df-text placeholder="Isi pesan..."></textarea></div>`,
                data: { text: '' } },
            condition: { in: 1, out: 2, html: `
                <div class="fbn"><div class="fbn-h fbn-condition"><span class="fbn-ic">&#128256;</span> Kondisi</div>
                <label>Kata kunci (cocok &rarr; jalur 1)</label><input df-keywords placeholder="beli, pesan">
                <div class="fbn-outs"><span class="fbn-out-yes">1 &bull; cocok</span><span class="fbn-out-no">2 &bull; tidak</span></div></div>`,
                data: { keywords: '' } },
            menu: { in: 1, out: 0, html: `
                <div class="fbn"><div class="fbn-h fbn-menu"><span class="fbn-ic">&#128203;</span> Menu</div>
                <label>Header</label><textarea df-header placeholder="Silakan pilih:"></textarea>
                <label>Opsi: key|label|reply/handoff|balasan</label>
                <textarea df-options class="fbn-opts" placeholder="1|Jam operasional|reply|Kami buka 08-17"></textarea>
                <label>Footer</label><input df-footer placeholder="Ketik angka pilihan."></div>`,
                data: { header: 'Silakan pilih:', options: '', footer: '' } },
            ai: { in: 1, out: 1, html: `
                <div class="fbn"><div class="fbn-h fbn-ai"><span class="fbn-ic">&#129302;</span> Jawab AI</div>
                <small>Menjawab pertanyaan bebas dari Knowledge Base.</small></div>`,
                data: {} },
            handoff: { in: 1, out: 0, html: `
                <div class="fbn"><div class="fbn-h fbn-handoff"><span class="fbn-ic">&#128587;</span> Serahkan ke Agen</div>
                <textarea df-message placeholder="Menghubungkan ke agen kami..."></textarea></div>`,
                data: { message: 'Baik, Anda akan dibantu agen kami sebentar lagi.' } },
        };
        return t[type];
    },

    addNode(type) {
        const tpl = this.templates(type);
        if (!tpl) return;
        const n = this.seq++;
        // Stagger new nodes so they never stack exactly on top of each other.
        const x = 60 + (n % 3) * 260 + (n % 2) * 20;
        const y = 40 + Math.floor(n / 3) * 210;
        this.editor.addNode(type, tpl.in, tpl.out, x, y, type, { ...tpl.data }, tpl.html, false);
    },

    removeSelected() {
        if (this.selected == null) return;
        try { this.editor.removeNodeId('node-' + this.selected); } catch (e) {}
        this.selected = null;
    },

    clearCanvas() {
        try { this.editor.clear(); } catch (e) {}
        this.seq = 0;
        this.selected = null;
        this.addNode('trigger');
    },

    zoomIn() { try { this.editor.zoom_in(); } catch (e) {} },
    zoomOut() { try { this.editor.zoom_out(); } catch (e) {} },
    zoomReset() { try { this.editor.zoom_reset(); } catch (e) {} },

    save() {
        const data = this.editor.export();
        this.$wire.set('definition', JSON.stringify(data));
        this.$wire.save();
    },
});
