# cg-cms Commerce: implementation plan

Turning cg-cms into a CMS that can run a shop: catalogue, cart, checkout, payments, stock,
orders, refunds, shipping, tax and the admin to run it all. Written against the package as it
stands at `96eb59d`, in production on chrisgarlick.com. Roles and two-factor sign-in are the
one part of the existing roadmap not yet built, and C3 depends on them.

## 1. The constraint that shapes everything

cg-cms is fast because public pages are cookie-free HTML on disk, served by nginx without PHP
starting. A shop wants three things that fight that: a cart (per-visitor state), live stock
(changes constantly) and checkout (authenticated, uncacheable, money-moving).

The plan keeps the property rather than abandoning it:

| Surface | How it is served | Why |
|---|---|---|
| Product, category and listing pages | Page cache, as now | 99% of shop traffic is browsing. It stays free |
| Price and stock *band* on those pages | Baked into the cached HTML | Purged only when a band changes (in stock, low, out), not on every sale |
| Cart | Client-side (`localStorage`), no server state, no cookie | The cart is a list of variant IDs and quantities. The server never trusts it, so it does not need to hold it |
| Live stock and price check | `POST /shop/api/quote`, JSON, uncached, no session | Called when the cart drawer opens and before checkout. Reprices everything server-side |
| Checkout | Stripe Checkout (hosted), reached from `POST /checkout` | No card data on our origin, SCA/3DS, Apple and Google Pay for free, PCI scope SAQ A |
| Order confirmation | `/order/{uuid}?sig=` signed URL, `no-store` | Same pattern as the planned signed preview URL |
| Webhooks | `POST /shop/webhooks/{gateway}`, no session, no CSRF, signature verified | The source of truth for "paid" |
| Customer accounts (later phase) | Own cookie scope on `/account`, like `/admin` | Keeps the public site cookie-free |

The nginx bypass rule already keys on the session cookie. Nothing in the shop sets one
outside `/account` and `/admin`, so the existing cache rules hold unchanged.

## 2. Decisions to take

| # | Decision | Recommended | Alternative |
|---|---|---|---|
| 1 | Where the code lives | `Cg\Cms\Commerce` inside this package, off by default (`commerce.enabled`), migrations loaded only when enabled | A separate `cg-cms-commerce` package. Cleaner boundary, but the admin is one Vite build and splitting it doubles the asset pipeline |
| 2 | Payment provider | Stripe first, behind a `PaymentGateway` interface | Mollie, PayPal or GoCardless later, as further gateway classes |
| 3 | Checkout UI | Stripe Checkout (hosted page) | Payment Element embedded on our page. Nicer brand fit, but adds Stripe JS and a CSP exception on our origin, and PCI moves from SAQ A to SAQ A-EP |
| 4 | Where products live | Content in `entries` (a `product` collection), commerce data in relational tables keyed to the entry | All in jsonb. Rejected: money and stock need constraints, transactions and row-level atomic updates, which jsonb does badly |
| 5 | Money | Integer minor units plus currency code. A small `Money` value object, no float anywhere | `brick/money`. Fine, but one more dependency for what is GBP-only in v1 |
| 6 | Currency and tax | GBP only, prices entered VAT-inclusive, UK VAT by tax class | Multi-currency and Stripe Tax later, behind the same `TaxCalculator` interface |
| 7 | Customers | Guest checkout in v1, accounts in Phase C5 | Accounts from day one. More surface, more GDPR, little value for a small shop |

## 3. Data model

New tables, all prefixed by `table_prefix` like the existing ones. Money columns are
`bigint` minor units. Every order-side table is append-mostly and never hard-deleted: HMRC
wants six years of records.

### Catalogue

- **`product` collection** (schema, not a table). Title, slug, description (rich text),
  blocks, gallery, SEO, category relation. Everything editorial, versioned by the existing
  revisions system.
