/**
 * The framed side of the iframe embed (ADR 2026-11-05-embeddable-admin,
 * protocol v1), for Escalated's wp-admin screens. Loaded only on a screen
 * requested with escalated_embed=1 while iframe mode is on, so the host can
 * use connectEscalatedFrame() from @escalated-dev/escalated/embed unchanged.
 *
 * It talks only to the parent window, and only when the parent's origin is
 * in escalatedAdmin.embed.allowed_origins. Otherwise it does nothing.
 * Every wp-admin screen is a full page load, so the first screen sends
 * `ready` and each later one (loaded from inside the frame) sends `navigate`.
 */
(function () {
    'use strict';

    var config = window.escalatedAdmin && window.escalatedAdmin.embed;
    if (!config || !config.enabled || window.parent === window) {
        return;
    }

    function toOrigin(value) {
        if (typeof value !== 'string' || value === '' || value === '*' || value === 'null') return null;
        try {
            var url = new URL(value);
            if (url.protocol !== 'https:' && url.protocol !== 'http:') return null;
            return url.origin;
        } catch (e) {
            return null;
        }
    }

    var allowed = (config.allowed_origins || []).map(toOrigin).filter(Boolean);
    var ancestors = window.location.ancestorOrigins;
    var parentOrigin = ancestors && ancestors.length ? toOrigin(ancestors[0]) : toOrigin(document.referrer);
    if (!parentOrigin || allowed.indexOf(parentOrigin) === -1) {
        return;
    }

    function post(type, data) {
        var message = { source: 'escalated', version: 1, type: type };
        for (var key in data) {
            if (Object.prototype.hasOwnProperty.call(data, key)) message[key] = data[key];
        }
        window.parent.postMessage(message, parentOrigin);
    }

    function state() {
        return {
            url: window.location.pathname + window.location.search,
            route: typeof config.route === 'string' ? config.route : null,
            title: document.title || '',
        };
    }

    /** A same-origin Escalated screen under base_url (no scheme, no //, no ..). */
    function isScreenUnder(url, base) {
        if (typeof url !== 'string' || url.charAt(0) !== '/' || url.indexOf('//') === 0 || url.indexOf('\\') !== -1) return false;
        var parts = url.split('#')[0].split('?');
        if (parts[0] !== base || parts[0].split('/').indexOf('..') !== -1) return false;
        var page = new URLSearchParams(parts[1] || '').get('page') || '';
        return page === 'escalated' || page.indexOf('escalated-') === 0;
    }

    function wrap() {
        return document.querySelector('.escalated-wrap') || document.querySelector('#wpbody-content > .wrap') || document.body;
    }

    window.addEventListener('message', function (event) {
        if (event.source !== window.parent || event.origin !== parentOrigin) return;
        var data = event.data;
        if (!data || typeof data !== 'object' || data.source !== 'escalated-host' || data.version !== 1) return;

        if (data.type === 'theme' && (data.mode === 'light' || data.mode === 'dark')) {
            var el = wrap();
            el.classList.remove('escalated-dark', 'escalated-light');
            el.classList.add(data.mode === 'dark' ? 'escalated-dark' : 'escalated-light');
            if (data.tokens && typeof data.tokens === 'object') {
                Object.keys(data.tokens).forEach(function (name) {
                    if (name.indexOf('--') === 0 && typeof data.tokens[name] === 'string') {
                        el.style.setProperty(name, data.tokens[name]);
                    }
                });
            }
        } else if (data.type === 'navigate' && isScreenUnder(data.url, config.base_url)) {
            var target = new URL(data.url, window.location.origin);
            target.searchParams.set('escalated_embed', '1');
            window.location.assign(target.pathname + target.search + target.hash);
        }
    });

    var lastHeight = -1;
    function reportHeight() {
        var height = Math.ceil(document.body.scrollHeight || 0);
        if (height !== lastHeight) {
            lastHeight = height;
            post('resize', { height: height });
        }
    }

    function start() {
        var fromFrame = toOrigin(document.referrer) === window.location.origin;
        post(fromFrame ? 'navigate' : 'ready', state());
        reportHeight();
        if (typeof window.ResizeObserver === 'function') {
            new window.ResizeObserver(reportHeight).observe(document.body);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
