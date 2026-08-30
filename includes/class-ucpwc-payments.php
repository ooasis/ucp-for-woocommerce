<?php
defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages become JSON API payloads (UCP/ACP error envelopes), never HTML output.

/**
 * Payment handler registry serving both protocols.
 *
 * Handlers:
 *  - mock_payment_handler — token semantics for testing/conformance; advertised
 *    only while a simulation secret is configured (i.e. test mode).
 *  - google_pay (UCP com.google.pay) — Google Pay instruments tokenized with
 *    gateway=stripe; charged as a Stripe PaymentIntent.
 *  - card_tokenized (ACP dev.acp.tokenized.card) — Stripe Shared Payment
 *    Tokens from ChatGPT/OpenAI; charged via shared_payment_granted_token.
 *
 * Stripe credentials come from this plugin's options, falling back to the
 * WooCommerce Stripe gateway's settings when that plugin is configured.
 * Stripe handlers are advertised only when a secret key is available.
 */
class UCPWC_Payments
{
    /** Charge an instrument. Returns a PSP transaction id, or null (mock). Throws UCPWC_Error. */
    public static function charge(array $instrument, int $amount, string $currency): ?string
    {
        $cred = $instrument['credential'] ?? [];
        switch ($instrument['handler_id'] ?? '') {
            case 'mock_payment_handler':
                self::charge_mock($cred);
                return null;
            case 'google_pay':
                // Two calls: tok_ -> PaymentMethod -> confirmed PaymentIntent.
                $pm = self::stripe_request('/v1/payment_methods', [
                    'type' => 'card',
                    'card' => ['token' => self::gpay_stripe_token($cred['token'] ?? '')],
                ]);
                return self::charge_stripe(['payment_method' => $pm['id'] ?? ''], $amount, $currency);
            case 'card_tokenized':
                // Stripe Shared Payment Token: documented one-call preview shape.
                return self::charge_stripe(['payment_method_data' => [
                    'shared_payment_granted_token' => $cred['token'] ?? '',
                ]], $amount, $currency);
        }
        throw new UCPWC_Error(400, 'INVALID_REQUEST', 'Unknown payment handler ' . ($instrument['handler_id'] ?? ''));
    }

    public static function is_known(string $handler_id): bool
    {
        return in_array($handler_id, ['mock_payment_handler', 'google_pay', 'card_tokenized'], true);
    }

    // -- mock ---------------------------------------------------------------------

    private static function charge_mock(array $cred): void
    {
        if (($cred['type'] ?? '') === 'card') {
            return; // mock: any raw card succeeds
        }
        match ($cred['token'] ?? '') {
            'success_token' => null,
            'fail_token'    => throw new UCPWC_Error(402, 'INSUFFICIENT_FUNDS', 'Payment Failed: Insufficient Funds (Mock)'),
            'fraud_token'   => throw new UCPWC_Error(403, 'FRAUD_DETECTED', 'Payment Failed: Fraud Detected (Mock)'),
            default         => throw new UCPWC_Error(402, 'UNKNOWN_TOKEN', 'Payment Failed: Unknown Token (Mock)'),
        };
    }

    // -- Stripe --------------------------------------------------------------------

    /** GPay tokenizationData.token for gateway=stripe is a JSON Stripe Token object (or a bare tok_). */
    private static function gpay_stripe_token(string $token): string
    {
        $parsed = json_decode($token, true);
        return is_array($parsed) && isset($parsed['id']) ? $parsed['id'] : $token;
    }

    private static function charge_stripe(array $params, int $amount, string $currency): string
    {
        $params += [
            'amount'   => $amount,
            'currency' => strtolower($currency),
            'confirm'  => 'true',
            // Agent checkouts are server-to-server: redirect-based methods are impossible.
            'automatic_payment_methods' => ['enabled' => 'true', 'allow_redirects' => 'never'],
        ];
        return self::map_stripe_response(...self::stripe_request('/v1/payment_intents', $params, true));
    }

