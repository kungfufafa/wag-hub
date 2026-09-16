// WAHA-compatible mock engine for local development and automated demos of the
// Gateway Hub self-hosted pairing flow. It implements the subset of the WAHA
// API the Hub uses (session lifecycle, QR, sendText, chat pulls) and fires
// webhooks back to the Hub — WITHOUT connecting to real WhatsApp. Not for prod.
import express from 'express';
import QRCode from 'qrcode';

const app = express();
app.use(express.json({ limit: '5mb' }));

const PORT = process.env.PORT || 3999;
const sessions = new Map();

function getOrCreate(name) {
  if (!sessions.has(name)) {
    sessions.set(name, { name, status: 'STOPPED', me: null, webhooks: [] });
  }
  return sessions.get(name);
}

function doc(s) {
  return { name: s.name, status: s.status, me: s.me, engine: { engine: 'NOWEB' } };
}

async function fireWebhook(s, event, payload) {
  for (const wh of s.webhooks) {
    if (Array.isArray(wh.events) && wh.events.length && !wh.events.includes(event)) continue;
    try {
      await fetch(wh.url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ event, session: s.name, payload }),
      });
      console.log(`[mock] webhook ${event} -> ${wh.url}`);
    } catch (e) {
      console.log(`[mock] webhook ${event} failed: ${e.message}`);
    }
  }
}

// Opt-in: auto-"scan" the QR after N ms to make hands-free demos self-driving.
const AUTOSCAN_MS = parseInt(process.env.MOCK_AUTOSCAN_MS || '0', 10);

function toScanQr(s, delay = 700) {
  s.status = 'STARTING';
  fireWebhook(s, 'session.status', { name: s.name, status: 'STARTING' });
  setTimeout(() => {
    s.status = 'SCAN_QR_CODE';
    fireWebhook(s, 'session.status', { name: s.name, status: 'SCAN_QR_CODE' });
    console.log(`[mock] session ${s.name} -> SCAN_QR_CODE`);
    if (AUTOSCAN_MS > 0) {
      setTimeout(() => {
        if (s.status === 'SCAN_QR_CODE') simulateScan(s);
      }, AUTOSCAN_MS);
    }
  }, delay);
}

function simulateScan(s, phone = '6281200000001', from = '6289900000002') {
  s.status = 'WORKING';
  s.me = { id: `${phone}@c.us`, pushName: 'Bisnis Demo' };
  fireWebhook(s, 'session.status', { name: s.name, status: 'WORKING', me: s.me });
  fireWebhook(s, 'message', {
    id: `false_${from}@c.us_${Date.now().toString(16)}`,
    timestamp: Math.floor(Date.now() / 1000),
    from: `${from}@c.us`,
    fromMe: false,
    body: 'Halo, apakah pesanan saya sudah dikirim?',
    hasMedia: false,
    notifyName: 'Pelanggan Demo',
  });
  console.log(`[mock] session ${s.name} -> WORKING (scanned)`);
}

// --- Session lifecycle -----------------------------------------------------
app.post('/api/sessions', (req, res) => {
  const name = req.body?.name || 'default';
  const s = getOrCreate(name);
  s.webhooks = req.body?.config?.webhooks || s.webhooks;
  if (req.body?.start) toScanQr(s);
  res.status(201).json(doc(s));
});

app.post('/api/sessions/:name/start', (req, res) => {
  const s = getOrCreate(req.params.name);
  if (s.status !== 'WORKING') toScanQr(s);
  res.json(doc(s));
});

app.post('/api/sessions/:name/stop', (req, res) => {
  const s = getOrCreate(req.params.name);
  s.status = 'STOPPED';
  fireWebhook(s, 'session.status', { name: s.name, status: 'STOPPED' });
  res.json(doc(s));
});

app.post('/api/sessions/:name/logout', (req, res) => {
  const s = getOrCreate(req.params.name);
  s.status = 'STOPPED';
  s.me = null;
  fireWebhook(s, 'session.status', { name: s.name, status: 'STOPPED' });
  res.json(doc(s));
});

app.post('/api/sessions/:name/restart', (req, res) => {
  const s = getOrCreate(req.params.name);
  toScanQr(s);
  res.json(doc(s));
});

app.get('/api/sessions/:name', (req, res) => {
  res.json(doc(getOrCreate(req.params.name)));
});

// --- QR --------------------------------------------------------------------
app.get('/api/:name/auth/qr', async (req, res) => {
  const s = getOrCreate(req.params.name);
  if (s.status !== 'SCAN_QR_CODE') {
    return res.status(422).json({ error: 'not_in_scan_state', status: s.status });
  }
  // A fresh, realistic-looking (but non-functional) pairing payload.
  const payload = `2@${Math.random().toString(36).slice(2)},${Date.now()}`;
  const buffer = await QRCode.toBuffer(payload, { width: 512, margin: 2 });
  res.set('Content-Type', 'image/png').send(buffer);
});

// --- Outbound send (used by the WAHA driver) -------------------------------
app.post('/api/sendText', (req, res) => {
  const chatId = req.body?.chatId || 'unknown';
  console.log(`[mock] sendText -> ${chatId}: ${req.body?.text ?? ''}`);
  res.status(201).json({
    id: `true_${chatId}_${Date.now().toString(16).toUpperCase()}`,
    timestamp: Math.floor(Date.now() / 1000),
    from: 'me',
    to: chatId,
  });
});

// --- Inbox pull (return empty; the Hub is webhook-driven here) --------------
app.get('/api/:name/chats/overview', (_req, res) => res.json([]));
app.get('/api/:name/chats/:chatId/messages', (_req, res) => res.json([]));

// --- Demo helpers (not part of WAHA) ---------------------------------------
// Simulate a phone scanning the QR: session becomes WORKING and an inbound
// message arrives so a conversation shows up in the Hub inbox.
app.post('/_mock/scan/:name', (req, res) => {
  const s = getOrCreate(req.params.name);
  const phone = (req.query.phone || '6281200000001').toString().replace(/\D+/g, '');
  const from = (req.body?.from || '6289900000002').toString().replace(/\D+/g, '');
  simulateScan(s, phone, from);
  res.json(doc(s));
});

app.get('/', (_req, res) => {
  res.json({ mock: 'waha', sessions: [...sessions.values()].map(doc) });
});

app.listen(PORT, () => console.log(`[mock] WAHA-compatible engine on :${PORT}`));
