---
description: Implement a contract (interface) from isapp/laravel-cashier-support
argument-hint: "[ContractName]"
---

# Implement Contract

1. Create class in the appropriate directory (Concerns/, Builders/, Webhooks/)
2. `implements` interface from `Isapp\CashierSupport\Contracts\`
3. Map Revolut API responses → Support DTOs inside each method
4. Handle errors via Support Exceptions