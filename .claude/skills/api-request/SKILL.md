---
description: Make an HTTP request to the Revolut Merchant API via RevolutClient
---

# Revolut API Request

1. Use `RevolutClient` (wrapper over `Http::withHeaders(...)`)
2. Always send `Revolut-Api-Version` header
3. Base URL: sandbox `https://sandbox-merchant.revolut.com/api` / prod `https://merchant.revolut.com/api`
4. Authorization: `Authorization: Bearer {api_key}`
5. Map responses through Response DTO → Support DTO
6. **Check the operation's markdown spec before sending a field**:
   `https://developer.revolut.com/docs/api/merchant/operations/<operation>.md`
   (curl + browser User-Agent). A field the spec does not list is ignored by Revolut —
   the data is dropped silently and the code still looks like it works.
7. `Idempotency-Key` is honoured on the refund, the subscription create and usage records
   **only**. Orders and payments accept no key; a charge is deduplicated via
   `merchant_order_data.reference` + `GET /orders?merchant_order_data_reference=`.