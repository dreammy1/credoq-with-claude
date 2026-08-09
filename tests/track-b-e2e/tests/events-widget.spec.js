const { test, expect } = require('@playwright/test');
const { mountWidget, mockAjax } = require('./helpers');

// A minimal, realistic config for a standalone Events registration form —
// matches exactly what credoq-events/includes/Shortcodes.php + the
// credoq_widget_config filter chain (Events_Bridge::inject_widget_config)
// would produce server-side. Nothing here is a widget mock — this is the
// REAL data shape the real widget expects.
function baseConfig(overrides = {}) {
  return Object.assign({
    ajax_url: 'http://widget-test.local/wp-admin/admin-ajax.php',
    nonce: 'test-nonce-123',
    form_id: 1,
    currency: 'USD',
    fields: [
      { name: 'event_field', type: 'event_registration', label: 'Choose an event',
        _frontend: { component: 'event_calendar' } },
      { name: 'name', type: 'text', label: 'Your Name', required: true },
      { name: 'email', type: 'email', label: 'Email', required: true },
    ],
    event_seat_plans: {},
  }, overrides);
}

test.describe('Events registration — no seat map (free event)', () => {
  test('visitor picks an event, fills details, and submits successfully', async ({ page }) => {
    await mockAjax(page, {
      credoq_get_events_feed: () => ({
        success: true,
        data: { events: [
          { id: 1, title: 'Community Meetup', price: 0, start_date: '2026-12-01', capacity_left: 10 },
        ] },
      }),
      credoq_submit_booking: (fields) => {
        expect(fields['form_data[name]']).toBe('Jane Visitor');
        expect(fields['form_data[email]']).toBe('jane@example.test');
        return { success: true, data: { message: 'Registered!', submission_id: 42, total_price: 0 } };
      },
    });

    await mountWidget(page, baseConfig());

    // Event calendar renders the mocked event.
    await expect(page.getByText('Community Meetup')).toBeVisible({ timeout: 10000 });
    await page.getByText('Community Meetup').click();

    await page.locator('input[name="form_data[name]"]').fill('Jane Visitor');
    await page.locator('input[name="form_data[email]"]').fill('jane@example.test');

    // Click the single Next/Submit button through however many steps exist.
    for (let i = 0; i < 6; i++) {
      const btn = page.locator('.cqw-btn-cont');
      if (!(await btn.isVisible())) break;
      await btn.click();
      await page.waitForTimeout(400);
      const successVisible = await page.getByText(/Registered!/i).isVisible().catch(() => false);
      if (successVisible) break;
    }

    await expect(page.getByText(/Registered!/i)).toBeVisible({ timeout: 10000 });
  });
});

test.describe('Events registration — with seat map', () => {
  test('seat map renders, seat click holds it, qty stepper locks, submission includes real seat data', async ({ page }) => {
    const holdRequests = [];
    let submittedFields = null;

    await mockAjax(page, {
      credoq_get_events_feed: () => ({
        success: true,
        data: { events: [
          { id: 7, title: 'Jazz Night', price: 25, start_date: '2026-12-15', capacity_left: 40 },
        ] },
      }),
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
      credoq_seats_hold: (fields) => {
        holdRequests.push(fields);
        return { success: true, data: { held: true } };
      },
      credoq_seats_release: () => ({ success: true }),
      credoq_submit_booking: (fields) => {
        submittedFields = fields;
        return { success: true, data: { message: 'Registered!', submission_id: 55, total_price: 40 } };
      },
    });

    // event_seat_plans maps event id 7 -> seat plan id 3, exactly like
    // Events_Bridge::inject_widget_config() computes server-side for a
    // plan connected to exactly one event.
    const config = baseConfig({
      fields: [
        { name: 'event_field', type: 'event_registration', label: 'Choose an event',
          _frontend: { component: 'event_calendar' } },
        { name: 'seat_field', type: 'seat_map', label: 'Choose your seats' },
        { name: 'name', type: 'text', label: 'Your Name', required: true },
        { name: 'email', type: 'email', label: 'Email', required: true },
      ],
      event_seat_plans: { '7': 3 },
    });

    await mountWidget(page, config);

    await expect(page.getByText('Jazz Night')).toBeVisible({ timeout: 10000 });
    await page.getByText('Jazz Night').click();

    // Qty stepper must be LOCKED (shows "N seats" text, not +/- buttons)
    // once a seat map governs this event — the fix verified earlier in
    // this project. Assert the interactive stepper buttons are ABSENT.
    await expect(page.locator('.cqw-event-qty-stepper button')).toHaveCount(0, { timeout: 5000 });

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
      const successVisible = await page.getByText(/Registered!/i).isVisible().catch(() => false);
      if (successVisible) break;
    }

    await expect(page.getByText(/Registered!/i)).toBeVisible({ timeout: 10000 });
    expect(submittedFields).not.toBeNull();
    // The real seat_map field's submitted value nests under form_data[seat_field][...]
    const seatMapKey = Object.keys(submittedFields).find(k => k.includes('seat_field') && k.includes('seats'));
    expect(seatMapKey).toBeTruthy();
    expect(submittedFields[seatMapKey]).toContain('102');
  });
});

test.describe('Backend error handling', () => {
  test('widget shows a clear error instead of crashing when the backend rejects the submission', async ({ page }) => {
    await mockAjax(page, {
      credoq_get_events_feed: () => ({
        success: true,
        data: { events: [{ id: 9, title: 'Sold Out Show', price: 10, start_date: '2026-12-20', capacity_left: 0 }] },
      }),
      credoq_submit_booking: () => ({
        success: false,
        data: { message: 'Not enough spots remaining.' },
      }),
    });

    await mountWidget(page, baseConfig());
    await expect(page.getByText('Sold Out Show')).toBeVisible({ timeout: 10000 });
    await page.getByText('Sold Out Show').click();
    await page.locator('input[name="form_data[name]"]').fill('Alex Test');
    await page.locator('input[name="form_data[email]"]').fill('alex@example.test');

    for (let i = 0; i < 6; i++) {
      const btn = page.locator('.cqw-btn-cont');
      if (!(await btn.isVisible())) break;
      await btn.click();
      await page.waitForTimeout(400);
      const errVisible = await page.getByText(/Not enough spots remaining/i).isVisible().catch(() => false);
      if (errVisible) break;
    }

    await expect(page.getByText(/Not enough spots remaining/i)).toBeVisible({ timeout: 10000 });
    // The widget must not have thrown a JS error that breaks the page —
    // the form's Submit/Next button should still be present/interactable.
    await expect(page.locator('#credoq-booking-root')).toBeVisible();
  });
});
