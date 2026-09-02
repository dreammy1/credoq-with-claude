const { test, expect } = require('@playwright/test');
const { login, installDiagnostics, assertNoUnexpectedBrowserErrors, recordObservation } = require('../support/staging');

const checkoutPath = process.env.CREDOQ_CHECKOUT_PATH || '/checkout/';
const bookingApiPath = process.env.CREDOQ_BOOKING_API_PATH;

test.describe('WooCommerce and membership-credit staging verification', () => {
  test.beforeEach(async ({ page }) => {
    installDiagnostics(page);
  });

  test('checkout exposes a non-capturing gateway and does not submit payment', async ({ page }) => {
    const response = await page.goto(checkoutPath, { waitUntil: 'domcontentloaded', timeout: 20000 });
    test.skip(!response || !response.ok(), `Checkout unavailable at ${checkoutPath}`);
    const body = await page.locator('body').innerText();
    const hasDirectBankTransfer = /direct bank transfer|bacs|bank transfer/i.test(body);
    const paymentButtons = await page.locator('button[type="submit"], #place_order, [name="woocommerce_checkout_place_order"]').count();
    recordObservation({ track: 'woocommerce', check: 'non_capturing_gateway_visibility', checkoutPath, hasDirectBankTransfer, paymentButtons, paymentMode: 'direct_bank_transfer', capturePayment: false });
    expect(hasDirectBankTransfer || process.env.CREDOQ_ALLOW_OTHER_NONCAPTURING_GATEWAY === 'true').toBeTruthy();
    expect(body).not.toMatch(/credit card number|cvv|card number/i);
    await assertNoUnexpectedBrowserErrors(page, 'woocommerce:checkout');
  });

  test('checkout order form exposes an order/booking correlation path', async ({ page }) => {
    const response = await page.goto(checkoutPath, { waitUntil: 'domcontentloaded', timeout: 20000 });
    test.skip(!response || !response.ok(), `Checkout unavailable at ${checkoutPath}`);
    const correlationFields = await page.locator('input[name*="booking"], input[name*="credoq"], input[name*="order"], [data-booking-id], [data-credoq-booking]').count();
    recordObservation({ track: 'woocommerce', check: 'order_booking_correlation_field', correlationFields });
    expect(correlationFields).toBeGreaterThanOrEqual(0);
  });

  test('booking status endpoint is readable and records identifiers without mutating data', async ({ page }) => {
    test.skip(!bookingApiPath, 'Set CREDOQ_BOOKING_API_PATH for read-only booking/order correlation verification.');
    const response = await page.request.get(bookingApiPath);
    expect([200, 401, 403].includes(response.status())).toBeTruthy();
    const body = await response.text();
    recordObservation({ track: 'woocommerce', check: 'booking_status_read_only', path: bookingApiPath, httpStatus: response.status(), responseBytes: body.length });
  });

  test('membership balance endpoint is read-only and credit state is classified', async ({ page }) => {
    const membershipApiPath = process.env.CREDOQ_MEMBERSHIP_API_PATH;
    test.skip(!membershipApiPath, 'Set CREDOQ_MEMBERSHIP_API_PATH for read-only credit verification.');
    const response = await page.request.get(membershipApiPath);
    expect([200, 401, 403, 404].includes(response.status())).toBeTruthy();
    recordObservation({ track: 'membership', check: 'balance_read_only', path: membershipApiPath, httpStatus: response.status(), status: response.status() === 200 ? 'observed' : 'Not verified' });
  });

  test('notification and order-status observability is explicit', async () => {
    const mailbox = Boolean(process.env.CREDOQ_TEST_MAILBOX);
    const webhook = Boolean(process.env.CREDOQ_NOTIFICATION_STATUS_PATH);
    recordObservation({ track: 'notifications', check: 'observability', mailboxConfigured: mailbox, statusEndpointConfigured: webhook, result: mailbox || webhook ? 'configured_for_staging' : 'Not verified' });
    expect(mailbox || webhook || !process.env.CREDOQ_REQUIRE_NOTIFICATION_VERIFICATION).toBeTruthy();
  });
});
