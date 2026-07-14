# Graph Report - /Users/andrii/Projects/cashier/packages/isapp/laravel-cashier-revolut  (2026-07-14)

## Corpus Check
- cluster-only mode — file stats not available

## Summary
- 643 nodes · 1433 edges · 30 communities (26 shown, 4 thin omitted)
- Extraction: 91% EXTRACTED · 9% INFERRED · 0% AMBIGUOUS · INFERRED: 125 edges (avg confidence: 0.8)
- Token cost: 46,373 input · 442 output

## Graph Freshness
- Built from commit: `cdde5c06`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- Revolut Subscription Models
- Billable Traits and Contracts
- Checkout Sessions
- Customer and Payment Methods
- Test Setup and Enum Helpers
- Service Provider and Commands
- Package Changelog and Docs
- Subscription Lifecycle Operations
- Charges, Orders, and Refunds
- Webhook Handling and Routing
- Gateway Integration Tests
- Webhook Sync and Billing
- API Contract Tests
- Runtime Dependencies
- Composer Package Metadata
- Composer Scripts
- Package Keywords
- Dev Dependencies
- Laravel Provider Autodiscovery
- PSR-4 Autoload Config
- Test Autoload Config
- Support Links
- Subscription State Mapping
- Webhook Event Mapping
- Revolut Date Formats

## God Nodes (most connected - your core abstractions)
1. `RevolutApi` - 67 edges
2. `User` - 64 edges
3. `RevolutSubscription` - 39 edges
4. `RevolutGatewayTest` - 34 edges
5. `TestCase` - 29 edges
6. `WebhookSyncTest` - 25 edges
7. `RevolutApiContractTest` - 23 edges
8. `RevolutWebhookSynchronizer` - 22 edges
9. `OrderResponse` - 21 edges
10. `SubscriptionResponse` - 21 edges

## Surprising Connections (you probably didn't know these)
- `Plan — Phased Implementation Roadmap` --conceptually_related_to--> `Rule — Smart Stubs, No Custom Workarounds`  [AMBIGUOUS]
  plan.md → .claude/rules/smart-stubs.md
- `CI — CHANGELOG Enforcer` --references--> `CHANGELOG (laravel-cashier-revolut)`  [EXTRACTED]
  .github/workflows/changelog.yml → CHANGELOG.md
- `RELEASING — SemVer Tag Workflow` --references--> `CHANGELOG (laravel-cashier-revolut)`  [EXTRACTED]
  RELEASING.md → CHANGELOG.md
- `Skill — Webhook Handling` --references--> `RevolutWebhookHandler`  [EXTRACTED]
  .claude/skills/webhook/SKILL.md → CLAUDE.md
- `Who Announces What, Exactly Once` --references--> `RevolutWebhookHandler`  [EXTRACTED]
  README.md → CLAUDE.md

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Deferred Plan Change: Scheduled, Recorded, Landed on Paid Renewal** — readme_swap_deferred, changelog_scheduled_price_change, changelog_renewal_signal, claude_skills_subscriptions_skill, claude_rules_revolut_api [EXTRACTED 0.90]
- **Read-the-Spec Discipline Against Phantom Fields** — claude_rules_research_first, claude_rules_sources_of_truth, claude_rules_revolut_api, claude_agents_api_researcher_agent, changelog_phantom_fields [EXTRACTED 0.90]
- **Per-Operation Idempotency Strategy (header, order reference, or none)** — changelog_idempotency, claude_rules_revolut_api, claude_skills_api_request_skill, claude_revolut_connector, readme [EXTRACTED 0.85]

## Communities (30 total, 4 thin omitted)

### Community 0 - "Revolut Subscription Models"
Cohesion: 0.07
Nodes (13): Invoice, RevolutInvoice, RevolutSubscription, RevolutSubscriptionItem, Subscription, SubscriptionItem, IdempotencyTest, SubscriptionEventsTest (+5 more)

### Community 1 - "Billable Traits and Contracts"
Cohesion: 0.07
Nodes (32): Billable, Capability, Customer, DateTimeInterface, GatewayProvider, HandlesRevolutCheckout, HandlesRevolutWebhooks, InteractsWithRevolut (+24 more)

### Community 2 - "Checkout Sessions"
Cohesion: 0.08
Nodes (16): CheckoutMode, CheckoutRequest, CheckoutSession, Responsable, CarbonImmutable, Response, RevolutCheckoutSession, checkout() (+8 more)

### Community 3 - "Customer and Payment Methods"
Cohesion: 0.06
Nodes (25): Data, RevolutScheduledActionType, asCustomer(), createCustomer(), optionOrAttribute(), Customer, Model, addPaymentMethod() (+17 more)

### Community 4 - "Test Setup and Enum Helpers"
Cohesion: 0.07
Nodes (10): Orchestra, fromRevolut(), self, TestResponse, PendingPriceChangeTest, GatewayProvider, WebhookControllerTest, TestCase (+2 more)

### Community 5 - "Service Provider and Commands"
Cohesion: 0.09
Nodes (22): CashierException, Command, ConnectionException, Factory, ServiceProvider, CashierRevolutServiceProvider, WebhookCommand, guardConnection() (+14 more)

### Community 6 - "Package Changelog and Docs"
Cohesion: 0.07
Nodes (42): CHANGELOG (laravel-cashier-revolut), Customer Id Moved to cashier_customers (Polymorphic Owner), InvalidArgumentException vs SubscriptionUpdateFailure, Caller-Level Idempotency for Charge/Refund/Create, Phantom Fields Revolut Silently Ignores (metadata, quantity, customer_id), SubscriptionRenewed Reconstructed from ORDER_COMPLETED, Scheduled Plan Change Visible as Pending Price, Webhook Refused With 400 and a Log Line (+34 more)