- **`category` collection**. Ordinary entries; listing pages cached with `collection:product`
  dependencies as now.
- **`commerce_products`**: `entry_id` (unique FK to entries), `type` (physical, digital),
  `tax_class`, `track_stock` bool, `allow_backorder` bool, `shipping_class`, `weight_g`.
- **`commerce_options`** and **`commerce_option_values`**: Size: S, M, L. Colour: Red, Blue.
- **`commerce_variants`**: `product_id`, `sku` (unique), `price`, `compare_at_price`,
  `cost_price`, `currency`, `gtin`, `weight_g` override, `option_value_ids`, `position`,
  `active`. A product with no options has exactly one variant, so everything downstream
  deals only in variants.

### Stock

- **`commerce_stock_levels`**: `variant_id` (PK), `on_hand`, `reserved`, `low_threshold`.
  Check constraint `reserved >= 0` and `on_hand - reserved >= 0` unless the product allows
  backorder (enforced in the reserve query rather than the constraint, see below).
- **`commerce_stock_movements`**: append-only ledger. `variant_id`, `delta`, `reason`
  (sale, refund_restock, adjustment, receipt, correction), `order_id` nullable, `user_id`
  nullable, `note`, `created_at`. `on_hand` is the running sum, kept denormalised on
  `stock_levels` and checked nightly by `cms:shop-stock-audit`.
- **`commerce_reservations`**: `variant_id`, `order_id`, `quantity`, `expires_at`,
  `released_at`, `converted_at`.

### Orders and payments

- **`commerce_orders`**: `id`, `uuid` (public), `number` (from a Postgres sequence, shown to
  customers as `CG-10042`), `status`, `email`, `currency`, `subtotal`, `discount_total`,
  `shipping_total`, `tax_total`, `grand_total`, `billing_address` jsonb,
  `shipping_address` jsonb, `shipping_method` jsonb snapshot, `customer_id` nullable,
  `placed_at`, `paid_at`, `fulfilled_at`, `cancelled_at`, `meta` jsonb.
- **`commerce_order_lines`**: snapshotted at purchase: `variant_id` (nullable FK, survives
  deletion), `sku`, `title`, `options_label`, `unit_price`, `quantity`, `discount`,
  `tax_rate`, `tax_amount`, `line_total`, `quantity_refunded`, `quantity_fulfilled`.
- **`commerce_payments`**: `order_id`, `gateway`, `gateway_ref` (unique), `amount`,
  `status`, `raw` jsonb.
- **`commerce_refunds`**: `order_id`, `payment_id`, `amount`, `reason`, `restock` bool,
  `gateway_ref`, `lines` jsonb, `user_id`.
- **`commerce_fulfilments`**: `order_id`, `carrier`, `tracking_number`, `tracking_url`,
  `lines` jsonb, `shipped_at`.
- **`commerce_order_events`**: the order's audit log and admin timeline. Every state
  change, email sent, note added and webhook applied.
- **`commerce_webhook_events`**: `gateway`, `event_id` (unique), `type`, `payload`,
  `processed_at`, `error`. The unique key is the idempotency guarantee.

### Pricing rules

- **`commerce_discounts`**: `code` (unique, case-insensitive), `type` (percent, fixed,
  free_shipping), `value`, `min_subtotal`, `starts_at`, `ends_at`, `usage_limit`,
  `usage_limit_per_email`, `applies_to` jsonb (product or category IDs), `active`.
- **`commerce_discount_redemptions`**: `discount_id`, `order_id`, `email`, `amount`.
- **`commerce_shipping_zones`** (countries list) and **`commerce_shipping_rates`**:
  `zone_id`, `name`, `type` (flat, weight_band, free_over), bands jsonb, `price`.
- **`commerce_tax_rates`**: `tax_class` (standard, reduced, zero, exempt), `country`,
  `rate` (basis points, 2000 = 20%).

### Customers (Phase C5)

