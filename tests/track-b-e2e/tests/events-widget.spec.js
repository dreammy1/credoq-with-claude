const { test, expect } = require('@playwright/test');
const { mountWidget, mockAjax, withDiagnostics } = require('./helpers');

/**
 * AUDIT-FIX (Track B — corrected data model after the first CI run
 * failed): EventCalendarField does NOT fetch its event list via AJAX.
 * The events for a given date live in `field._frontend.props.by_date`
 * (a month-grid keyed by ISO date), pre-rendered server-side — exactly
 * like `get_frontend_render()` builds it for the real widget. The first
 * version of this spec incorrectly assumed a flat AJAX-fetched list and
 * a plain clickable event title; the real interaction is: click a
 * calendar day cell (only clickable when it has events) → a day panel
 * opens listing that day's events as checkboxes → check one to select it.
 */

function todayISO() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
}

function baseConfig(overrides = {}) {
  return Object.assign({
    ajax_url: 'http://127.0.0.1:4173/wp-admin/admin-ajax.php',
    nonce: 'test-nonce-123',
    form_id: 1,
    currency: 'USD',
    fields: [
      { name: 'name', type: 'text', label: 'Your Name', required: true },
      { name: 'email', type: 'email', label: 'Email', required: true },
    ],
    event_seat_plans: {},
  }, overrides);
}

function eventCalendarField(name, byDate) {
  return {
    name, type: 'event_registration', label: 'Choose an event',
    _frontend: { component: 'event_calendar', props: { by_date: byDate, max_tickets: 10, currency_sym: '$' } },
  };
}

test.describe('Events registration — no seat map (free event)', () => {
  test('visitor picks a date, selects an event, fills details, and submits successfully', async ({ page }) => {
    const date = todayISO();
    const byDate = { [date]: [
      { id: 1, title: 'Community Meetup', price: 0, start: '10:00', end: '11:00', color: '#4f46e5', remaining: 20, max_qty: 5 },
    ] };

    await mockAjax(page, {
      credoq_submit_booking: (fields) => {
        expect(fields['form_data[name]']).toBe('Jane Visitor');
        expect(fields['form_data[email]']).toBe('jane@example.test');
        expect(fields['form_data[event_field]']).toContain('"event_id":1');
        return { success: true, data: { message: 'Registered!', submission_id: 42, total_price: 0 } };
      },
    });

    const config = baseConfig({
      fields: [
        eventCalendarField('event_field', byDate),
        { name: 'name', type: 'text', label: 'Your Name', required: true },
        { name: 'email', type: 'email', label: 'Email', required: true },
      ],
    });
    await mountWidget(page, config);

    // Click the day cell that has events (identified by its title text,
    // not the day-of-month number, so this works regardless of what day
    // the test happens to run).
    await withDiagnostics(page, 'calendar-root-visible', async () => {
      await expect(page.locator('.cqw-event-cal-root')).toBeVisible({ timeout: 15000 });
    });
    await withDiagnostics(page, 'click-day-cell', async () => {
      await expect(page.locator('[title*="click to select"]').first()).toBeVisible({ timeout: 15000 });
      await page.locator('[title*="click to select"]').first().click();
    });
    await expect(page.locator('.cqw-event-row-title', { hasText: 'Community Meetup' })).toBeVisible({ timeout: 10000 });
    await page.locator('.cqw-event-row', { hasText: 'Community Meetup' }).locator('.cqw-event-check-wrap input[type="checkbox"]').check();

    await page.locator('input[name="form_data[name]"]').fill('Jane Visitor');
    await page.locator('input[name="form_data[email]"]').fill('jane@example.test');

    for (let i = 0; i < 6; i++) {
      const btn = page.locator('.cqw-btn-cont');
      if (!(await btn.isVisible())) break;
      await btn.click();
      await page.waitForTimeout(400);
      if (await page.getByText(/Registered!/i).isVisible().catch(() => false)) break;
    }

    await expect(page.getByText(/Registered!/i)).toBeVisible({ timeout: 10000 });
  });
});

