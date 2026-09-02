const { test, expect } = require('@playwright/test');
const { installDiagnostics, assertNoUnexpectedBrowserErrors, recordFixture, recordObservation } = require('../support/staging');

const formPath = process.env.CREDOQ_BOOKING_FORM_PATH || process.env.CREDOQ_FRONTEND_AUDIT_PATH;
const checkoutPath = process.env.CREDOQ_CHECKOUT_PATH || '/checkout/';
const auditPrefix = process.env.CREDOQ_AUDIT_PREFIX || 'AUDIT TEST';
const bookingDate = process.env.CREDOQ_APPOINTMENT_DATE;
const slotText = process.env.CREDOQ_APPOINTMENT_SLOT_TEXT;

function optionalLocator(page, selectors) {
  return page.locator(selectors).first();
}

async function firstVisible(page, selectors) {
  const locator = optionalLocator(page, selectors);
  if (await locator.isVisible().catch(() => false)) return locator;
  return null;
}

async function fillIfPresent(page, selectors, value) {
  const locator = await firstVisible(page, selectors);
  if (locator) await locator.fill(value);
  return Boolean(locator);
}

test.describe('CredoQ appointment form to WooCommerce checkout', () => {
  test.beforeEach(async ({ page }) => installDiagnostics(page));

  test('selects an appointment schedule, submits the form, and redirects to checkout without capturing payment', async ({ page }) => {
    test.skip(!formPath, 'Set CREDOQ_BOOKING_FORM_PATH to the published CredoQ appointment form page.');
    test.skip(!bookingDate, 'Set CREDOQ_APPOINTMENT_DATE to an available ISO date on staging.');
    test.skip(!slotText, 'Set CREDOQ_APPOINTMENT_SLOT_TEXT to a stable appointment slot label.');

    const response = await page.goto(formPath, { waitUntil: 'domcontentloaded', timeout: 30000 });
    expect(response && response.ok()).toBeTruthy();
    await expect(page.locator('body')).not.toContainText(/fatal error|critical error|there has been a critical error/i);

    const form = page.locator('form, #credoq-booking-root, [data-credoq-form]').first();
    await expect(form).toBeVisible({ timeout: 20000 });

    const dateControl = await firstVisible(page, [
      `input[name*="date"][value="${bookingDate}"]`,
      `input[type="date"]`,
      `[data-date="${bookingDate}"]`,
      `[data-day="${bookingDate}"]`,
      `[title*="${bookingDate}"]`,
    ].join(', '));
    expect(dateControl, `No appointment date control found for ${bookingDate}`).not.toBeNull();
    await dateControl.click();

    const slot = await firstVisible(page, [
      `[data-slot*="${slotText}"]`,
      `[data-time*="${slotText}"]`,
      `label:has-text("${slotText}")`,
      `button:has-text("${slotText}")`,
      `text=${slotText}`,
    ].join(', '));
    expect(slot, `No appointment slot found for ${slotText}`).not.toBeNull();
    await slot.click();

    const unique = Date.now();
    const filled = {
      name: await fillIfPresent(page, 'input[name*="name"], input[name*="full_name"], input[autocomplete="name"]', `${auditPrefix} Visitor`),
      email: await fillIfPresent(page, 'input[type="email"], input[name*="email"]', `audit-${unique}@example.test`),
      phone: await fillIfPresent(page, 'input[type="tel"], input[name*="phone"]', '2025550199'),
    };
    expect(filled.name && filled.email, 'The appointment form must expose name and email controls.').toBeTruthy();

    await page.locator('button[type="submit"], input[type="submit"], .cqw-btn-cont').last().click();
    await page.waitForURL(url => url.pathname.includes('checkout') || url.pathname.endsWith('/cart/'), { timeout: 30000 });

    const redirectedUrl = page.url();
    recordFixture('booking-submission', `${auditPrefix} Appointment Booking ${unique}`, redirectedUrl, 'archive');
    recordObservation({ track: 'appointment-to-checkout', formPath, bookingDate, slotText, redirectedUrl, filled, status: 'redirected' });
    expect(redirectedUrl).toContain(new URL(checkoutPath, redirectedUrl).pathname.replace(/\/$/, ''));

    const body = await page.locator('body').innerText();
    expect(body).toMatch(/checkout|billing|payment options/i);
    expect(body).toMatch(/direct bank transfer|cash on delivery|bank transfer|cod/i);
    expect(body).not.toMatch(/stripe|paypal|credit card|card number/i);
    await assertNoUnexpectedBrowserErrors(page, 'appointment-to-checkout');
  });

  test('does not submit the appointment form when the schedule is incomplete', async ({ page }) => {
    test.skip(!formPath, 'Set CREDOQ_BOOKING_FORM_PATH to the published CredoQ appointment form page.');
    await page.goto(formPath, { waitUntil: 'domcontentloaded', timeout: 30000 });
    const form = page.locator('form, #credoq-booking-root, [data-credoq-form]').first();
    await expect(form).toBeVisible({ timeout: 20000 });
    const submit = form.locator('button[type="submit"], input[type="submit"], .cqw-btn-cont').last();
    await submit.click();
    expect(page.url()).not.toMatch(/checkout|cart/);
    recordObservation({ track: 'appointment-to-checkout', check: 'incomplete_schedule_blocked', status: 'not_redirected' });
    await assertNoUnexpectedBrowserErrors(page, 'appointment-incomplete-schedule');
  });
});
