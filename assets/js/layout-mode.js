/**
 * Layout chosen from the header icons (PC / tablet / phone), loaded in <head>
 * before the page draws. Kept on this device (localStorage 'layout-mode');
 * none chosen = automatic, by the screen size.
 *
 * - On a phone or tablet the page's virtual width is changed (viewport), so
 *   the chosen layout applies for real: 'desktop' = 1280 px, 'tablet' =
 *   900 px, 'phone' = the phone's own width (420 px on a tablet).
 * - On a PC the browser ignores that, so 'tablet' / 'phone' open the page
 *   inside a frame of that size (/layout-preview.php: the page in an iframe
 *   has its own width, so its own layout).
 */
(function () {
    var KEY = 'layout-mode';
    var mode = null;
    try { mode = localStorage.getItem(KEY); } catch (e) {}
    if (['desktop', 'tablet', 'phone'].indexOf(mode) < 0) mode = null;
    var inFrame = window.self !== window.top;
    var touch = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;

    window.layoutMode = mode;
    window.setLayoutMode = function (m) {
        try {
            if (!m || m === mode) localStorage.removeItem(KEY); else localStorage.setItem(KEY, m);
        } catch (e) {}
        // Start this page over, full window, with the new layout.
        if (inFrame) window.top.location.href = window.location.href; else window.location.reload();
    };

    if (inFrame || !mode) return;

    if (touch) {
        var small = Math.min(screen.width, screen.height) < 600;   // a phone
        var width = mode === 'desktop' ? '1280' : mode === 'tablet' ? '900' : (small ? 'device-width' : '420');
        var vp = document.querySelector('meta[name=viewport]');
        if (vp) vp.setAttribute('content', 'width=' + width + (width === 'device-width' ? ', initial-scale=1.0' : ''));
        return;
    }

    // PC, tablet or phone layout: show this page in the device frame.
    if (mode !== 'desktop') {
        window.stop();
        window.location.replace('/layout-preview.php?u=' + encodeURIComponent(location.pathname + location.search + location.hash));
    }
})();
