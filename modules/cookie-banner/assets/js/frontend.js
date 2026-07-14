(function () {
    'use strict';

    var COOKIE  = 'dpt_consent';
    var LS_KEY  = 'dpt_consent_v1';
    var config  = window.DPT_CB_CONFIG || {};

    // Debug mode: enable by adding ?dpt_debug=1 to the URL.
    var DEBUG = /[?&]dpt_debug=1/.test(location.search);
    var debugLog = [];
    function log(label, data) {
        var entry = '[' + new Date().toISOString().substr(11, 12) + '] ' + label;
        if (data !== undefined) {
            try { entry += ' → ' + JSON.stringify(data); } catch (e) { entry += ' → [unserializable]'; }
        }
        debugLog.push(entry);
        if (DEBUG && window.console && console.log) console.log('[DPTCB]', label, data);
        renderDebugPanel();
    }

    /* ============== Storage ============== */

    function getCookieDomain() {
        var host = location.hostname;
        if (!host || host === 'localhost' || /^\d+\.\d+\.\d+\.\d+$/.test(host)) return '';
        var parts = host.split('.');
        if (parts.length < 2) return '';

        // Public suffixes that need 3 parts (instead of 2) to form a valid domain.
        // .co.il, .co.uk, .com.au, .co.za, .com.br etc.
        var publicSuffixes = ['co', 'com', 'org', 'net', 'gov', 'edu', 'ac'];
        var lastTwo = parts.slice(-2).join('.');
        var secondLast = parts[parts.length - 2];

        // If the second-last label is a known suffix (co, com, org, etc.) AND
        // we have at least 3 parts, use the last 3 labels.
        if (parts.length >= 3 && publicSuffixes.indexOf(secondLast) !== -1) {
            return '.' + parts.slice(-3).join('.');
        }
        // Otherwise use the last 2 (for .com, .org, etc.).
        return '.' + lastTwo;
    }

    function setCookie(name, value, days) {
        try {
            var d = new Date();
            d.setTime(d.getTime() + (days * 24 * 60 * 60 * 1000));
            var secure = location.protocol === 'https:' ? '; secure' : '';
            var base = name + '=' + encodeURIComponent(value) +
                '; expires=' + d.toUTCString() +
                '; path=/' +
                '; samesite=lax' + secure;
            var domain = getCookieDomain();
            if (domain) {
                document.cookie = base + '; domain=' + domain;
            }
            // Verify the write LANDED (value comparison, not mere presence -
            // a stale pre-existing cookie must not mask a rejected write):
            // if the guessed domain is actually a public suffix (e.g. .or.jp
            // is not in our short list) the browser silently rejects it -
            // fall back to a host-only cookie so the server can still read
            // the consent.
            if (getCookie(name) !== value) {
                document.cookie = base;
                log('cookie domain write not verified, host-only fallback', domain);
            }
        } catch (e) { log('cookie set failed', e.message); }
    }

    function getCookie(name) {
        try {
            var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
            return m ? decodeURIComponent(m[1]) : null;
        } catch (e) { return null; }
    }

    function lsGet(key) { try { return localStorage.getItem(key); } catch (e) { return null; } }
    function lsSet(key, value) { try { localStorage.setItem(key, value); } catch (e) {} }
    function lsRemove(key) { try { localStorage.removeItem(key); } catch (e) {} }

    function isCurrentVersion(consent) {
        var expected = String(config.consentVersion || '1');
        return String((consent && consent.v) || '') === expected;
    }

    // The "Consent lifetime (days)" setting must hold even when the consent
    // survives in localStorage after the cookie expired - otherwise the
    // localStorage copy would silently mint a fresh cookie forever.
    function isExpired(consent) {
        var days = parseInt(config.consentDays, 10) || 180;
        if (!consent || !consent.ts) return true;
        return (Date.now() - consent.ts) > days * 864e5;
    }

    // Parse one stored copy and validate it: well-formed, current
    // consent_version, within the consent lifetime. Returns null otherwise.
    function parseValidConsent(raw) {
        if (!raw) return null;
        try {
            var parsed = JSON.parse(raw);
            if (!parsed || typeof parsed !== 'object') return null;
            // Consent stored for an older consent_version: ignore it, so the
            // banner asks again after the admin bumps the version.
            if (!isCurrentVersion(parsed)) return null;
            if (isExpired(parsed)) return null;
            return parsed;
        } catch (e) { return null; }
    }

    function readConsent() {
        // Validate each store independently - a malformed/stale cookie must
        // not shadow a valid localStorage copy (or vice versa).
        var fromCookie    = getCookie(COOKIE);
        var fromLs        = lsGet(LS_KEY);
        var cookieConsent = parseValidConsent(fromCookie);
        var lsConsent     = parseValidConsent(fromLs);
        var consent       = cookieConsent || lsConsent;

        if (!consent) {
            // Nothing valid anywhere - drop a dead localStorage copy so it
            // can't linger forever (the cookie expires on its own).
            if (fromLs) lsRemove(LS_KEY);
            return null;
        }

        // Cross-heal: rewrite whichever side is missing or invalid.
        var serialized = JSON.stringify(consent);
        if (!cookieConsent) setCookie(COOKIE, serialized, config.consentDays || 180);
        if (!lsConsent) lsSet(LS_KEY, serialized);
        return consent;
    }

    function saveConsent(consent) {
        var serialized = JSON.stringify(consent);
        var days = config.consentDays || 180;
        setCookie(COOKIE, serialized, days);
        lsSet(LS_KEY, serialized);
        log('consent saved', consent);
    }

    /* ============== Script injection (cache-proof) ==============
     * With script blocking on, consented snippets are injected ONLY here,
     * based on this visitor's own consent. The server never bakes them into
     * the HTML, because full-page caches may serve that HTML to visitors
     * who did not consent.
     */

    var injectedCats = { functional: false, analytics: false, marketing: false };

    // Insert admin-provided HTML so its <script> tags actually execute
    // (nodes added via innerHTML never run). Nodes are processed through a
    // sequential queue: an external <script src> must finish loading before
    // the next node runs, so snippets like "load library + inline init"
    // keep working exactly like they do when parsed from static HTML.
    var injectQueue = [];
    var injectWaiting = false;

    function processInjectQueue() {
        while (!injectWaiting && injectQueue.length) {
            var node = injectQueue.shift();
            var target = document.head || document.body || document.documentElement;
            if (node.nodeName === 'SCRIPT') {
                var s = document.createElement('script');
                for (var i = 0; i < node.attributes.length; i++) {
                    s.setAttribute(node.attributes[i].name, node.attributes[i].value);
                }
                s.text = node.text || node.textContent || '';
                if (s.src) {
                    injectWaiting = true;
                    var proceed = function () {
                        injectWaiting = false;
                        processInjectQueue();
                    };
                    s.onload = proceed;
                    s.onerror = proceed;
                    target.appendChild(s);
                    return; // resume from onload/onerror
                }
                target.appendChild(s);
            } else {
                target.appendChild(node);
            }
        }
    }

    function appendExecutable(html) {
        var tpl = document.createElement('template');
        tpl.innerHTML = html;
        var nodes = tpl.content ? tpl.content.childNodes : tpl.childNodes;
        injectQueue = injectQueue.concat(Array.prototype.slice.call(nodes));
        processInjectQueue();
    }

    function injectConsentedScripts(consent) {
        if (!config.blockScripts || !consent) return;
        var scripts = config.scripts || {};
        ['functional', 'analytics', 'marketing'].forEach(function (cat) {
            if (injectedCats[cat]) return;          // already injected on this page
            if (!consent[cat]) return;              // no consent for this category
            var html = scripts[cat];
            if (!html || !String(html).replace(/\s+/g, '')) return;
            injectedCats[cat] = true;
            try {
                appendExecutable(String(html));
                log('injected scripts: ' + cat);
            } catch (e) {
                log('script injection failed: ' + cat, e.message);
            }
        });
    }

    /* ============== Debug Panel ============== */

    var debugPanel = null;
    function renderDebugPanel() {
        if (!DEBUG) return;
        if (!debugPanel) {
            debugPanel = document.createElement('div');
            debugPanel.id = 'dpt-cb-debug-panel';
            debugPanel.style.cssText = 'position:fixed;top:0;left:0;width:100%;max-height:35vh;background:#0a0e27;color:#7fd4bc;font-family:monospace;font-size:12px;padding:10px;overflow:auto;z-index:2147483647;border-bottom:3px solid #7fd4bc;direction:ltr;text-align:left;line-height:1.5;box-shadow:0 4px 12px rgba(0,0,0,0.4);';
            var header = document.createElement('div');
            header.style.cssText = 'color:#fff;font-weight:bold;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center;';
            header.innerHTML = '<span>🐛 DPT Cookie Banner Debug <span style="color:#7fd4bc;font-weight:normal;">(?dpt_debug=1)</span></span><span><button id="dpt-cb-debug-copy" style="background:#7fd4bc;color:#0a0e27;border:0;padding:4px 10px;border-radius:4px;cursor:pointer;font-weight:bold;margin-left:8px;">📋 Copy All</button><button id="dpt-cb-debug-close" style="background:#dc2626;color:#fff;border:0;padding:4px 10px;border-radius:4px;cursor:pointer;font-weight:bold;">✕</button></span>';
            debugPanel.appendChild(header);
            var content = document.createElement('pre');
            content.id = 'dpt-cb-debug-content';
            content.style.cssText = 'margin:0;white-space:pre-wrap;word-wrap:break-word;color:#7fd4bc;';
            debugPanel.appendChild(content);
            (document.body || document.documentElement).appendChild(debugPanel);

            document.getElementById('dpt-cb-debug-close').addEventListener('click', function () {
                debugPanel.style.display = 'none';
            });
            document.getElementById('dpt-cb-debug-copy').addEventListener('click', function () {
                var fullDump = buildFullDump();
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(fullDump).then(function () {
                        this.textContent = '✓ Copied!';
                        var self = this;
                        setTimeout(function () { self.textContent = '📋 Copy All'; }, 1500);
                    }.bind(this));
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = fullDump;
                    document.body.appendChild(ta);
                    ta.select();
                    try { document.execCommand('copy'); } catch (e) {}
                    document.body.removeChild(ta);
                }
            });
        }
        var content = document.getElementById('dpt-cb-debug-content');
        if (content) content.textContent = debugLog.join('\n');
    }

    function buildFullDump() {
        var banner = document.getElementById('dpt-cb-banner');
        var overlay = document.getElementById('dpt-cb-overlay');
        var floatBtn = document.getElementById('dpt-cb-float-button');
        var dump = '=== DPT COOKIE BANNER DEBUG DUMP ===\n';
        dump += 'Version: ' + (config.version || '?') + '\n';
        dump += 'URL: ' + location.href + '\n';
        dump += 'User Agent: ' + navigator.userAgent + '\n';
        dump += 'Document readyState: ' + document.readyState + '\n';
        dump += '\n=== ELEMENTS ===\n';
        dump += 'banner found: ' + !!banner + '\n';
        dump += 'banner classes: ' + (banner ? banner.className : 'N/A') + '\n';
        dump += 'banner display: ' + (banner ? getComputedStyle(banner).display : 'N/A') + '\n';
        dump += 'banner dir: ' + (banner ? banner.getAttribute('dir') : 'N/A') + '\n';
        dump += 'banner lang: ' + (banner ? banner.getAttribute('lang') : 'N/A') + '\n';
        dump += 'overlay found: ' + !!overlay + '\n';
        dump += 'overlay classes: ' + (overlay ? overlay.className : 'N/A') + '\n';
        dump += 'floatBtn found: ' + !!floatBtn + '\n';
        dump += 'html data-dpt-cb-resolved: ' + document.documentElement.getAttribute('data-dpt-cb-resolved') + '\n';
        if (banner) {
            var mainView = banner.querySelector('.dpt-cb-main-view');
            var settingsView = banner.querySelector('.dpt-cb-settings-view');
            dump += 'mainView found: ' + !!mainView + '\n';
            dump += 'mainView display: ' + (mainView ? mainView.style.display || 'default' : 'N/A') + '\n';
            dump += 'settingsView found: ' + !!settingsView + '\n';
            dump += 'settingsView display: ' + (settingsView ? settingsView.style.display || 'default' : 'N/A') + '\n';
            dump += 'action buttons:\n';
            var actions = banner.querySelectorAll('[data-dpt-cb-action]');
            for (var i = 0; i < actions.length; i++) {
                dump += '  - [' + actions[i].getAttribute('data-dpt-cb-action') + '] tag=' + actions[i].tagName + ' pointer-events=' + actions[i].style.pointerEvents + '\n';
            }
        }
        dump += '\n=== CONFIG ===\n';
        dump += JSON.stringify(config, null, 2) + '\n';
        dump += '\n=== STORAGE ===\n';
        dump += 'cookie dpt_consent: ' + getCookie(COOKIE) + '\n';
        dump += 'localStorage dpt_consent_v1: ' + lsGet(LS_KEY) + '\n';
        dump += 'cookie domain: ' + getCookieDomain() + '\n';
        dump += '\n=== EVENT LOG ===\n';
        dump += debugLog.join('\n');
        return dump;
    }

    /* ============== Boot ============== */

    function init() {
        log('init() start');

        var banner    = document.getElementById('dpt-cb-banner');
        var overlay   = document.getElementById('dpt-cb-overlay');
        var floatBtn  = document.getElementById('dpt-cb-float-button');

        if (!banner) {
            log('FATAL: #dpt-cb-banner not found in DOM');
            renderDebugPanel();
            return;
        }
        log('banner element OK');

        var existing    = readConsent();
        var forceOpen   = banner.hasAttribute('data-force-open');
        var preResolved = document.documentElement.getAttribute('data-dpt-cb-resolved') === '1';
        var shouldShow  = (!existing && !preResolved) || forceOpen;

        // Consented snippets are client-injected on every page load (the
        // server never bakes them into cacheable HTML).
        if (existing) {
            injectConsentedScripts(existing);
        }

        // The precheck may have resolved from a stale-version consent that
        // readConsent() now rejects - trust readConsent() over the marker.
        if (!existing && preResolved && !forceOpen) {
            preResolved = false;
            document.documentElement.removeAttribute('data-dpt-cb-resolved');
            shouldShow = true;
        }

        log('boot decision', {
            hasExisting: !!existing,
            forceOpen: forceOpen,
            preResolved: preResolved,
            shouldShow: shouldShow
        });

        if (shouldShow) {
            document.documentElement.removeAttribute('data-dpt-cb-resolved');
            var delayMs = Math.max(0, (config.showDelay || 0) * 1000);
            var revealBanner = function () {
                banner.classList.remove('dpt-cb-hidden');
                if (overlay) overlay.classList.remove('dpt-cb-hidden');
                banner.classList.add('dpt-cb-entering');
                setTimeout(function () { banner.classList.remove('dpt-cb-entering'); }, 500);
                log('banner revealed');
            };
            if (delayMs > 0) {
                log('delaying reveal by ' + delayMs + 'ms');
                setTimeout(revealBanner, delayMs);
            } else {
                revealBanner();
            }
        }

        var mainView     = banner.querySelector('.dpt-cb-main-view');
        var settingsView = banner.querySelector('.dpt-cb-settings-view');
        var catInputs    = banner.querySelectorAll('input[data-dpt-cb-cat]');

        // Lock prevents rapid double-clicks during a short close animation,
        // but resets automatically so the user is NEVER permanently locked out.
        var isClosing = false;

        function showBanner() {
            log('showBanner()');
            isClosing = false;
            try { document.documentElement.removeAttribute('data-dpt-cb-resolved'); } catch(e) {}
            banner.classList.remove('dpt-cb-hidden', 'dpt-cb-closing');
            if (overlay) overlay.classList.remove('dpt-cb-hidden', 'dpt-cb-closing');
            void banner.offsetWidth;
            banner.classList.add('dpt-cb-entering');
            setTimeout(function () { banner.classList.remove('dpt-cb-entering'); }, 500);
        }

        function hideBannerInstant() {
            log('hideBannerInstant()');
            banner.classList.remove('dpt-cb-entering', 'dpt-cb-closing');
            banner.classList.add('dpt-cb-hidden');
            if (overlay) {
                overlay.classList.remove('dpt-cb-closing');
                overlay.classList.add('dpt-cb-hidden');
            }
        }

        function showMainView() {
            log('showMainView()');
            if (mainView) mainView.style.display = '';
            if (settingsView) settingsView.style.display = 'none';
            // Re-enable any buttons that were disabled by previous clicks.
            var btns = banner.querySelectorAll('[data-dpt-cb-action]');
            for (var i = 0; i < btns.length; i++) btns[i].style.pointerEvents = '';
        }

        function showSettingsView() {
            log('showSettingsView()');
            if (mainView) mainView.style.display = 'none';
            if (settingsView) settingsView.style.display = '';
            var saved = readConsent();
            Array.prototype.forEach.call(catInputs, function (input) {
                var cat = input.getAttribute('data-dpt-cb-cat');
                input.checked = !!(saved && saved[cat]);
            });
            // Re-enable buttons in the settings view in case they were disabled.
            var btns = settingsView ? settingsView.querySelectorAll('[data-dpt-cb-action]') : [];
            for (var i = 0; i < btns.length; i++) btns[i].style.pointerEvents = '';
        }

        function dispatchConsentEvent(consent) {
            try { window.dispatchEvent(new CustomEvent('dpt:consent', { detail: consent })); }
            catch (e) {}
        }

        function finishConsent(consent) {
            if (isClosing) {
                log('finishConsent ignored - already closing');
                return;
            }
            isClosing = true;
            log('finishConsent', consent);
            saveConsent(consent);
            injectConsentedScripts(consent);
            try { document.documentElement.setAttribute('data-dpt-cb-resolved', '1'); } catch(e) {}
            hideBannerInstant();
            // Reset to main view so the next time the banner opens, it shows the main view.
            if (mainView) mainView.style.display = '';
            if (settingsView) settingsView.style.display = 'none';
            dispatchConsentEvent(consent);
            // Auto-release lock after a short window so the float button still works.
            setTimeout(function () { isClosing = false; }, 600);
        }

        function baseConsent() {
            return { v: String(config.consentVersion || '1'), essential: true, ts: Date.now() };
        }
        function acceptAll() {
            // Grant only the categories that are enabled (and therefore
            // disclosed) right now - a disabled category must stay false so
            // enabling it later doesn't resurrect this cookie as consent
            // the visitor never actually gave.
            var cats = config.categories || {};
            var c = baseConsent();
            c.functional = !!cats.functional;
            c.analytics  = !!cats.analytics;
            c.marketing  = !!cats.marketing;
            finishConsent(c);
        }
        function rejectAll() {
            var c = baseConsent();
            c.functional = false; c.analytics = false; c.marketing = false;
            finishConsent(c);
        }
        function saveSettings() {
            var c = baseConsent();
            c.functional = false; c.analytics = false; c.marketing = false;
            Array.prototype.forEach.call(catInputs, function (input) {
                c[input.getAttribute('data-dpt-cb-cat')] = input.checked;
            });
            finishConsent(c);
        }

        // Click handler. Only the close-action buttons should be locked
        // during the closing animation. Navigation actions (settings/back)
        // should always work.
        banner.addEventListener('click', function (e) {
            var trigger = e.target.closest('[data-dpt-cb-action]');
            if (!trigger) return;
            var action = trigger.getAttribute('data-dpt-cb-action');
            log('click: ' + action);

            // Reset the lock for navigation actions - they don't close the banner.
            if (action === 'show-settings') {
                isClosing = false;
                showSettingsView();
                return;
            }

            // For close-actions, respect the lock to prevent double-fires.
            if (isClosing) {
                log('click ignored - banner closing');
                e.stopPropagation();
                e.preventDefault();
                return;
            }

            // Visual feedback only for the closing buttons.
            if (action === 'accept' || action === 'reject' || action === 'save-settings') {
                trigger.style.pointerEvents = 'none';
                setTimeout(function () { trigger.style.pointerEvents = ''; }, 600);
            }

            if (action === 'accept')             acceptAll();
            else if (action === 'reject')        rejectAll();
            else if (action === 'save-settings') saveSettings();
        }, false); // bubble phase, not capture - more compatible

        if (floatBtn) {
            floatBtn.addEventListener('click', function () {
                log('float button clicked');
                isClosing = false;
                showMainView();
                showBanner();
            });
        }

        if (config.autoAcceptScroll && !readConsent()) {
            // Mirrors the .dpt-cb-hide-mobile media query: scrolling must not
            // count as consent while the banner is invisible on this viewport.
            var bannerHiddenHere = function () {
                if (config.showOnMobile) return false;
                try { return window.matchMedia('(max-width: 640px)').matches; }
                catch (e) { return false; }
            };
            var scrollHandler = function () {
                if (window.scrollY <= 150) return;
                // The visitor may have answered since this listener was
                // attached (e.g. clicked "Reject all" and kept scrolling) -
                // an explicit choice must never be overwritten by a scroll.
                if (readConsent()) {
                    window.removeEventListener('scroll', scrollHandler);
                    return;
                }
                // Scrolling past an INVISIBLE banner is not consent: still
                // within the show-delay window (dpt-cb-hidden not yet
                // removed) or hidden on this viewport by the mobile rule.
                if (banner.classList.contains('dpt-cb-hidden') || bannerHiddenHere()) return;
                window.removeEventListener('scroll', scrollHandler);
                acceptAll();
            };
            window.addEventListener('scroll', scrollHandler, { passive: true });
        }

        window.DPTCB = {
            open:       function () { isClosing = false; showMainView(); showBanner(); },
            close:      hideBannerInstant,
            getConsent: readConsent,
            acceptAll:  acceptAll,
            rejectAll:  rejectAll,
            debug:      function () { return buildFullDump(); }
        };

        log('init() complete - DPTCB ready');
        if (DEBUG) renderDebugPanel();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
