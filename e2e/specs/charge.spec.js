// @ts-check
const { test, expect } = require('@playwright/test');
const revolut = require('../lib/revolut');

/**
 * e2e: complete a real sandbox card payment through the Revolut hosted checkout, then confirm via
 * the API that the order reached a paid state. This is the browser step the PHP sandbox suite
 * cannot do (charge/refund/save-method/subscription-activation all depend on it).
 *
 * Amount is €15 — deliberately below Revolut's €30 3DS threshold, so the sandbox success card pays
 * frictionlessly (no 3DS challenge to automate). Verified sandbox card from Revolut docs:
 *   VISA 4929420573595709 · any 3-digit CVV · any future expiry (MM/YY).
 *
 * DOM shape (discovered against the live sandbox): after choosing "Pay with card", the cardholder
 * name + email inputs and the "Pay €N" submit live in the main frame, while the card number /
 * expiry / CVV / postcode inputs live in a cross-origin iframe served from card-field.html.
 *
 * Self-skips unless REVOLUT_SECRET_KEY and a truthy REVOLUT_SANDBOX are set (mirrors the PHP gate).
 */

const SUCCESS_VISA = '4929420573595709';
const CVV = '123';
// Digits only — the expiry input auto-inserts the "/" as you type.
const EXPIRY_DIGITS = '1230';

test.describe('Revolut hosted checkout — card payment', () => {
  test.skip(!revolut.sandboxConfigured(), 'Set REVOLUT_SECRET_KEY and REVOLUT_SANDBOX=1 to run.');

  test('pays a €15 order with a sandbox success card and the order completes', async ({ page }) => {
    const customer = await revolut.createCustomer(`e2e+${Date.now()}@example.test`);
    const order = await revolut.createOrder(1500, 'EUR', customer.id);
    expect(order.checkout_url, 'order must carry a hosted checkout_url').toBeTruthy();

    await page.goto(order.checkout_url, { waitUntil: 'domcontentloaded' });

    // Choose the card payment method, then wait for the card iframe to mount.
    await page.getByRole('button', { name: /pay with card/i }).click();

    try {
      await fillCard(page);
      await page.getByRole('button', { name: /pay €/i }).click();
    } catch (err) {
      await dumpStructure(page);
      throw err;
    }

    // Verify by API, not by DOM: poll the order until it reaches a paid state.
    const finalState = await pollOrderState(order.id, ['completed', 'authorised'], 60_000);
    expect(finalState, `order should reach a paid state (was "${finalState}")`).toMatch(
      /completed|authorised/,
    );
  });
});

async function fillCard(page) {
  // Main-frame fields.
  await page.getByPlaceholder('Cardholder name').fill('E2E Test');
  const email = page.getByPlaceholder('Email address');
  if (await email.count()) await email.first().fill('e2e-card@example.test');

  // Card number / expiry / CVV / postcode live in the card-field.html cross-origin iframe. These are
  // masked/formatted inputs driven by keystroke handlers, so a plain .fill() sets the value without
  // firing the events the widget listens for and the payment fails — type character by character.
  const card = page.frameLocator('iframe[src*="card-field.html"]');
  await typeInto(card.locator('input[name="number"]'), SUCCESS_VISA);
  await typeInto(card.locator('input[name="expiry"]'), EXPIRY_DIGITS);
  await typeInto(card.locator('input[name="code"]'), CVV);
  // The postcode field exists but is disabled for a EUR order — it is not required, so leave it.
}

async function typeInto(locator, value) {
  await locator.waitFor({ state: 'visible', timeout: 15_000 });
  await locator.click();
  await locator.pressSequentially(value, { delay: 60 });
}

async function pollOrderState(orderId, wanted, timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  let last = 'unknown';
  while (Date.now() < deadline) {
    const order = await revolut.getOrder(orderId);
    last = order.state;
    if (wanted.includes(order.state)) return order.state;
    if (order.state === 'failed' || order.state === 'cancelled') {
      throw new Error(`Order reached terminal non-paid state: ${order.state}`);
    }
    await new Promise((r) => setTimeout(r, 2_000));
  }
  return last;
}

/** On failure, print the frame + input structure so selectors can be re-checked. */
async function dumpStructure(page) {
  const lines = ['\n===== CHECKOUT STRUCTURE DUMP ====='];
  for (const frame of page.frames()) {
    lines.push(`FRAME ${frame.url().slice(0, 90)}`);
    try {
      const controls = await frame.evaluate(() =>
        [...document.querySelectorAll('input,select,button')].map((el) => ({
          tag: el.tagName,
          type: el.getAttribute('type'),
          name: el.getAttribute('name'),
          ph: el.getAttribute('placeholder'),
          text: (el.textContent || '').trim().slice(0, 30),
        })),
      );
      for (const c of controls) lines.push('  ' + JSON.stringify(c));
    } catch (e) {
      lines.push('  (frame not evaluable: ' + String(e).slice(0, 60) + ')');
    }
  }
  // eslint-disable-next-line no-console
  console.log(lines.join('\n'));
}
