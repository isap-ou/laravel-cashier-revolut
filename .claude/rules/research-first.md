# Research First — Never Trust Training Data

When working with any external API or third-party package:

- **ALWAYS** fetch and verify against official documentation before writing code, docs, or making claims
- **NEVER** rely on model training data for API capabilities, endpoints, parameters, or behavior
- **Use the `api-researcher` agent** (`.claude/agents/api-researcher/`) to look up official sources
- If official docs are unavailable, explicitly state that the information is unverified
- **Reference packages are read from disk, not remembered**: `vendor/laravel/cashier` (Stripe,
  primary) and `vendor/laravel/cashier-paddle` (second opinion) at the monorepo root.
  `vendor/mollie/laravel-cashier-mollie` is a last resort and not a design authority.

**The Revolut reference is fetchable as markdown** — one file per operation:
`https://developer.revolut.com/docs/api/merchant/operations/<operation>.md`. Fetch it with
curl and a browser User-Agent (`WebFetch` 403s, and the HTML pages are JS-rendered and
contain no field names). Details in `sources-of-truth.md`.

A field the docs do not list is not "probably fine": Revolut ignores it, so the data is
dropped in silence and the code looks like it works.

This applies to:
- Revolut Merchant API endpoints and capabilities
- Laravel Cashier Stripe method signatures and behavior
- Any third-party package APIs (spatie/laravel-data, moneyphp/money, etc.)
- Webhook events, statuses, enums from external services