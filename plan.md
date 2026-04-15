# Plan — isapp/laravel-cashier-revolut

## Фаза 1: Инфраструктура

- [ ] `src/CashierRevolutServiceProvider.php` — регистрация GatewayProvider, config, migrations, routes
- [ ] `src/config/cashier-revolut.php` — api_key, sandbox, webhook_secret, api_version, currency
- [ ] `src/Http/RevolutClient.php` — HTTP-клиент (Http::baseUrl, headers, retry, logging)
- [ ] `src/RevolutGateway.php` — implements GatewayProvider

## Фаза 2: Customers

- [ ] `database/migrations/add_revolut_columns_to_users_table.php` — revolut_customer_id, revolut_mandate_id
- [ ] `src/Concerns/ManagesRevolutCustomer.php` — createAsCustomer → POST /customers, asCustomer → GET /customers/{id}
- [ ] `src/Http/Requests/CreateCustomerRequest.php`
- [ ] `src/Http/Responses/CustomerResponse.php`

## Фаза 3: Charges (Single Payments)

- [ ] `src/Concerns/PerformsRevolutCharges.php` — charge → POST /orders + POST /orders/{id}/payments
- [ ] `src/Http/Requests/CreateOrderRequest.php`
- [ ] `src/Http/Requests/CreatePaymentRequest.php`
- [ ] `src/Http/Responses/OrderResponse.php`
- [ ] `src/Http/Responses/PaymentResponse.php`
- [ ] Refund → POST /orders/{id}/refund

## Фаза 4: Payment Methods

- [ ] `src/Concerns/ManagesRevolutPaymentMethods.php`
- [ ] Сохранение карты через Checkout Widget (save_payment_method flag)
- [ ] GET /customers/{id}/payment-methods
- [ ] DELETE /customers/{id}/payment-methods/{pm_id}

## Фаза 5: Checkout

- [ ] `src/Concerns/HandlesRevolutCheckout.php` — checkout → create order + return widget config
- [ ] Интеграция с Revolut Checkout Widget (JS)
- [ ] Blade component для widget'а

## Фаза 6: Subscriptions (свой движок)

- [ ] `database/migrations/create_revolut_subscriptions_table.php`
- [ ] `src/Models/RevolutSubscription.php` — extends abstract Subscription
- [ ] `src/Models/RevolutSubscriptionItem.php`
- [ ] `src/Builders/RevolutSubscriptionBuilder.php` — implements SubscriptionBuilder
- [ ] `src/Concerns/ManagesRevolutSubscriptions.php`
- [ ] Scheduled command: проверка due подписок, автоматический charge
- [ ] cancel(), resume(), swap(), onTrial(), onGracePeriod()

## Фаза 7: Invoices

- [ ] `src/Concerns/ManagesRevolutInvoices.php`
- [ ] Генерация PDF локально (dompdf или spatie/laravel-pdf)
- [ ] Хранение invoice records в БД

## Фаза 8: Webhooks

- [ ] `src/routes/webhook.php` — POST /revolut/webhook
- [ ] `src/Webhooks/RevolutWebhookController.php`
- [ ] `src/Webhooks/RevolutWebhookHandler.php` — implements WebhookHandler
- [ ] Верификация HMAC-SHA256 подписи
- [ ] Маппинг: ORDER_COMPLETED → PaymentSucceeded, ORDER_PAYMENT_FAILED → PaymentFailed

## Фаза 9: Artisan Commands

- [ ] `src/Commands/InstallCommand.php` — php artisan cashier-revolut:install
- [ ] `src/Commands/WebhookCommand.php` — php artisan cashier-revolut:webhook (регистрация в Revolut)

## Фаза 10: Тесты + CI

- [ ] Http::fake() fixtures для всех Revolut API endpoints
- [ ] Unit-тесты: RevolutClient, DTO mapping, webhook verification
- [ ] Feature-тесты: charge flow, subscription lifecycle, webhook handling
- [ ] PHPStan level 8 без ошибок
- [ ] README.md с документацией
