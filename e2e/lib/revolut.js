// @ts-check
// Minimal Revolut Merchant API helper for the e2e setup steps (create the order the browser then
// pays). Uses the same sandbox creds as the PHP sandbox suite. The secret is read from the
// environment and never logged.

const BASE = 'https://sandbox-merchant.revolut.com/api';
const VERSION = process.env.REVOLUT_API_VERSION || '2026-04-20';

function key() {
  return process.env.REVOLUT_SECRET_KEY || '';
}

/** Both gates, mirroring the PHP SandboxTestCase: a real key AND the sandbox switch explicitly on. */
function sandboxConfigured() {
  return key() !== '' && /^(1|true|on|yes)$/i.test(process.env.REVOLUT_SANDBOX || '');
}

async function api(method, path, body) {
  const res = await fetch(`${BASE}${path}`, {
    method,
    headers: {
      Authorization: `Bearer ${key()}`,
      'Revolut-Api-Version': VERSION,
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await res.text();
  let json = null;
  try {
    json = text ? JSON.parse(text) : null;
  } catch {
    json = text;
  }
  if (!res.ok) {
    throw new Error(`${method} ${path} -> ${res.status}: ${text}`);
  }
  return json;
}

module.exports = {
  sandboxConfigured,
  createCustomer: (email) => api('POST', '/customers', { full_name: 'E2E Charge', email }),
  createOrder: (amount, currency, customerId) =>
    api('POST', '/orders', {
      amount,
      currency,
      customer: { id: customerId },
      redirect_url: 'https://app.test/return',
      description: 'E2E charge',
    }),
  getOrder: (id) => api('GET', `/orders/${id}`),
};