    /** Form-encoded POST to Stripe. Returns [http_code, decoded_body] when $raw, else the body (throwing on any error). */
    private static function stripe_request(string $path, array $params, bool $raw = false): array
    {
        $key = self::stripe_secret_key();
        if (!$key) {
            throw new UCPWC_Error(400, 'INVALID_REQUEST', 'Stripe is not configured on this store');
        }
        $res = wp_remote_post(self::stripe_api_base() . $path, [
            'headers' => ['Authorization' => 'Bearer ' . $key],
            'body'    => $params,
            'timeout' => 30,
        ]);
        if (is_wp_error($res)) {
            throw new UCPWC_Error(502, 'PAYMENT_FAILED', 'Payment processor unreachable: ' . $res->get_error_message());
        }
        $http = wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true) ?: [];
        if ($raw) {
            return [$http, $body];
        }
        if ($http >= 400 || isset($body['error'])) {
            self::map_stripe_response($http, $body); // throws with the right classification
        }
        return $body;
    }

    /** Split out so decline mapping is unit-testable without a network. */
    public static function map_stripe_response(int $http, array $body): string
    {
        if ($http < 400 && isset($body['id'])) {
            $status = $body['status'] ?? 'succeeded';
            if (in_array($status, ['succeeded', 'processing', 'requires_capture'], true)) {
                return $body['id'];
            }
            throw new UCPWC_Error(402, 'PAYMENT_DECLINED',
                'Payment not completed: intent status ' . $status, 'requires_buyer_input');
        }
        $err = $body['error'] ?? [];
        if (($err['type'] ?? '') === 'card_error') {
            $reason = $err['decline_code'] ?? $err['code'] ?? 'card_declined';
            throw new UCPWC_Error(402, 'PAYMENT_DECLINED',
                'Payment declined: ' . $reason . ' — ' . ($err['message'] ?? ''), 'requires_buyer_input');
        }
        throw new UCPWC_Error(502, 'PAYMENT_FAILED', 'Payment processor error: ' . ($err['message'] ?? "HTTP $http"));
    }

    // -- Stripe config ----------------------------------------------------------------

    public static function stripe_api_base(): string
    {
        // Dev/test override (e.g. stripe-mock): option only, no UI field.
        return untrailingslashit(get_option('ucpwc_stripe_api_base') ?: 'https://api.stripe.com');
    }

    public static function stripe_secret_key(): string
    {
        if (!ucpwc_can_premium('stripe')) {
            return ''; // no key = Stripe handlers neither advertised nor chargeable
        }
        $key = get_option('ucpwc_stripe_secret_key', '');
        if ($key) {
            return $key;
        }
        $wc = get_option('woocommerce_stripe_settings', []);
        if (($wc['testmode'] ?? 'no') === 'yes') {
            return $wc['test_secret_key'] ?? '';
        }
        return $wc['secret_key'] ?? '';
    }

    public static function stripe_publishable_key(): string
    {
        $key = get_option('ucpwc_stripe_publishable_key', '');
        if ($key) {
            return $key;
        }
        $wc = get_option('woocommerce_stripe_settings', []);
        return ($wc['testmode'] ?? 'no') === 'yes' ? ($wc['test_publishable_key'] ?? '') : ($wc['publishable_key'] ?? '');
    }

    /** Stripe account id (acct_...) for the ACP handler config; fetched once and cached. */
    public static function stripe_account_id(): string
    {
        $acct = get_option('ucpwc_stripe_account_id', '');
        if ($acct || !self::stripe_secret_key()) {
            return $acct;
        }
        $res = wp_remote_get(self::stripe_api_base() . '/v1/account', [
            'headers' => ['Authorization' => 'Bearer ' . self::stripe_secret_key()], 'timeout' => 10,
        ]);
        $id = is_wp_error($res) ? '' : (json_decode(wp_remote_retrieve_body($res), true)['id'] ?? '');
        if ($id) {
            update_option('ucpwc_stripe_account_id', $id, false);
        }
        return $id;
    }

    private static function is_test_mode(): bool
    {
        return str_starts_with(self::stripe_secret_key(), 'sk_test_');
    }

    // -- handler declarations ------------------------------------------------------------

    /** payment_handlers map for the UCP business profile and checkout response envelope. */
    public static function ucp_handlers(): array
    {
        $handlers = [];
        if (get_option('ucpwc_simulation_secret')) {
            $handlers['dev.mock.payment_handler'] = [[
                'id'      => 'mock_payment_handler',
                'name'    => 'mock_payment_handler',
                'version' => UCPWC_VERSION,
                'spec'    => 'https://ucp.dev/' . UCPWC_VERSION . '/schemas/mock_payment_handler/spec',
                'config'  => ['mode' => 'mock'],
            ]];
        }
        if (self::stripe_secret_key()) {
            $gateway = ['gateway' => 'stripe'];
            if (self::stripe_publishable_key()) {
                $gateway['stripe:version'] = '2018-10-31';
                $gateway['stripe:publishableKey'] = self::stripe_publishable_key();
            }
            $handlers['com.google.pay'] = [[
                'id'                 => 'google_pay',
                'name'               => 'com.google.pay',
                'version'            => UCPWC_VERSION,
                'spec'               => 'https://developers.google.com/merchant/ucp/guides/google-pay-payment-handler',
                'config_schema'      => 'https://pay.google.com/gp/p/ucp/' . UCPWC_VERSION . '/schemas/config.json',
                'instrument_schemas' => ['https://pay.google.com/gp/p/ucp/' . UCPWC_VERSION . '/schemas/card_payment_instrument.json'],
                'config'             => [
                    'api_version'       => 2,
                    'api_version_minor' => 0,
                    'merchant_info'     => [
                        'merchant_name'   => get_bloginfo('name'),
                        'merchant_id'     => self::stripe_account_id() ?: 'TEST',
                        'merchant_origin' => wp_parse_url(home_url(), PHP_URL_HOST),
                    ],
                    'allowed_payment_methods' => [[
                        'type'       => 'CARD',
                        'parameters' => [
                            'allowedAuthMethods'  => ['PAN_ONLY', 'CRYPTOGRAM_3DS'],
                            'allowedCardNetworks' => ['VISA', 'MASTERCARD', 'AMEX', 'DISCOVER'],
                        ],
                        'tokenization_specification' => [[
                            'type'       => 'PAYMENT_GATEWAY',
                            'parameters' => [$gateway],
                        ]],
                    ]],
                ],
            ]];
        }
        return apply_filters('ucpwc_payment_handlers', $handlers);
    }

    /** handlers list for ACP session capabilities.payment.handlers. */
    public static function acp_handlers(): array
    {
        $handlers = [];
        if (self::stripe_secret_key()) {
            $handlers[] = [
                'id'                        => 'card_tokenized',
                'name'                      => 'dev.acp.tokenized.card',
                'version'                   => '2026-01-22',
                'spec'                      => 'https://acp.dev/handlers/tokenized.card',
                'requires_delegate_payment' => true,
                'requires_pci_compliance'   => false,
                'psp'                       => 'stripe',
                'config_schema'             => 'https://acp.dev/schemas/handlers/tokenized.card/config.json',
                'instrument_schemas'        => ['https://acp.dev/schemas/handlers/tokenized.card/instrument.json'],
                'config'                    => [
                    'merchant_id'     => self::stripe_account_id(),
                    'psp'             => 'stripe',
                    'accepted_brands' => ['visa', 'mastercard', 'amex', 'discover'],
                    'supports_3ds'    => false,
                    'environment'     => self::is_test_mode() ? 'test' : 'production',
                ],
            ];
        }
        if (get_option('ucpwc_simulation_secret')) {
            $handlers[] = [
                'id'   => 'mock_payment_handler', 'name' => 'mock_payment_handler',
                'version' => UCPWC_VERSION,
                'spec' => 'https://ucp.dev/' . UCPWC_VERSION . '/schemas/mock_payment_handler/spec',
                'requires_delegate_payment' => false, 'requires_pci_compliance' => false,
                'psp' => 'mock', 'config_schema' => '', 'instrument_schemas' => [],
                'config' => ['mode' => 'mock'],
            ];
        }
        return $handlers;
    }
}
