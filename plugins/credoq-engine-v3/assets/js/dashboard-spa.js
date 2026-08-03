/**
 * Credoq Dashboard SPA router.
 *
 * Loaded by [credoq_dashboard_app]. Listens to clicks on any
 * [data-credoq-tab] element and shows the matching panel.
 *
 * This script is dependency-free (no jQuery). It self-initializes
 * after the panels render. Globals: window.credoqRouter.
 */
(function (win, doc) {
	'use strict';

	function init() {
		var app = doc.getElementById('credoq-app');
		if (!app) return; // no SPA on this page
		if (app.dataset.credoqInitialized === '1') return;
		app.dataset.credoqInitialized = '1';

		var tabsAttr = app.getAttribute('data-tabs') || '[]';
		var TABS = [];
		try { TABS = JSON.parse(tabsAttr); } catch (e) { TABS = []; }
		if (!TABS.length) return;

		var current = null;

		function normalise(raw) {
			if (!raw) return TABS[0] || 'home';
			raw = String(raw).replace(/^#/, '').toLowerCase().replace(/-/g, '_');
			return TABS.indexOf(raw) !== -1 ? raw : (TABS[0] || 'home');
		}

		function go(tabKey, pushState) {
			tabKey = normalise(tabKey);
			if (tabKey === current) return;

			// Hide all panels.
			TABS.forEach(function (t) {
				var p = doc.getElementById('credoq-panel-' + t);
				if (p) {
					p.style.display = 'none';
					p.classList.remove('cq-entering');
				}
			});

			// Show target.
			var target = doc.getElementById('credoq-panel-' + tabKey);
			if (target) {
				target.style.display = 'block';
				// Force reflow so the animation restarts cleanly.
				void target.offsetWidth;
				target.classList.add('cq-entering');
				// Don't scroll if user is already viewing this region.
				var rect = target.getBoundingClientRect();
				if (rect.top < 0 || rect.top > win.innerHeight * 0.5) {
					target.scrollIntoView({ behavior: 'smooth', block: 'start' });
				}
			}
			current = tabKey;

			// Update active state on every tab control.
			doc.querySelectorAll('[data-credoq-tab]').forEach(function (el) {
				var isActive = el.dataset.credoqTab === tabKey;
				el.classList.toggle('active', isActive);
				el.setAttribute('aria-current', isActive ? 'page' : 'false');
			});

			if (pushState !== false) {
				history.pushState({ credoqTab: tabKey }, '', '#' + tabKey.replace(/_/g, '-'));
			}
		}

		win.addEventListener('popstate', function (e) {
			var tab = (e.state && e.state.credoqTab) ? e.state.credoqTab : normalise(location.hash);
			go(tab, false);
		});

		doc.addEventListener('click', function (e) {
			var el = e.target.closest('[data-credoq-tab]');
			if (!el) return;
			e.preventDefault();
			go(el.dataset.credoqTab);
		});

		var initTab = normalise(location.hash || TABS[0]);
		go(initTab, false);
		history.replaceState({ credoqTab: initTab }, '', '#' + initTab.replace(/_/g, '-'));

		win.credoqRouter = { go: go, current: function () { return current; } };
	}

	if (doc.readyState === 'loading') {
		doc.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(window, document);
