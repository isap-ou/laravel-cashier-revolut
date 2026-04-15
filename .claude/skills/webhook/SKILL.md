---
description: Handle Revolut webhook events
---

# Webhook Handling

1. Signature verification in `RevolutWebhookHandler::verifySignature()` (HMAC-SHA256)
2. Map Revolut events → Support `WebhookEvent` enum
3. Dispatch Support Events (PaymentSucceeded, SubscriptionCreated, etc.)