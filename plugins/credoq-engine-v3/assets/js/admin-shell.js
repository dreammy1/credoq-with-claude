/**
 * Credoq Admin Shell — interactive behaviors.
 *
 *  - Sidebar drawer toggle (mobile)
 *  - Theme toggle (light/dark) with localStorage persistence
 *  - Backdrop click to close sidebar
 *  - Escape key to close sidebar
 *  - Auto-close sidebar on resize to desktop
 *
 * Dependency-free. Self-initializing on DOMContentLoaded.
 */
(function (win, doc) {
	'use strict';

	function ready(fn) {
		if (doc.readyState !== 'loading') return fn();
		doc.addEventListener('DOMContentLoaded', fn);
	}

	ready(function () {
		var shell    = doc.getElementById('credoq-shell');
		var burger   = doc.getElementById('credoq-shell-burger');
		var sidebar  = doc.getElementById('credoq-shell-sidebar');
		var backdrop = doc.getElementById('credoq-shell-backdrop');
		var themeBtn = doc.getElementById('credoq-shell-theme-toggle');
		var body     = doc.body;
		var html     = doc.documentElement;

		if (!shell) return;

		// ── Sidebar drawer ───────────────────────────────────────
		function openSidebar() {
			body.classList.add('credoq-shell-sidebar-open');
			if (burger) burger.setAttribute('aria-expanded', 'true');
		}
		function closeSidebar() {
			body.classList.remove('credoq-shell-sidebar-open');
			if (burger) burger.setAttribute('aria-expanded', 'false');
		}
		function toggleSidebar() {
			if (body.classList.contains('credoq-shell-sidebar-open')) closeSidebar();
			else openSidebar();
		}

		if (burger) burger.addEventListener('click', toggleSidebar);
		if (backdrop) backdrop.addEventListener('click', closeSidebar);

		doc.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && body.classList.contains('credoq-shell-sidebar-open')) {
				closeSidebar();
			}
		});

		// On resize past mobile breakpoint, ensure drawer is closed.
		var resizeTimer = null;
		win.addEventListener('resize', function () {
			if (resizeTimer) clearTimeout(resizeTimer);
			resizeTimer = setTimeout(function () {
				if (win.innerWidth > 1024) closeSidebar();
			}, 150);
		});

		// Auto-close drawer after clicking a sidebar link (mobile UX).
		if (sidebar) {
			sidebar.addEventListener('click', function (e) {
				var link = e.target.closest('.credoq-shell-sidebar-item');
				if (link && win.innerWidth <= 1024) {
					// Let the navigation happen — drawer will reset on next page anyway.
					// But also close immediately for snappy feel if the link is in-page.
				}
			});
		}

		// ── Theme toggle ─────────────────────────────────────────
		function isDark() {
			return html.classList.contains('credoq-dark');
		}
		function applyTheme(dark, persist) {
			if (dark) html.classList.add('credoq-dark');
			else      html.classList.remove('credoq-dark');
			if (persist) {
				try { localStorage.setItem('credoq_theme', dark ? 'dark' : 'light'); }
				catch (e) { /* localStorage blocked */ }
			}
			if (themeBtn) {
				themeBtn.setAttribute(
					'aria-label',
					dark
						? (win.CredoqShell && win.CredoqShell.i18n && win.CredoqShell.i18n.lightMode) || 'Switch to light mode'
						: (win.CredoqShell && win.CredoqShell.i18n && win.CredoqShell.i18n.darkMode) || 'Switch to dark mode'
				);
			}
		}

		if (themeBtn) {
			themeBtn.addEventListener('click', function () {
				applyTheme(!isDark(), true);
			});
		}

		// If user hasn't manually chosen a theme, follow OS changes live.
		if (win.matchMedia) {
			var mq = win.matchMedia('(prefers-color-scheme: dark)');
			var listener = function (e) {
				try {
					if (!localStorage.getItem('credoq_theme')) {
						applyTheme(e.matches, false);
					}
				} catch (err) {}
			};
			if (mq.addEventListener) mq.addEventListener('change', listener);
			else if (mq.addListener)  mq.addListener(listener); // legacy Safari
		}

		// Mark sidebar items active by URL match — server already does this
		// but if the page hash changes (e.g. tab switch in a settings page),
		// keep visual state correct.
		(function highlightActive() {
			var here = win.location.pathname + win.location.search;
			doc.querySelectorAll('.credoq-shell-sidebar-item').forEach(function (el) {
				var href = el.getAttribute('href') || '';
				try {
					var a = doc.createElement('a');
					a.href = href;
					var matches = (a.pathname + a.search) === here;
					if (matches && !el.classList.contains('is-active')) {
						el.classList.add('is-active');
					}
				} catch (err) {}
			});
		})();
	});
})(window, document);