- **`commerce_customers`**: separate from admin `users` on purpose. Email, name, password
  nullable (guests become customers with no password), addresses jsonb, `marketing_opt_in`
  with timestamp and source.

## 4. The core flows

### 4.1 Reserving stock without oversell

One atomic statement per line, no explicit lock, no read-then-write race:

```sql
update commerce_stock_levels
   set reserved = reserved + :qty
 where variant_id = :id
   and (on_hand - reserved >= :qty or :allow_backorder)
returning on_hand - reserved as available
```

Zero rows back means not enough stock. All lines reserve inside one transaction, so a cart
either reserves completely or not at all. Postgres row locks serialise concurrent buyers of
the same variant. Tested with two real connections racing for the last unit.

### 4.2 Checkout

1. Browser posts the `localStorage` cart, email and chosen shipping country to
   `POST /checkout`. Guarded by the existing `FormGuard` (token, honeypot, rate limit).
2. `Checkout\Quote` reprices from the database: variant prices, discounts, shipping, VAT.
   The client's numbers are display only and never read.
3. In one transaction: create the order (`pending_payment`), snapshot lines, reserve stock
   (4.1) with `expires_at = now + 35 min`, record the discount redemption as provisional.
4. `PaymentGateway::createCheckout($order)` creates a Stripe Checkout Session with the
   order UUID in `client_reference_id` and metadata, `expires_at` 30 minutes out, and an
   idempotency key of `order:{uuid}`.
5. 303 redirect to Stripe.

### 4.3 Payment confirmation

Webhooks are the truth. The return URL is only a nicer page.

1. `POST /shop/webhooks/stripe` verifies the signature, inserts into
   `commerce_webhook_events` (a duplicate event ID is a no-op 200) and returns 200
   immediately.
2. `ProcessWebhookEvent` job, on the database queue: `checkout.session.completed` with
   `payment_status=paid` marks the order paid, converts reservations into `sale` stock
   movements (`on_hand -= qty`, `reserved -= qty`), confirms the discount redemption,
   writes events and queues the confirmation email.
3. `checkout.session.expired` cancels the order and releases reservations.
4. `cms:shop-release-reservations`, every five minutes, releases anything past
   `expires_at` in case a webhook never arrives.
5. `cms:shop-reconcile`, hourly, asks Stripe for sessions completed in the last 24 hours
   and applies any the webhook path missed. Catches a webhook outage during a deploy.

The return page `/order/{uuid}?sig=` shows "Payment received" if the webhook has landed,
and "Confirming your payment" with a short poll if not. It never marks anything paid itself.

### 4.4 Order state machine

```
pending_payment ─▶ paid ─▶ partially_fulfilled ─▶ fulfilled
       │            │                                  │
       ▼            ▼                                  ▼
   cancelled   refunded / partially_refunded ◀─────────┘
```

Transitions live in one `OrderStateMachine` class. An illegal transition throws, every
legal one writes an `order_events` row. Payment status and fulfilment status are tracked
separately on the order, because "paid and half shipped and partly refunded" is a real
state and one enum cannot hold it.

### 4.5 Refunds

From the admin: choose lines and quantities (or an arbitrary amount), tick "restock".
`PaymentGateway::refund()` is called with an idempotency key, the refund row is written on
the gateway's answer, restock writes `refund_restock` movements. `charge.refunded` webhooks
from refunds made directly in the Stripe dashboard are applied too, so the two never drift.

### 4.6 Stock and the page cache

Purging a product page on every sale would make a busy product permanently uncached. So:

- The cached page shows a **band**: `in_stock`, `low_stock` ("Only a few left"),
  `out_of_stock`, `backorder`. Never an exact count.
- `StockLevelObserver` computes the band before and after each change and purges the
  entry tag **only when the band changes**. That reuses `CachePurger::purge()` and the
  warm-after-purge job exactly as a content edit does.
