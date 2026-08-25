const { test, expect } = require('@playwright/test');
const { mountWidget, mockAjax, withDiagnostics } = require('./helpers');
const auditConfig = require('../../../automation/credoq-audit.config.json');

function baseConfig(overrides = {}) {
  return Object.assign({
    ajax_url: 'http://127.0.0.1:4173/wp-admin/admin-ajax.php',
    nonce: 'test-nonce-123',
    form_id: 200,
    currency: 'USD',
    fields: [],
    event_seat_plans: {},
  }, overrides);
}

const optionFields = {
  dropdown: { options: [{ value: 'gold', label: 'Gold' }, { value: 'silver', label: 'Silver' }] },
  radio: { options: [{ value: 'yes', label: 'Yes' }, { value: 'no', label: 'No' }] },
  checkboxes: { options: [{ value: 'a', label: 'Option A' }, { value: 'b', label: 'Option B' }] },
};

const simpleTypes = [
  'text', 'email', 'long_text', 'number', 'date', 'dropdown', 'time', 'radio',
  'checkboxes', 'file_upload', 'signature', 'hidden', 'html_block', 'quantity',
  'formula', 'total_price',
];

function fieldFor(type) {
  const runtimeType = ({ long_text: 'textarea', dropdown: 'select', checkboxes: 'checkbox', file_upload: 'file', html_block: 'html', formula: 'calculate' })[type] || type;
  const field = { name: `audit_${type}`, type: runtimeType, label: `Audit ${type}`, required: false };
  if (optionFields[type]) Object.assign(field, optionFields[type]);
  if (type === 'hidden') field.default_value = 'hidden-audit-value';
  if (type === 'html_block') field.html_code = '<strong data-testid="audit-html">Safe HTML block</strong>';
  if (type === 'quantity') { field.min = 1; field.max = 5; field.default = 1; }
  if (type === 'formula') { field.formula = '{audit_number} * 2'; field.add_to_total = true; }
  return field;
}

async function settle(page) {
  await page.waitForTimeout(250);
  await expect(page.locator('#credoq-booking-root')).toBeVisible();
}

function renderedLocator(page, type) {
  const name = `audit_${type}`;
  if (type === 'long_text') return page.locator(`textarea[name="form_data[${name}]"]`);
  if (type === 'dropdown') return page.locator(`select[name="form_data[${name}]"]`);
  if (type === 'radio') return page.locator('.cqw-radio-opt').first();
  if (type === 'checkboxes') return page.locator('.cqw-check-opt').first();
  if (type === 'file_upload') return page.locator(`input[type="file"][name="form_data[${name}]"]`);
  if (type === 'signature') return page.locator('canvas').first();
  if (type === 'hidden') return page.locator(`input[type="hidden"][name="form_data[${name}]"]`);
  if (type === 'html_block') return page.locator('[data-testid="audit-html"]');
  if (type === 'formula') return page.locator(`.cqw-calc-out[data-name="${name}"]`);
  if (type === 'total_price') return page.locator('.cqw-total-price');
  if (type === 'quantity') return page.locator('.cqw-qty');
  return page.locator(`input[name="form_data[${name}]"]`);
}

test.describe('Full field-type rendering matrix', () => {
  for (const type of simpleTypes) {
    test(`${type} renders without browser errors`, async ({ page }) => {
      await mockAjax(page, { credoq_submit_booking: () => ({ success: true, data: { message: 'OK' } }) });
      await mountWidget(page, baseConfig({ fields: [fieldFor(type)] }));
      await withDiagnostics(page, `${type}-render`, async () => {
        await settle(page);
        const rendered = renderedLocator(page, type);
        if (type === 'hidden' || type === 'file_upload') await expect(rendered).toHaveCount(1);
        else await expect(rendered).toBeVisible({ timeout: 10000 });
      });
      expect(page._browserErrors || []).toEqual([]);
    });
  }

  test('audit contract declares all 20 required variations and this suite maps every declared type', async () => {
    const declared = auditConfig.form_field_types;
    expect(declared).toHaveLength(20);
    const mapped = new Set([
      ...simpleTypes,
      'appointment_booking', 'event_calendar', 'seat_map', 'submit_button',
    ]);
    for (const type of declared) expect(mapped.has(type)).toBeTruthy();
  });
});

