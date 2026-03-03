/**
 * Singles Manager
 *
 * Intercepts clicks on single sources in the entry element index sidebar and
 * redirects to the globals-like edit page (`singles/{handle}`) instead of the
 * default element index behaviour.
 *
 * Uses capture-phase interception so we run before Craft's element index
 * click handlers, which are attached via Vue and are not removable by simply
 * stripping HTML attributes.
 */
(function () {
    document.addEventListener('click', function (e) {
        var el = e.target && e.target.closest ? e.target.closest('[data-singles-manager-url]') : null;
        if (!el) {
            return;
        }

        var url = el.getAttribute('data-singles-manager-url');
        if (!url) {
            return;
        }

        e.preventDefault();
        e.stopImmediatePropagation();
        window.location.href = url;
    }, true /* capture phase — runs before all bubble-phase / Vue handlers */);
}());