- Price changes purge the product entry and `collection:product`, so listings update.
- The cart drawer and checkout always call `/shop/api/quote`, so a stale band can never
  sell what is not there. Worst case a visitor sees "In stock" and is told at the cart
  that it just sold out.

### 4.7 Digital products

`type = digital` skips shipping. On payment, a `commerce_downloads` row is created per line
with a signed, expiring URL (7 days, 5 downloads), served by PHP streaming from the private
media disk, never from `public/`.

## 5. Package structure

```
src/Commerce/
  Money/Money.php, Currency.php
  Catalogue/ProductRepository.php, VariantResolver.php, StockBand.php
  Inventory/StockLedger.php, Reserver.php, StockLevelObserver.php
  Pricing/Quote.php, QuoteLine.php, DiscountEngine.php, ShippingCalculator.php,
          TaxCalculator.php, UkVatCalculator.php
  Checkout/CheckoutController.php, QuoteController.php, CheckoutService.php
  Orders/OrderStateMachine.php, OrderService.php, RefundService.php, FulfilmentService.php
  Payments/PaymentGateway.php (interface), StripeGateway.php, FakeGateway.php,
           WebhookController.php, StripeEventHandler.php
  Notifications/OrderConfirmed.php, OrderShipped.php, OrderRefunded.php, LowStockAlert.php
  Models/...
  Seo/ProductBuilder.php, MerchantFeedWriter.php
  Console/ReleaseReservations.php, Reconcile.php, StockAudit.php, ImportProducts.php
  Jobs/ProcessWebhookEvent.php, SendOrderEmail.php
database/migrations/commerce/   (loaded only when commerce.enabled)
routes/shop.php                 (public, no session middleware)
resources/views/shop/           (cart drawer partial, minimal checkout form, emails)
resources/js/shop/cart.ts       (~3KB, no framework: localStorage cart, quote fetch, drawer)
```

`stripe/stripe-php` becomes a `suggest`, required only by `StripeGateway`, so a site without
a shop installs nothing new.

Config (`config/cg-cms.php`, scalars only so `config:cache` keeps working):

```php
'commerce' => [
    'enabled' => env('CG_COMMERCE_ENABLED', false),
    'currency' => 'GBP',
    'prices_include_tax' => true,
    'gateway' => env('CG_COMMERCE_GATEWAY', 'stripe'),
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],
    'reservation_minutes' => 35,
    'low_stock_default' => 5,
    'order_number_prefix' => 'CG-',
    'notify_email' => env('CG_COMMERCE_NOTIFY_EMAIL'),
    'routes' => ['checkout' => 'checkout', 'order' => 'order', 'api' => 'shop/api'],
],
```

Add `checkout`, `order/*` and `shop/*` to `page_cache.except` and `seo.exclude` when
enabled. The provider can merge them in so a site cannot forget.

## 6. Schema and editor integration

Products stay ordinary entries so they keep revisions, autosave, lint, SEO checks, blocks
and the page cache for free. The commerce data sits beside them:

```php
Collection::make('product')
    ->route('/shop/{slug}')
    ->indexRoute('/shop')
    ->commerce()                       // marks it as a product collection
    ->schemaOrg(ProductBuilder::class)
    ->seoImageFrom('gallery')
    ->fields([
        Field::text('title')->required(),
        Field::slug('slug')->from('title'),
        Field::media('gallery')->many()->requireAlt(),
        Field::richText('description'),
        Field::relation('categories')->to('category')->many(),
        Field::blocks('content')->allow(Block::builtIns()),
    ]);
```

`->commerce()` adds a **Commerce** tab to the entry editor, rendered by a new
`CommerceTab.tsx` rather than the field registry, because variants, prices and stock are
written to the relational tables and not to `data`. It saves through its own endpoint, in
its own transaction, so a content autosave can never touch a price.

Publishing rules added to the existing publish-blocking checks: a product cannot be
published with no active variant, a variant with no price, or a gallery with no alt text.

