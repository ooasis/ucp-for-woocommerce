=== UCP/ACP Agent for WooCommerce ===
Contributors: sunh11373
Tags: ucp, agentic commerce, acp, ai agents, checkout
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Make your store buyable by AI agents: Universal Commerce Protocol (UCP) and OpenAI's Agentic Commerce Protocol (ACP) in one plugin.

== Description ==

UCP/ACP Agent for WooCommerce turns your store into a spec-compliant merchant for the two agentic-commerce standards:

* **UCP (Universal Commerce Protocol)** — the open standard by Google and Shopify used by Gemini and Google AI Mode. This plugin passes the official UCP conformance suite (75/75 tests, protocol version 2026-04-08).
* **ACP (Agentic Commerce Protocol)** — the OpenAI/Stripe standard used by ChatGPT (checkout currently limited to OpenAI-approved merchants; protocol version 2026-04-17).

What it implements:

* Discovery profiles at `/.well-known/ucp` and `/.well-known/acp.json` with published ES256 signing keys (JWK)
* Full checkout-session lifecycle mapped onto your real WooCommerce products, stock, coupons, and shipping zones — completed agent checkouts become normal WooCommerce orders
* RFC 9421 HTTP Message Signatures: signed webhooks, optional strict verification of inbound requests, key rotation with a grace period
* Idempotency, capability/version negotiation, and structured protocol errors on both rails
* Payments: Google Pay via Stripe (UCP) and Stripe Shared Payment Token (ACP), with declines surfaced to the agent; plus a mock handler for testing
* Signed order webhooks (RFC 9421 for UCP platforms, HMAC-SHA256 for ACP)
* Admin screen under WooCommerce → UCP: keys, rotation, strict mode, ACP provisioning, Stripe configuration

**Security model:** agent-facing checkout endpoints are public by design — that is how the UCP open standard works (any agent may discover and transact; authenticity is provided by HTTP Message Signatures, which you can enforce with strict mode). Orders are only created after payment succeeds, prices and stock are always server-authoritative, the ACP API requires a Bearer key, payment credentials are never stored or echoed, and the shipping-simulation test endpoint is disabled unless you configure a secret.

== External services ==

This plugin communicates with external services only in the following cases:

1. **Stripe (api.stripe.com)** — only when you configure a Stripe key (directly or via the WooCommerce Stripe gateway). When an AI agent completes a checkout with a Google Pay or Shared Payment Token instrument, the plugin sends the payment token, amount, and currency to Stripe to process the charge, and fetches your Stripe account id once for handler configuration. Subject to the [Stripe Services Agreement](https://stripe.com/legal/ssa) and [Privacy Policy](https://stripe.com/privacy).
2. **Google Pay** — only when Stripe is configured. The plugin publishes your Google Pay merchant configuration (store name, site host, Stripe merchant id, accepted card networks) in the UCP discovery document and checkout responses so that AI agents can obtain a Google Pay card token for the shopper. The plugin itself never contacts Google; the resulting token is charged through Stripe as described above. Subject to the [Google Pay API Terms of Service](https://payments.developers.google.com/terms/sellertos) and [Google Privacy Policy](https://policies.google.com/privacy).
3. **The AI platform contacting your store** — when an agent request carries a `UCP-Agent` header, the plugin fetches that platform's public profile URL to discover its webhook endpoint and signature keys, and sends order status webhooks (order contents, totals, shipping address) to the webhook URL the platform published or that you configured for ACP. This only happens for platforms that initiate contact with your store or that you configure explicitly. Terms and privacy policy are those of the platform in question.
4. **ACP Feed API push** — only when you enter a Feed API base URL, feed id, and API token on the settings screen. The plugin then sends your published product catalog (product id or SKU, title, description, URL, image URL, price, availability, GTIN, variant options, categories, and store name) to that URL, once when you click "Push now" and thereafter once a day via WP-Cron. No customer data is included. Terms and privacy policy are those of the platform whose Feed API you configure.

URLs under ucp.dev, acp.dev, pay.google.com, and developers.google.com appear in the plugin's discovery documents and payment handler declarations as protocol version identifiers, specification links, and JSON Schema references. They are never requested by the plugin.

No data is sent to the plugin author. No analytics or tracking of any kind.

== Frequently Asked Questions ==

= Does this make my products appear in Gemini or ChatGPT? =

It makes your store protocol-ready. Receiving Google surface traffic additionally requires a Google Merchant Center account; ChatGPT checkout additionally requires OpenAI merchant approval. Any UCP-speaking agent can transact with your store immediately.

= Do agents pay real money? =

Yes, when Stripe is configured: tokens supplied by the platform are charged through your Stripe account. Without Stripe, only the mock test handler is available, and only while a simulation secret is set.

= Does it work with HPOS (High-Performance Order Storage)? =

Yes, compatibility is declared and order access uses HPOS-safe APIs.

== Screenshots ==

1. Settings screen (WooCommerce → UCP): discovery status, signing-key rotation, ACP provisioning, Stripe payments, product feeds.
2. Agent-placed purchases arrive as normal WooCommerce orders.
3. Order detail with the Stripe PaymentIntent recorded as the transaction ID.
4. An AI shopping agent buying from the store over UCP: discovery, checkout, payment, signed webhooks.

== Changelog ==

= 0.1.0 =
* Initial release: UCP 2026-04-08 (official conformance suite: 75 passed), ACP 2026-04-17, RFC 9421 signatures, Stripe payment handlers, admin screen.
