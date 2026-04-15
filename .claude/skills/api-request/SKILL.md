---
description: Make an HTTP request to the Revolut Merchant API via RevolutClient
---

# Revolut API Request

1. Use `RevolutClient` (wrapper over `Http::withHeaders(...)`)
2. Always send `Revolut-Api-Version` header
3. Base URL: sandbox `https://sandbox-merchant.revolut.com/api` / prod `https://merchant.revolut.com/api`
4. Authorization: `Authorization: Bearer {api_key}`
5. Map responses through Response DTO → Support DTO