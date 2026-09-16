// Visual bot Flow Builder (Drawflow) logic. Kept in an external file so the
// HTML template strings below are not parsed by Livewire's root-element
// detection when this lives inside a Filament page component.
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
            this.seq = Math.max(0, ...Object.keys(data.drawflow.Home.data).map(Number));
            return;
        }

        this.addNode('trigger');
    },

    templates(type) {
        const t = {
            trigger: { in: 0, out: 1, html: `
                <div class="fbn"><div class="fbn-h fbn-trigger">Pemicu</div>
                <label>Tipe</label><select df-trigger_type><option value="keyword">Kata kunci</option><option value="welcome">Sapaan (kontak pertama)</option></select>
                <label>Kata kunci</label><input df-keywords placeholder="halo, menu, mulai"></div>`,
                data: { trigger_type: 'keyword', keywords: 'halo, menu' } },
            message: { in: 1, out: 1, html: `
                <div class="fbn"><div class="fbn-h fbn-message">Pesan</div>
                <textarea df-text placeholder="Isi pesan..."></textarea></div>`,
                data: { text: '' } },
            condition: { in: 1, out: 2, html: `
                <div class="fbn"><div class="fbn-h fbn-condition">Kondisi</div>
                <label>Kata kunci (cocok -> jalur 1)</label><input df-keywords placeholder="beli, pesan">
                <div class="fbn-outs"><span>1 cocok</span><span>2 tidak</span></div></div>`,
                data: { keywords: '' } },
            menu: { in: 1, out: 0, html: `
                <div class="fbn"><div class="fbn-h fbn-menu">Menu</div>
                <label>Header</label><textarea df-header placeholder="Silakan pilih:"></textarea>
                <label>Opsi: key|label|reply/handoff|balasan</label>
                <textarea df-options placeholder="1|Jam operasional|reply|Kami buka 08-17"></textarea>
                <label>Footer</label><input df-footer placeholder="Ketik angka pilihan."></div>`,
                data: { header: 'Silakan pilih:', options: '', footer: '' } },
            ai: { in: 1, out: 1, html: `
                <div class="fbn"><div class="fbn-h fbn-ai">Jawab AI</div>
                <small>Menjawab pertanyaan bebas dari Knowledge Base.</small></div>`,
                data: {} },
            handoff: { in: 1, out: 0, html: `
                <div class="fbn"><div class="fbn-h fbn-handoff">Serahkan ke Agen</div>
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