## 7. Admin screens

| Screen | What it does |
|---|---|
| **Orders** | List with status filters, search by number, email or SKU. Detail page: lines, totals, addresses, payment, timeline from `order_events`, actions (fulfil, refund, cancel, resend email, add note). CSV export |
| **Products** | The existing collection index with price, stock and variant count columns added |
| **Inventory** | Every variant with on hand, reserved, available. Inline adjust with required reason. Bulk CSV import. Movement history per variant |
| **Discounts** | CRUD, redemption counts |
| **Shipping and tax** | Zones, rates, tax classes. Lives under Settings |
| **Customers** (C5) | List, order history, GDPR export and erase (erase anonymises orders, never deletes them) |
| **Dashboard widgets** | Today's revenue, orders to fulfil, low stock, failed webhooks |

Two prerequisites from the existing roadmap are needed first: **roles** (section 5.11 of
the CMS plan) gain a `shop_manager` role and policy per screen, and **2FA** becomes
mandatory for any role that can refund.

## 8. SEO and feeds

- `ProductBuilder` emits `Product` with `Offer` or `AggregateOffer` (price, currency,
  `availability` from the stock band, `itemCondition`, `gtin`, `sku`, `brand`), plus
  `shippingDetails` and `hasMerchantReturnPolicy` from settings, so products qualify for
  Google merchant listings.
- An out-of-stock product stays a 200 with `OutOfStock`. A deleted product gets a 301 to
  its category through the existing redirects module rather than a 404.
- `MerchantFeedWriter` writes `public/feeds/google-merchant.xml` the same way
  `SeoFileWriter` writes the sitemap: queued, debounced, served by nginx.
- `SeoChecks` gains product checks: missing GTIN warns, missing price blocks publish.

## 9. Email

Queued Mailables, Blade views overridable by the host app: order confirmation (with VAT
breakdown, which doubles as the VAT receipt), dispatch with tracking, refund, download
links, and to the shop owner a new-order and low-stock alert. Every send writes an
`order_events` row so "did they get the email" is answerable from the admin.

## 10. Security and compliance

- **PCI**: hosted Checkout keeps card data off the server entirely (SAQ A).
- **Webhooks**: signature verified with a tolerance window, raw body read before any
  middleware touches it, processed idempotently by event ID.
- **Never trust the client**: every price, discount and total is recomputed server-side.
  The cart sends IDs and quantities only.
- **Idempotency keys** on every gateway call that moves money.
- **Rate limits** on `/checkout`, `/shop/api/quote` and discount code attempts (stops code
  enumeration).
- **UK law**: Consumer Contracts Regulations (14-day cancellation for distance sales,
  pre-contract information on the checkout page), VAT invoices on request, six-year record
  retention, GDPR erase as anonymisation. Terms, returns and privacy pages are content
  entries; the checkout page links them and records the version agreed to on the order.
- **Admin**: refunds and stock adjustments require a reason and are attributed to a user.

## 11. Performance on the 1GB box

- Browsing stays nginx-only. PHP runs for quote, checkout, webhooks and the admin.
- Webhook handler does one insert and returns; the work happens on the database queue
  worker that already exists.
- `cart.ts` is loaded with `defer`, framework-free, budgeted at under 4KB gzipped, and is
  the only JavaScript a product page gains.
- Indexes: `orders (status, placed_at desc)`, `orders (email)`, `order_lines (variant_id)`,
  `stock_movements (variant_id, created_at)`, partial index on reservations
  `where released_at is null and converted_at is null`.
- A sale-day spike is bounded by Stripe's hosted page: our origin serves one POST and one
  webhook per order.

## 12. Testing

Postgres throughout, as now.

- Unit: `Money`, `Quote`, `DiscountEngine`, `UkVatCalculator` (rounding per line, then
  summed, matching HMRC guidance), `OrderStateMachine` transition table.
