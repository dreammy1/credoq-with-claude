const { test, expect } = require('@playwright/test');
const { mockAjax, withDiagnostics } = require('./helpers');

/**
 * Mounts the Dashboard SPA structure.
 * Unlike the widget, the dashboard panels are server-rendered HTML.
 */
async function mountDashboard(page, panels) {
  const tabs = Object.keys(panels);
  const tabsJson = JSON.stringify(tabs).replace(/"/g, '&quot;');
  
  let panelsHtml = '';
  let sidebarHtml = '';
  
  for (const [slug, cfg] of Object.entries(panels)) {
    panelsHtml += `<div class="credoq-panel" id="credoq-panel-${slug}" data-tab="${slug}" style="display:none">${cfg.content}</div>`;
    sidebarHtml += `<li><button class="csn-item" data-credoq-tab="${slug}">${cfg.label}</button></li>`;
  }

  await page.setContent(`
    <!DOCTYPE html>
    <html>
    <head>
      <meta charset="utf-8">
      <style>
        .active { font-weight: bold; color: blue; }
        .cq-entering { opacity: 1; transition: opacity 0.3s; }
      </style>
    </head>
    <body>
      <div id="credoq-sidebar-nav">
        <ul class="csn-list">${sidebarHtml}</ul>
      </div>
      <div id="credoq-app" data-tabs="${tabsJson}">
        ${panelsHtml}
      </div>
      <script>
        // Minimal mock of the dashboard router logic
        window.credoqRouter = (function() {
          const TABS = JSON.parse(document.getElementById('credoq-app').dataset.tabs);
          function go(tab) {
            TABS.forEach(t => { document.getElementById('credoq-panel-'+t).style.display = 'none'; });
            document.getElementById('credoq-panel-'+tab).style.display = 'block';
            document.querySelectorAll('[data-credoq-tab]').forEach(btn => {
              btn.classList.toggle('active', btn.dataset.credoqTab === tab);
            });
          }
          document.addEventListener('click', e => {
            const btn = e.target.closest('[data-credoq-tab]');
            if (btn) go(btn.dataset.credoqTab);
          });
          go(TABS[0]);
          return { go };
        })();
      </script>
    </body>
    </html>
  `);
}

test.describe('Dashboard SPA Functionality', () => {
  test('switches between panels correctly', async ({ page }) => {
    await mountDashboard(page, {
      home: { label: 'Home', content: '<h1>Welcome</h1>' },
      my_schedule: { label: 'My Schedule', content: '<div id="schedule-content">Your Bookings</div>' }
    });

    await expect(page.getByText('Welcome')).toBeVisible();
    await expect(page.locator('#credoq-panel-my_schedule')).not.toBeVisible();

    await page.click('button[data-credoq-tab="my_schedule"]');

    await expect(page.locator('#credoq-panel-my_schedule')).toBeVisible();
    await expect(page.getByText('Your Bookings')).toBeVisible();
    await expect(page.getByText('Welcome')).not.toBeVisible();
  });

  test('cancelling a booking via AJAX updates UI', async ({ page }) => {
    let cancelledId = null;
    await mockAjax(page, {
      credoq_cancel_booking_user: (fields) => {
        cancelledId = fields.booking_id;
        return { success: true };
      }
    });

    const panelHtml = `
      <div class="cq-session-row">
        <span>Yoga Session</span>
        <button class="cq-btn" onclick="credoqCancelBooking(555, this, 'mock-nonce')">Cancel</button>
      </div>
      <script>
        function credoqCancelBooking(id, btn, nonce) {
          const fd = new FormData();
          fd.append('action', 'credoq_cancel_booking_user');
          fd.append('booking_id', id);
          fetch('/wp-admin/admin-ajax.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => { if(d.success) btn.textContent = 'Cancelled'; });
        }
      </script>
    `;

    await mountDashboard(page, {
      my_schedule: { label: 'My Schedule', content: panelHtml }
    });

    await page.click('button:has-text("Cancel")');
    await expect(page.getByText('Cancelled')).toBeVisible();
    expect(cancelledId).toBe('555');
  });

  test('displays membership credits and ledger', async ({ page }) => {
    const membershipHtml = `
      <div class="cq-card">
        <strong>Premium Plan</strong>
        <div class="credit-usage">Slot Usage: 3 / 10</div>
        <div class="progress-bar" style="width: 30%"></div>
      </div>
    `;

    await mountDashboard(page, {
      my_plans: { label: 'My Plans', content: membershipHtml }
    });

    await page.click('button[data-credoq-tab="my_plans"]');
    await expect(page.getByText('Premium Plan')).toBeVisible();
    await expect(page.getByText('Slot Usage: 3 / 10')).toBeVisible();
  });
});