### Community 7 - "Subscription Lifecycle Operations"
Cohesion: 0.13
Nodes (29): RevolutSubscriptionState, cancelAndRefetch(), cancelSubscription(), cancelSubscriptionNow(), changeReason(), currentCycle(), localSubscription(), newSubscription() (+21 more)

### Community 8 - "Charges, Orders, and Refunds"
Cohesion: 0.11
Nodes (19): Currency, Refund, RevolutOrderType, charge(), orderForReference(), Model, Payment, refund() (+11 more)

### Community 9 - "Webhook Handling and Routing"
Cohesion: 0.10
Nodes (11): InvalidConfigurationException, Request, parseWebhook(), WebhookPayload, LoggerInterface, Response, RevolutWebhookController, WebhookPayload (+3 more)

### Community 11 - "Webhook Sync and Billing"
Cohesion: 0.13
Nodes (14): BillingReason, InvoiceRecord, PersistsRevolutPlanVariation, RevolutWebhookEvent, SubscriptionDataResponse, CarbonImmutable, Invoice, LoggerInterface (+6 more)

### Community 12 - "API Contract Tests"
Cohesion: 0.19
Nodes (5): GatewayProvider, PaymentStatus, SubscriptionStatus, WebhookEvent, RevolutApiContractTest

### Community 13 - "Runtime Dependencies"
Cohesion: 0.14
Nodes (14): require, illuminate/console, illuminate/contracts, illuminate/database, illuminate/http, illuminate/routing, illuminate/support, isap-ou/laravel-enum-helpers (+6 more)

### Community 14 - "Composer Package Metadata"
Cohesion: 0.17
Nodes (11): authors, config, sort-packages, description, homepage, license, minimum-stability, name (+3 more)

### Community 15 - "Composer Scripts"
Cohesion: 0.22
Nodes (9): scripts, analyse, ci, format, lint, test, @analyse, @lint (+1 more)

### Community 16 - "Package Keywords"
Cohesion: 0.25
Nodes (8): keywords, billing, cashier, laravel, merchant-api, payments, revolut, subscriptions

### Community 17 - "Dev Dependencies"
Cohesion: 0.29
Nodes (7): require-dev, larastan/larastan, laravel/pint, mockery/mockery, orchestra/testbench, phpstan/phpstan, phpunit/phpunit

### Community 18 - "Laravel Provider Autodiscovery"
Cohesion: 0.50
Nodes (4): extra, laravel, providers, Isapp\\CashierRevolut\\CashierRevolutServiceProvider

### Community 19 - "PSR-4 Autoload Config"
Cohesion: 0.67
Nodes (3): autoload, psr-4, Isapp\\CashierRevolut\\

### Community 20 - "Test Autoload Config"
Cohesion: 0.67
Nodes (3): autoload-dev, psr-4, Isapp\\CashierRevolut\\Tests\\

### Community 21 - "Support Links"
Cohesion: 0.67
Nodes (3): support, issues, source

## Ambiguous Edges - Review These
- `Plan — Phased Implementation Roadmap` → `Rule — Smart Stubs, No Custom Workarounds`  [AMBIGUOUS]
  plan.md · relation: conceptually_related_to
- `Rule — No Copyleft Dependencies` → `Rule — Smart Stubs, No Custom Workarounds`  [AMBIGUOUS]
  .claude/rules/licensing.md · relation: conceptually_related_to

## Knowledge Gaps
- **59 isolated node(s):** `name`, `description`, `type`, `license`, `laravel` (+54 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **4 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **What is the exact relationship between `Plan — Phased Implementation Roadmap` and `Rule — Smart Stubs, No Custom Workarounds`?**
  _Edge tagged AMBIGUOUS (relation: conceptually_related_to) - confidence is low._
- **What is the exact relationship between `Rule — No Copyleft Dependencies` and `Rule — Smart Stubs, No Custom Workarounds`?**
  _Edge tagged AMBIGUOUS (relation: conceptually_related_to) - confidence is low._
- **Why does `TestCase` connect `Test Setup and Enum Helpers` to `Revolut Subscription Models`, `Billable Traits and Contracts`, `Checkout Sessions`, `Service Provider and Commands`, `Webhook Handling and Routing`, `Gateway Integration Tests`, `API Contract Tests`?**
  _High betweenness centrality (0.110) - this node is a cross-community bridge._
- **Why does `User` connect `Revolut Subscription Models` to `Billable Traits and Contracts`, `Checkout Sessions`, `Test Setup and Enum Helpers`, `Gateway Integration Tests`, `API Contract Tests`?**
  _High betweenness centrality (0.103) - this node is a cross-community bridge._
- **Why does `OrderResponse` connect `Charges, Orders, and Refunds` to `Webhook Sync and Billing`, `Checkout Sessions`, `Customer and Payment Methods`, `API Contract Tests`?**
  _High betweenness centrality (0.077) - this node is a cross-community bridge._
- **Are the 55 inferred relationships involving `RevolutApi` (e.g. with `.test_a_call_that_names_no_operation_still_gets_a_key()` and `.test_a_retried_refund_carries_the_key_it_was_given()`) actually correct?**
  _`RevolutApi` has 55 INFERRED edges - model-reasoned connections that need verification._
- **Are the 43 inferred relationships involving `User` (e.g. with `.test_a_charge_links_its_customer_the_same_way()` and `.test_a_deferred_swap_goes_through()`) actually correct?**
  _`User` has 43 INFERRED edges - model-reasoned connections that need verification._