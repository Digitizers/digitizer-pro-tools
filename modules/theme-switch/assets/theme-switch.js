/* Runs in the head before content renders. No page scan or color conversion. */
(function () {
	'use strict';
	if (window.DPTThemeSwitch) { return; }
	var config = window.DPT_TS_CONFIG || {};
	var root = document.documentElement;
	var base = config.base === 'dark' ? 'dark' : 'light';
	var modes = ['light', 'dark', 'system'];
	var preference = modes.indexOf(config.default) !== -1 ? config.default : 'system';
	var query = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
	var key = config.key || 'dpt-theme-switch';
	// Storage can throw even on access (privacy settings, sandboxed frames).
	// https://developer.mozilla.org/en-US/docs/Web/API/Window/localStorage#exceptions
	try {
		var saved = window.localStorage.getItem(key);
		if (modes.indexOf(saved) !== -1) { preference = saved; }
	} catch (ignore) {}

	function sync(context) {
		var groups = context.querySelectorAll('.dpt-theme-switch');
		if (context.nodeType === 1 && context.matches('.dpt-theme-switch')) {
			groups = [context];
		}
		Array.prototype.forEach.call(groups, function (group) {
			Array.prototype.forEach.call(group.querySelectorAll('[data-dpt-theme-choice]'), function (button) {
				button.setAttribute('aria-pressed', button.getAttribute('data-dpt-theme-choice') === preference ? 'true' : 'false');
			});
			group.hidden = false;
		});
	}

	function apply() {
		var mode = preference === 'system' ? (query ? (query.matches ? 'dark' : 'light') : base) : preference;
		root.setAttribute('data-dpt-theme', mode);
		root.setAttribute('data-dpt-theme-preference', preference);
		sync(document);
	}

	function choose(mode) {
		if (modes.indexOf(mode) === -1) { return; }
		preference = mode;
		try { window.localStorage.setItem(key, mode); } catch (ignore) {}
		apply();
	}
	window.DPTThemeSwitch = { setPreference: choose };
	apply();

	document.addEventListener('click', function (event) {
		var target = event.target;
		var button = target && target.closest ? target.closest('button[data-dpt-theme-choice]') : null;
		if (button && button.closest('.dpt-theme-switch')) {
			choose(button.getAttribute('data-dpt-theme-choice'));
		}
	});
	// Native buttons provide Enter/Space without duplicate click handlers.
	if (query) {
		var changed = function () { if (preference === 'system') { apply(); } };
		if (query.addEventListener) { query.addEventListener('change', changed); }
		else if (query.addListener) { query.addListener(changed); }
	}
	window.addEventListener('storage', function (event) {
		if (event.key === key || event.key === null) {
			preference = modes.indexOf(event.newValue) !== -1 ? event.newValue : (modes.indexOf(config.default) !== -1 ? config.default : 'system');
			apply();
		}
	});
	// A cached page restored by back/forward navigation may have missed storage events.
	window.addEventListener('pageshow', function (event) {
		if (!event.persisted) { return; }
		try {
			var value = window.localStorage.getItem(key);
			preference = modes.indexOf(value) !== -1 ? value : (modes.indexOf(config.default) !== -1 ? config.default : 'system');
		} catch (ignore) {}
		apply();
	});
	function ready() {
		sync(document);
		if (!window.MutationObserver) { return; }
		new MutationObserver(function (mutations) {
			mutations.forEach(function (mutation) {
				Array.prototype.forEach.call(mutation.addedNodes, function (node) {
					if (node.nodeType === 1) { sync(node); }
				});
			});
		}).observe(document.documentElement, { childList: true, subtree: true });
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', ready); }
	else { ready(); }
}());