- Concurrency: two connections racing to reserve the last unit; exactly one wins.
- Webhooks: replayed event is a no-op; out-of-order events (`expired` after `completed`)
  do not undo a paid order; bad signature is a 400 and writes nothing.
- Cache: a sale that keeps the band purges nothing; a sale that crosses the low threshold
  purges exactly that product's URLs and the listings.
- Session test extended: no route in `routes/shop.php` starts a session or sets a cookie.
- `FakeGateway` drives full checkout to paid to refunded with no network.
- Stripe test mode end-to-end run with `stripe listen` before each release.
- Smoke: the Commerce tab builds in jsdom alongside the editor.

## 13. Phases

Estimates in the same terms as the CMS roadmap. Roles and 2FA from the existing Phase 3
should land before C3.

### C0: Foundations, 3 days
`Money`, config block, enable flag, migration loading, `routes/shop.php` with the
no-session test, `PaymentGateway` interface with `FakeGateway`.

### C1: Catalogue and stock, 1.5 weeks
Product tables, variants and options, `->commerce()` collection flag, Commerce tab in the
editor, stock levels and ledger, band computation and band-change purging, `ProductBuilder`
JSON-LD. **Exit:** products can be authored, published and cached with correct
availability.

### C2: Cart, quote and checkout, 2 weeks
`cart.ts`, `/shop/api/quote`, shipping zones and rates, UK VAT, discount engine, reservation,
`StripeGateway`, webhook ingestion and processing, release and reconcile commands, signed
order page, confirmation emails. **Exit:** a real test-mode order goes from product page to
paid with stock decremented, and survives a replayed and a missed webhook.

### C3: Orders admin, 2 weeks
Orders list and detail, timeline, fulfilment with tracking and the dispatch email, refunds
(partial, with restock), cancellation, inventory screen and adjustments, discount CRUD,
shipping and tax settings, dashboard widgets, `shop_manager` role. **Exit:** a shop can be
run day to day without opening the Stripe dashboard.

### C4: SEO, feeds and hardening, 1 week
Merchant feed, product SEO checks, stock audit command, low-stock alerts, product CSV
import, security audit pass, load test of quote and checkout on the droplet.

### C5: Customers and growth, 2 weeks, optional
Customer accounts on `/account` with their own cookie scope, order history, saved
addresses, GDPR export and erase, digital downloads, abandoned checkout emails (needs the
email captured at step 1 of checkout and explicit consent).

### Later, only if a client needs it
Multi-currency, Stripe Tax for non-UK VAT and US sales tax, subscriptions (Stripe Billing,
which changes the order model enough to be its own plan), gift cards, product bundles,
multi-location stock, marketplace integrations.

**Total to a sellable v1 (C0 to C4): about 7 weeks.**

## 14. Risks

| Risk | Mitigation |
|---|---|
| Oversell under concurrency | Atomic conditional update, tested with real concurrent connections |
| Missed webhooks during deploy or outage | Reconcile command, idempotent processing, admin widget for failed events |
| Stale stock shown on cached pages | Bands not counts, purge on band change, live quote before any money moves |
| Price changed between cart and checkout | Quote recomputed at checkout; the checkout page shows the server's total, not the cart's |
| Scope creep into a Shopify clone | The "later" list is explicit. Anything on it waits for a paying client |
| Package grows heavy for non-shop sites | Off by default, no migrations or routes when disabled, Stripe SDK only suggested |

## 15. Open questions

1. Is the first shop physical goods, digital, or both? Digital moves C5's downloads into C2.
2. Is the seller VAT-registered? If not, tax is zero-rated throughout and C2 shrinks.
3. Stripe confirmed, or does a client need PayPal as well? PayPal would be a second gateway
   in C2.
4. Do products need variants (size, colour) in v1, or are single-SKU products enough?
5. Ship outside the UK? International shipping is cheap to support; non-UK tax is not.
