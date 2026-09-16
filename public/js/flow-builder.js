// Visual bot Flow Builder (Drawflow). Nodes are compact (icon + title +
// summary); editing happens in a side inspector panel — the n8n pattern.
// Kept external so HTML template strings aren't parsed by Livewire's
// root-element detection inside the Filament page component.
window.flowBuilder = () => ({
    editor: null,
    seq: 0,
    selected: null,
    inspType: null,
    insp: {},
    saving: false,

    meta: {
        trigger: { icon: '\u25B6', title: 'Pemicu', in: 0, out: 1 },
        message: { icon: '\uD83D\uDCAC', title: 'Pesan', in: 1, out: 1 },
        condition: { icon: '\uD83D\uDD00', title: 'Kondisi', in: 1, out: 2 },
        menu: { icon: '\uD83D\uDCCB', title: 'Menu', in: 1, out: 0 },
        ai: { icon: '\uD83E\uDD16', title: 'Jawab AI', in: 1, out: 1 },
        handoff: { icon: '\uD83D\uDE4B', title: 'Ke Agen', in: 1, out: 0 },
    },

    defaults(type) {
        return {
            trigger: { trigger_type: 'keyword', keywords: 'halo, menu' },
            message: { text: '' },
            condition: { keywords: '' },
            menu: { header: 'Silakan pilih:', options: [{ key: '1', label: 'Opsi 1', action: 'reply', reply: '' }], footer: 'Ketik angka pilihan.' },
            ai: {},
            handoff: { message: 'Baik, Anda akan dibantu agen kami sebentar lagi.' },
        }[type];
    },

    init() {
        if (this.editor) return;
        // SPA-safe: Drawflow may not be loaded yet when Alpine runs x-init.
        this.ensureDrawflow().then(() => this.build());
    },

    // Lazy-load the Drawflow lib (CDN) once; resolves when window.Drawflow exists.
    ensureDrawflow() {
        return new Promise((resolve) => {
            if (typeof Drawflow !== 'undefined') return resolve();

            if (!document.getElementById('drawflow-css')) {
                const link = document.createElement('link');
                link.id = 'drawflow-css';
                link.rel = 'stylesheet';
                link.href = 'https://cdn.jsdelivr.net/npm/drawflow@0.0.60/dist/drawflow.min.css';
                document.head.appendChild(link);
            }

            let s = document.getElementById('drawflow-js');
            if (!s) {
                s = document.createElement('script');
                s.id = 'drawflow-js';
                s.src = 'https://cdn.jsdelivr.net/npm/drawflow@0.0.60/dist/drawflow.min.js';
                document.head.appendChild(s);
            }

            const ready = () => (typeof Drawflow !== 'undefined' ? resolve() : setTimeout(ready, 50));
            s.addEventListener('load', ready);
            ready();
        });
    },

    build() {
        if (this.editor || typeof Drawflow === 'undefined') return;

        this.editor = new Drawflow(this.$refs.canvas);
        this.editor.reroute = true;
        this.editor.curvature = 0.5;
        this.editor.start();

        this.editor.on('nodeSelected', (id) => this.onSelect(id));
        this.editor.on('nodeUnselected', () => { this.selected = null; this.inspType = null; });
        this.editor.on('nodeRemoved', () => { this.selected = null; this.inspType = null; });

        this.loadFrom(this.$el.dataset.definition, true);

        this.$wire.on('flow-loaded', (payload) => {
            const def = Array.isArray(payload) ? payload[0]?.definition : payload?.definition;
            this.loadFrom(def, true);
        });
    },

    // ---- summaries & node html -------------------------------------------
    esc(s) { return String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c])); },
    clip(s, n = 46) { s = String(s ?? '').trim(); return s.length > n ? s.slice(0, n) + '…' : (s || '(kosong)'); },

    summary(type, d) {
        switch (type) {
            case 'trigger': return d.trigger_type === 'welcome' ? 'Saat kontak pertama' : 'Kata kunci: ' + (d.keywords || '—');
            case 'message': return this.clip(d.text);
            case 'condition': return 'Jika mengandung: ' + (d.keywords || '—');
            case 'menu': return ((d.options || []).length) + ' opsi';
            case 'ai': return 'Jawab dari Knowledge Base';
            case 'handoff': return this.clip(d.message);
            default: return '';
        }
    },

    nodeHtml(type, d) {
        const m = this.meta[type];
        return `<div class="fbn fbn-${type}"><div class="fbn-top"><span class="fbn-ic">${m.icon}</span><span class="fbn-title">${m.title}</span></div><div class="fbn-sub" data-fbn-sub>${this.esc(this.summary(type, d))}</div></div>`;
    },

    addNode(type) {
        if (!this.meta[type]) return;
        const d = this.defaults(type);
        const m = this.meta[type];
        const n = this.seq++;
        const x = 70 + (n % 3) * 250 + (n % 2) * 20;
        const y = 50 + Math.floor(n / 3) * 170;
        this.editor.addNode(type, m.in, m.out, x, y, type, d, this.nodeHtml(type, d), false);
    },

    // ---- load / persist ---------------------------------------------------
    loadFrom(def, seed) {
        this.selected = null; this.inspType = null; this.seq = 0;

        let data = null;
        try { data = (typeof def === 'string' && def !== '') ? JSON.parse(def) : def; } catch (e) { data = null; }

        const nodes = data?.drawflow?.Home?.data;
        if (nodes && Object.keys(nodes).length) {
            // import() replaces the canvas in a single pass — no clear()-then-import
            // flash, so switching flows stays smooth.
            this.editor.import(data);
            this.seq = Math.max(0, ...Object.keys(nodes).map(Number));
            this.$nextTick(() => this.refreshAll());
            try { this.editor.zoom_reset(); } catch (e) {}
            return;
        }

        try { this.editor.clear(); } catch (e) {}
        if (seed) this.addNode('trigger');
    },

    refreshAll() {
        const nodes = this.editor.export()?.drawflow?.Home?.data || {};
        Object.values(nodes).forEach((n) => this.setSummary(n.id, n.name, n.data || {}));
    },

    setSummary(id, type, data) {
        const el = document.querySelector('#node-' + id + ' [data-fbn-sub]');
        if (el) el.textContent = this.summary(type, data);
    },

    // ---- inspector --------------------------------------------------------
    onSelect(id) {
        const node = this.editor.getNodeFromId(id);
        if (!node) return;
        this.selected = id;
        this.inspType = node.name;
        const d = JSON.parse(JSON.stringify(node.data || {}));
        if (this.inspType === 'menu') d.options = this.normalizeOptions(d.options);
        this.insp = d;
    },

    normalizeOptions(opts) {
        if (Array.isArray(opts)) return opts.map((o) => ({ key: o.key || '', label: o.label || '', action: o.action === 'handoff' ? 'handoff' : 'reply', reply: o.reply || '' }));
        // Legacy "key|label|action|reply" lines.
        return String(opts || '').split(/\r?\n/).map((line) => {
            const p = line.split('|');
            return { key: (p[0] || '').trim(), label: (p[1] || '').trim(), action: (p[2] || 'reply').trim() === 'handoff' ? 'handoff' : 'reply', reply: (p[3] || '').trim() };
        }).filter((o) => o.key || o.label);
    },

    apply() {
        if (this.selected == null) return;
        const d = JSON.parse(JSON.stringify(this.insp));
        this.editor.updateNodeDataFromId(this.selected, d);
        this.setSummary(this.selected, this.inspType, d);
    },

    addOption() {
        if (!Array.isArray(this.insp.options)) this.insp.options = [];
        this.insp.options.push({ key: String(this.insp.options.length + 1), label: '', action: 'reply', reply: '' });
        this.apply();
    },
    removeOption(i) { this.insp.options.splice(i, 1); this.apply(); },

    inspTitle() { return this.inspType ? (this.meta[this.inspType]?.title || '') : ''; },

    // ---- toolbar ----------------------------------------------------------
    removeSelected() {
        if (this.selected == null) return;
        try { this.editor.removeNodeId('node-' + this.selected); } catch (e) {}
        this.selected = null; this.inspType = null;
    },
    clearCanvas() {
        try { this.editor.clear(); } catch (e) {}
        this.seq = 0; this.selected = null; this.inspType = null;
        this.addNode('trigger');
    },
    zoomIn() { try { this.editor.zoom_in(); } catch (e) {} },
    zoomOut() { try { this.editor.zoom_out(); } catch (e) {} },
    zoomReset() { try { this.editor.zoom_reset(); } catch (e) {} },

    save() {
        if (this.saving) return;
        this.saving = true;
        const data = this.editor.export();
        this.$wire.set('definition', JSON.stringify(data));
        const done = () => { this.saving = false; };
        try {
            const p = this.$wire.save();
            (p && typeof p.finally === 'function') ? p.finally(done) : setTimeout(done, 600);
        } catch (e) { done(); }
    },
});
