const { test, expect } = require('@playwright/test');

const appointmentHTML = `<!doctype html><html><body>
  <main id="credoq-booking-root">
    <h1>Appointment booking</h1>
    <form id="booking-form">
      <button type="button" data-date="2030-01-15">15 Jan 2030</button>
      <div id="slots" hidden><label><input type="radio" name="slot" value="10:00">10:00 AM</label></div>
      <input name="name" required aria-label="Name">
      <input name="email" type="email" required aria-label="Email">
      <output id="total">$10.00</output>
      <button type="submit">Book appointment</button>
    </form>
    <p id="status" role="status"></p>
  </main>
  <script>
    const date = document.querySelector('[data-date]');
    date.addEventListener('click', () => document.querySelector('#slots').hidden = false);
    document.querySelector('#booking-form').addEventListener('submit', event => {
      event.preventDefault();
      const form = event.currentTarget;
      if (!form.checkValidity() || !form.querySelector('input[name="slot"]:checked')) {
        document.querySelector('#status').textContent = 'Select a date, time, name, and email.';
        return;
      }
      window.location.href = '/checkout/?booking_id=AUDIT-TEST-20300115&total=10.00';
    });
  </script>
</body></html>`;

const checkoutHTML = `<!doctype html><html><body>
  <h1>Checkout</h1><p>Booking reference: AUDIT-TEST-20300115</p><p>Total: $10.00</p>
  <input name="billing_first_name"><input name="billing_last_name"><input name="billing_address_1"><input name="billing_city"><input name="billing_postcode">
  <input type="radio" name="payment_method" value="bacs">Direct bank transfer
  <input type="radio" name="payment_method" value="cod">Cash on delivery
  <button id="place_order" type="button">Place Order</button>
</body></html>`;

test.describe('Local simulated CredoQ appointment-to-checkout flow', () => {
  test('selects date and slot, submits appointment, and lands on non-capturing checkout', async ({ page }) => {
    await page.route('**/*', async route => {
      const url = route.request().url();
      if (url.includes('/checkout/')) return route.fulfill({ status: 200, contentType: 'text/html', body: checkoutHTML });
      return route.fulfill({ status: 200, contentType: 'text/html', body: appointmentHTML });
    });

    await page.goto('http://credoq.local/appointment/');
    await page.locator('[data-date="2030-01-15"]').click();
    await page.locator('input[name="slot"][value="10:00"]').check();
    await page.locator('input[name="name"]').fill('AUDIT TEST Visitor');
    await page.locator('input[name="email"]').fill('audit-test@example.test');
    await page.getByRole('button', { name: 'Book appointment' }).click();

    await page.waitForURL(/\/checkout\/\?booking_id=AUDIT-TEST-20300115/);
    expect(page.url()).toContain('booking_id=AUDIT-TEST-20300115');
    await expect(page.getByText('Total: $10.00')).toBeVisible();
    await expect(page.getByText('Direct bank transfer')).toBeVisible();
    await expect(page.getByText('Cash on delivery')).toBeVisible();
    await expect(page.locator('#place_order')).toBeVisible();
    expect(await page.locator('input[name="payment_method"]').count()).toBe(2);
  });

  test('blocks appointment submission when no slot is selected', async ({ page }) => {
    await page.route('**/*', route => route.fulfill({ status: 200, contentType: 'text/html', body: appointmentHTML }));
    await page.goto('http://credoq.local/appointment/');
    await page.locator('[data-date="2030-01-15"]').click();
    await page.locator('input[name="name"]').fill('AUDIT TEST Visitor');
    await page.locator('input[name="email"]').fill('audit-test@example.test');
    await page.getByRole('button', { name: 'Book appointment' }).click();
    await expect(page.locator('#status')).toHaveText(/Select a date/);
    expect(page.url()).not.toContain('/checkout/');
  });
});
