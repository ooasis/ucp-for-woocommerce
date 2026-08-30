# UCP/ACP Agent for WooCommerce

Universal Commerce Protocol (UCP, v2026-04-08) merchant implementation for WooCommerce.
**Passes the official UCP conformance suite: 75/75 (2 expected skips).**

- `/.well-known/ucp` discovery profile with published ES256 signing keys (JWK, RFC 7638 kid)
- Checkout sessions with the real state machine (`incomplete → ready_for_complete → completed/canceled`) mapped to WC products, coupons, shipping zones, and orders
- RFC 9421 HTTP Message Signatures: inbound verification (when present) and signed webhooks — no dependencies beyond ext-openssl/ext-sodium
- Idempotency-Key replay + conflict detection, UCP-Agent version negotiation, structured UCP error envelopes
- Push order webhooks (full order entity, retried, signed) discovered from the platform profile
- **ACP dual-protocol layer** (OpenAI Agentic Commerce Protocol v2026-04-17): `/.well-known/acp.json` discovery, `/wp-json/acp/v1/checkout_sessions*` endpoints, Bearer-key auth (auto-generated, `ucpwc_acp_api_key` option), in-band `payment_declined` messages, and HMAC-SHA256 `Merchant-Signature` order webhooks — all translated onto the same session store and state machine that serves UCP
- **Product feeds** (`class-ucpwc-feed.php`): one catalog mapper, two serializations — the ACP standard feed (nested Product/Variant JSON, validated against the official `schema.feed.json`; JSONL snapshot endpoint + batched `PATCH /feeds/{id}/products` push with daily cron) and the OpenAI ChatGPT merchant feed (TSV with `is_eligible_search`/`is_eligible_checkout`, `item_group_id` variant grouping, length caps). Token-guarded read URLs, shown on the admin screen
- **Admin settings screen** (WooCommerce → UCP): endpoint/discovery status, UCP signing-key rotation with grace-period publication of retired keys, strict-signature mode, ACP API key display/regeneration, ACP webhook provisioning, Stripe keys, simulation secret
- **Real payment handlers** (`class-ucpwc-payments.php`): Google Pay via Stripe (UCP `com.google.pay` — GPay gateway token → PaymentMethod → confirmed PaymentIntent) and Stripe Shared Payment Token (ACP `dev.acp.tokenized.card` — `payment_method_data[shared_payment_granted_token]`). Declines map Stripe's 402 `card_error` to UCP envelopes / ACP in-band `payment_declined`; successful charges record the PaymentIntent id as the WC order transaction id. Stripe credentials come from plugin settings or the WooCommerce Stripe gateway; handlers are only advertised when configured (the mock handler only while a simulation secret is set). Unit checks: `tests/test-stripe-mapping.php`

## Conformance run

```sh
# WordPress+Woo dev site on SQLite (see ../wp/setup.sh), then:
cd ../wp && php -d memory_limit=512M -S localhost:8080 -t site router.php &
cd ../conformance && SERVER_URL=http://localhost:8080 SIMULATION_SECRET=test-secret uv run pytest -q
```

Fixtures: `../wp/fixtures.php` (flower_shop dataset; `wp eval-file fixtures.php`).

## Monetization rails

Freemius SDK is vendored but dormant: define `UCPWC_FS_ID` + `UCPWC_FS_PUBLIC_KEY` (from a Freemius dashboard product) to activate licensing. `ucpwc_can_premium($feature)` gates the future-Pro surfaces (`acp`, `stripe`, `strict_signatures`); until paid plans exist everything is free, so the split can be decided after real customers arrive. The free tier passes the full UCP conformance suite by design.

## Known ceilings (ponytail-marked in source)

- WBA-shape signature verification (RFC 9421 §2.1.2 dictionary members) not yet implemented
- Shipping: flat_rate / free_shipping / local_pickup with numeric costs; no cost formulas, no per-item free-shipping rules
- 3DS: a Stripe intent stuck in `requires_action` is declined rather than escalated via `continue_url`; wire escalation when a platform needs server-side 3DS
- Webhook delivery retries in-request; move to Action Scheduler for production
- No idempotency-table purge cron yet
