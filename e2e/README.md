# e2e — Revolut Checkout Widget (local only, NOT in CI)

Browser-driven end-to-end tests for the flows the PHP sandbox suite cannot reach, because they
require paying in the Revolut Checkout Widget (a browser flow): completing a card payment, saving a
payment method, refunding a real payment, activating a subscription so it can be swapped.

**This tier is deliberately NOT wired into CI and does not run with `composer test`.** It lives
outside the PHP `tests/` suite, uses Node + Playwright, and is run **manually, on request**. It hits
the live Revolut sandbox and needs credentials, so it self-skips unless they are set.

## Run it

```bash
cd packages/isapp/laravel-cashier-revolut/e2e
npm install
npm run install-browser          # one-time: downloads the Chromium Playwright uses

REVOLUT_SECRET_KEY='sk_...sandbox...' REVOLUT_SANDBOX=1 npm test
# headed (watch it drive the browser):
REVOLUT_SECRET_KEY='sk_...sandbox...' REVOLUT_SANDBOX=1 npm run test:headed
```

Without `REVOLUT_SECRET_KEY` + a truthy `REVOLUT_SANDBOX`, every test self-skips (same gate as the
PHP `SandboxTestCase`). The secret is read from the environment and never logged.

## What it covers

- `specs/charge.spec.js` — creates a €15 order (below Revolut's €30 3DS threshold, so the sandbox
  success card pays frictionlessly), pays it on the hosted `checkout_url` with the documented
  sandbox VISA `4929420573595709`, and polls `GET /orders/{id}` until the order reaches a paid
  state. Verification is by API, not by scraping the page.

Test cards are Revolut's published sandbox cards
(https://developer.revolut.com/docs/guides/accept-payments/get-started/test-implementation/test-cards)
— public test data, not real card details.

## Notes / limits

- Inherently slower and flakier than unit tests (drives a third-party hosted page). Keep it out of
  the fast feedback loop.
- Inbound webhooks (e.g. `ORDER_COMPLETED`) are not asserted here — they need a public tunnel. The
  payment outcome is confirmed by polling the order instead.
- 3DS challenge automation is intentionally avoided by staying under the 3DS amount threshold.
