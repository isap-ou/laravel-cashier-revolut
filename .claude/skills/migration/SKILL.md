---
description: Create or modify database migrations
---

# Migrations

1. Place in `database/migrations/` — publishable via ServiceProvider
2. This package ships NO migrations. The customer identity lives in the support package's `cashier_customers` table (morphed owner + provider + provider_id) — never a driver-named column on the app's own table.