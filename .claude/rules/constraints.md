---
paths:
  - "src/**/*.php"
  - "tests/**/*.php"
---

# Constraints

- Do NOT use Guzzle directly — only `Illuminate\Support\Facades\Http`
- Do NOT hardcode API keys — everything via config/env
- Do NOT duplicate contracts from `isapp/laravel-cashier-support` — import them
- Do NOT change method signatures from contracts
- Do NOT use float for money
- Do NOT perform synchronous long-running operations without a queue
- Do NOT build workarounds for unsupported Revolut features — throw `UnsupportedOperationException`