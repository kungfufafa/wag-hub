// Sets up Laravel Echo over Reverb for the admin panel so Livewire components
// (e.g. the Inbox) can react to broadcast events in real time. Config comes
// from FilamentAsset::registerScriptData -> window.filamentData.reverb.
(function () {
    function cfg() {
        return (window.filamentData && window.filamentData.reverb) || null;
    }

    function boot() {
        const c = cfg();
        if (!c || !c.key) return;
        if (typeof window.Pusher === 'undefined' || typeof window.Echo === 'undefined') return;
        if (window.__echoBooted) return;

        // laravel-echo's IIFE build exposes the class on window.Echo; swap it
        // for a configured instance (what Livewire expects).
        const EchoClass = window.Echo;
        try {
            window.Echo = new EchoClass({
                broadcaster: 'reverb',
                key: c.key,
                wsHost: c.host,
                wsPort: c.port,
                wssPort: c.port,
                forceTLS: c.scheme === 'https',
                enabledTransports: ['ws', 'wss'],
            });
            window.__echoBooted = true;
        } catch (e) {
            window.Echo = EchoClass;
        }
    }

    // window.Pusher is required by the reverb/pusher broadcaster.
    if (typeof window.Pusher !== 'undefined') { /* provided by pusher CDN */ }

    let tries = 0;
    (function wait() {
        if (window.__echoBooted) return;
        if (typeof window.Pusher !== 'undefined' && typeof window.Echo !== 'undefined') {
            boot();
            return;
        }
        if (tries++ < 100) setTimeout(wait, 50);
    })();
})();