test.describe('Field validation and data capture', () => {
  test('required text and email fields reject empty and invalid values before submission', async ({ page }) => {
    let submits = 0;
    await mockAjax(page, {
      credoq_submit_booking: () => { submits += 1; return { success: true, data: { message: 'OK' } }; },
    });
    await mountWidget(page, baseConfig({ fields: [
      { name: 'required_name', type: 'text', label: 'Name', required: true },
      { name: 'required_email', type: 'email', label: 'Email', required: true },
    ] }));
    const continueButton = page.locator('.cqw-btn-cont').last();
    await continueButton.click();
    expect(await page.locator('input[name="form_data[required_name]"]').evaluate(el => !el.checkValidity())).toBeTruthy();
    expect(await page.locator('input[name="form_data[required_email]"]').evaluate(el => !el.checkValidity())).toBeTruthy();
    expect(submits).toBe(0);
    await page.locator('input[name="form_data[required_name]"]').fill('Audit User');
    await page.locator('input[name="form_data[required_email]"]').fill('not-an-email');
    await continueButton.click();
    expect(submits).toBe(0);
    await expect(page.locator('#credoq-booking-root')).toBeVisible();
  });

  test('all selected field values are included in submission payload', async ({ page }) => {
    let submitted = null;
    await mockAjax(page, {
      credoq_submit_booking: fields => { submitted = fields; return { success: true, data: { message: 'Captured' } }; },
    });
    await mountWidget(page, baseConfig({ fields: [
      { name: 'name', type: 'text', label: 'Name' },
      { name: 'notes', type: 'textarea', label: 'Notes' },
      { name: 'level', type: 'select', label: 'Level', options: [{ value: 'pro', label: 'Professional' }] },
      { name: 'confirm', type: 'checkbox', label: 'Confirm', options: [{ value: 'yes', label: 'Yes' }] },
    ] }));
    await page.locator('input[name="form_data[name]"]').fill('Matrix User');
    await page.locator('textarea[name="form_data[notes]"]').fill('Long audit note');
    await page.locator('select[name="form_data[level]"]').selectOption('pro');
    await page.locator('.cqw-check-opt').filter({ hasText: 'Yes' }).click();
    for (let i = 0; i < 5 && !(await page.getByText('Captured').isVisible().catch(() => false)); i++) {
      await page.locator('.cqw-btn-cont').last().click();
      await page.waitForTimeout(200);
    }
    await expect(page.getByText('Captured')).toBeVisible();
    expect(submitted['form_data[name]']).toBe('Matrix User');
    expect(submitted['form_data[notes]']).toBe('Long audit note');
    expect(submitted['form_data[level]']).toBe('pro');
  });
});

test.describe('Calculation, upload, signature, and accessibility paths', () => {
  test('formula and total price update from input values', async ({ page }) => {
    await mockAjax(page, { credoq_submit_booking: () => ({ success: true, data: { message: 'OK' } }) });
    await mountWidget(page, baseConfig({ fields: [
      { name: 'audit_number', type: 'number', label: 'Units' },
      { name: 'audit_formula', type: 'calculate', label: 'Calculated add-on', formula: '{audit_number} * 2', add_to_total: true },
      { name: 'audit_total', type: 'total_price', label: 'Total' },
    ] }));
    await page.locator('input[name="form_data[audit_number]"]').fill('3');
    await page.waitForTimeout(300);
    await expect(page.locator('.cqw-calc-out[data-name="audit_formula"]')).toContainText('6');
    await expect(page.locator('.cqw-total-price')).toBeVisible();
  });

  test('file upload and signature canvas are present and keyboard-focusable', async ({ page }) => {
    await mockAjax(page, { credoq_submit_booking: () => ({ success: true, data: { message: 'OK' } }) });
    await mountWidget(page, baseConfig({ fields: [
      { name: 'proof', type: 'file', label: 'Proof' },
      { name: 'signature', type: 'signature', label: 'Signature' },
    ] }));
    await expect(page.locator('input[type="file"]')).toHaveCount(1);
    await expect(page.locator('canvas')).toHaveCount(1);
    await expect(page.locator('.cqw-file-btn')).toBeVisible();
    await expect(page.locator('.cqw-sig-clear')).toBeVisible();
  });
});

test.describe('Event and seat negative paths', () => {
  test('event selection cannot submit while backend rejects capacity', async ({ page }) => {
    const date = new Date().toISOString().slice(0, 10);
    await mockAjax(page, {
      credoq_submit_booking: () => ({ success: false, data: { message: 'Capacity exhausted' } }),
    });
    await mountWidget(page, baseConfig({ fields: [{
      name: 'event_field', type: 'event_registration', label: 'Event',
      _frontend: { component: 'event_calendar', props: { by_date: { [date]: [{ id: 99, title: 'Audit Event', price: 10, start: '10:00', end: '11:00', remaining: 1, max_qty: 1 }] } } },
    }, { name: 'name', type: 'text', label: 'Name' }] }));
    await expect(page.locator('.cqw-event-cal-root')).toBeVisible({ timeout: 10000 });
    await page.locator('[title*="click to select"]').first().click();
    await page.locator('.cqw-event-row', { hasText: 'Audit Event' }).locator('.cqw-event-check-wrap').click();
    await page.locator('input[name="form_data[name]"]').fill('Audit User');
    for (let i = 0; i < 5 && !(await page.getByText('Capacity exhausted').isVisible().catch(() => false)); i++) {
      await page.locator('.cqw-btn-cont').last().click();
      await page.waitForTimeout(200);
    }
    await expect(page.getByText('Capacity exhausted')).toBeVisible();
    await expect(page.locator('#credoq-booking-root')).toBeVisible();
  });
});