test.describe('Events registration — with seat map', () => {
  test('seat map renders, seat click holds it, qty stepper locks, submission includes real seat data', async ({ page }) => {
    const date = todayISO();
    const byDate = { [date]: [
      { id: 7, title: 'Jazz Night', price: 25, start: '19:00', end: '22:00', color: '#a78bfa', remaining: 40, max_qty: 5 },
    ] };

    const holdRequests = [];
    let submittedFields = null;

    await mockAjax(page, {
      credoq_seats_load_map: () => ({
        success: true,
        data: { html:
          '<div class="cvsp-map-wrap" data-plan-id="3" data-plan-name="Main Hall" data-credoq-event-id="7">' +
          '  <div class="cvsp-floor-canvas" data-floor-id="1">' +
          '    <div class="cvsp-seat type-standard" data-seat-id="101" data-price="25.00" data-label="A1" style="left:10px;top:10px;">A1</div>' +
          '    <div class="cvsp-seat type-vip" data-seat-id="102" data-price="40.00" data-label="A2" style="left:60px;top:10px;">A2</div>' +
          '  </div>' +
          '  <div class="cvsp-summary"><span class="cvsp-sel-count">0</span> · <span class="cvsp-sel-total">0.00</span></div>' +
          '  <div class="cvsp-hold-msg" role="status"></div>' +
          '</div>',
        },
      }),
      credoq_seats_get_booked: () => ({ success: true, data: { booked_seat_ids: [] } }),
      credoq_seats_hold: (fields) => { holdRequests.push(fields); return { success: true, data: { held: true } }; },
      credoq_seats_release: () => ({ success: true }),
      credoq_submit_booking: (fields) => { submittedFields = fields; return { success: true, data: { message: 'Registered!', submission_id: 55, total_price: 40 } }; },
    });

    const config = baseConfig({
      fields: [
        eventCalendarField('event_field', byDate),
        { name: 'seat_field', type: 'seat_map', label: 'Choose your seats' },
        { name: 'name', type: 'text', label: 'Your Name', required: true },
        { name: 'email', type: 'email', label: 'Email', required: true },
      ],
      event_seat_plans: { '7': 3 },
    });
    await mountWidget(page, config);

    await withDiagnostics(page, 'calendar-root-visible', async () => {
      await expect(page.locator('.cqw-event-cal-root')).toBeVisible({ timeout: 15000 });
    });
    await withDiagnostics(page, 'click-day-cell', async () => {
      await expect(page.locator('[title*="click to select"]').first()).toBeVisible({ timeout: 15000 });
      await page.locator('[title*="click to select"]').first().click();
    });
    await expect(page.locator('.cqw-event-row-title', { hasText: 'Jazz Night' })).toBeVisible({ timeout: 10000 });
    await page.locator('.cqw-event-row', { hasText: 'Jazz Night' }).locator('.cqw-event-check-wrap input[type="checkbox"]').check();

    // Qty stepper must be LOCKED once a seat map governs this event —
    // shows "N seats" text, not +/- buttons.
    await expect(page.locator('.cqw-event-qty-locked')).toBeVisible({ timeout: 5000 });
    await expect(page.locator('.cqw-event-qty-stepper button')).toHaveCount(0);

    // Seat map renders (real frontend-seat-map.js driving the mocked HTML).
    await expect(page.locator('.cvsp-seat[data-seat-id="102"]')).toBeVisible({ timeout: 10000 });
    await page.locator('.cvsp-seat[data-seat-id="102"]').click();
    await page.waitForTimeout(500);

    expect(holdRequests.length).toBeGreaterThan(0);
    expect(holdRequests[0]['seat_id']).toBe('102');
    expect(holdRequests[0]['event_id']).toBe('7');

    await page.locator('input[name="form_data[name]"]').fill('Sam Buyer');
    await page.locator('input[name="form_data[email]"]').fill('sam@example.test');

    for (let i = 0; i < 6; i++) {
      const btn = page.locator('.cqw-btn-cont');
      if (!(await btn.isVisible())) break;
      await btn.click();
      await page.waitForTimeout(400);
      if (await page.getByText(/Registered!/i).isVisible().catch(() => false)) break;
    }

    await expect(page.getByText(/Registered!/i)).toBeVisible({ timeout: 10000 });
    expect(submittedFields).not.toBeNull();
    const seatMapKey = Object.keys(submittedFields).find(k => k.includes('seat_field') && k.includes('seats'));
    expect(seatMapKey).toBeTruthy();
    expect(submittedFields[seatMapKey]).toContain('102');
  });
});

test.describe('Backend error handling', () => {
  test('widget shows a clear error instead of crashing when the backend rejects the submission', async ({ page }) => {
    const date = todayISO();
    const byDate = { [date]: [
      { id: 9, title: 'Sold Out Show', price: 10, start: '20:00', end: '21:00', color: '#fb7185', remaining: 20, max_qty: 5 },
    ] };

    await mockAjax(page, {
      credoq_submit_booking: () => ({ success: false, data: { message: 'Not enough spots remaining.' } }),
    });

    const config = baseConfig({
      fields: [
        eventCalendarField('event_field', byDate),
        { name: 'name', type: 'text', label: 'Your Name', required: true },
        { name: 'email', type: 'email', label: 'Email', required: true },
      ],
    });
    await mountWidget(page, config);

    await withDiagnostics(page, 'calendar-root-visible', async () => {
      await expect(page.locator('.cqw-event-cal-root')).toBeVisible({ timeout: 15000 });
    });
    await withDiagnostics(page, 'click-day-cell', async () => {
      await expect(page.locator('[title*="click to select"]').first()).toBeVisible({ timeout: 15000 });
      await page.locator('[title*="click to select"]').first().click();
    });
    await expect(page.locator('.cqw-event-row-title', { hasText: 'Sold Out Show' })).toBeVisible({ timeout: 10000 });
    await page.locator('.cqw-event-row', { hasText: 'Sold Out Show' }).locator('.cqw-event-check-wrap input[type="checkbox"]').check();

    await page.locator('input[name="form_data[name]"]').fill('Alex Test');
    await page.locator('input[name="form_data[email]"]').fill('alex@example.test');

    for (let i = 0; i < 6; i++) {
      const btn = page.locator('.cqw-btn-cont');
      if (!(await btn.isVisible())) break;
      await btn.click();
      await page.waitForTimeout(400);
      if (await page.getByText(/Not enough spots remaining/i).isVisible().catch(() => false)) break;
    }

    await expect(page.getByText(/Not enough spots remaining/i)).toBeVisible({ timeout: 10000 });
    await expect(page.locator('#credoq-booking-root')).toBeVisible();
  });
});
