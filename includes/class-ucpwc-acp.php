<?php
defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages become JSON API payloads (UCP/ACP error envelopes), never HTML output.

/**
 * ACP (OpenAI Agentic Commerce Protocol, v2026-04-17) dual-protocol layer.
 *
 * A translation shim over the same checkout core that serves UCP: ACP requests
 * are mapped onto UCPWC_Checkout operations, and the stored (UCP-shaped)
 * session document is mapped back into the ACP CheckoutSession wire shape.
 * One session store, one state machine, two protocols.
 */
class UCPWC_Acp
{
    const ACP_VERSION = '2026-04-17';

    public static function register_routes(): void
    {
        $r = fn(string $op) => fn(WP_REST_Request $req) => self::dispatch($op, $req);
        register_rest_route('acp/v1', '/checkout_sessions', [
            'methods' => 'POST', 'callback' => $r('create'), 'permission_callback' => '__return_true',
        ]);
        register_rest_route('acp/v1', '/checkout_sessions/(?P<id>[A-Za-z0-9\-]+)', [
            ['methods' => 'GET', 'callback' => $r('get'), 'permission_callback' => '__return_true'],
            ['methods' => 'POST', 'callback' => $r('update'), 'permission_callback' => '__return_true'],
        ]);
        register_rest_route('acp/v1', '/checkout_sessions/(?P<id>[A-Za-z0-9\-]+)/complete', [
            'methods' => 'POST', 'callback' => $r('complete'), 'permission_callback' => '__return_true',
        ]);
        register_rest_route('acp/v1', '/checkout_sessions/(?P<id>[A-Za-z0-9\-]+)/cancel', [
            'methods' => 'POST', 'callback' => $r('cancel'), 'permission_callback' => '__return_true',
        ]);
    }

    /** Served at /.well-known/acp.json */
    public static function discovery(): array
    {
        return [
            'protocol'     => ['version' => self::ACP_VERSION],
            'api_base_url' => untrailingslashit(rest_url('acp/v1')),
            'transports'   => ['rest'],
            'capabilities' => ['checkout', 'orders_webhooks'],
        ];
    }

    /** Bearer key auto-generated on first use; required on every ACP request. */
    public static function api_key(): string
    {
        $key = get_option('ucpwc_acp_api_key');
        if (!$key) {
            $key = 'acp_' . wp_generate_password(40, false);
            update_option('ucpwc_acp_api_key', $key, false);
        }
        return $key;
    }

    // -- request pipeline -------------------------------------------------------

    private static function dispatch(string $op, WP_REST_Request $req): WP_REST_Response
    {
        try {
            $auth = $req->get_header('authorization') ?? '';
            if (!hash_equals('Bearer ' . self::api_key(), $auth)) {
                return self::error(401, 'invalid_request', 'invalid_api_key', 'Invalid or missing API key');
            }
            $ver = $req->get_header('api-version');
            if ($ver && $ver !== self::ACP_VERSION) {
                $res = self::error(400, 'invalid_request', 'unsupported_api_version', "API version $ver is not supported");
                $res->set_data($res->get_data() + ['supported_versions' => [self::ACP_VERSION]]);
                return $res;
            }
            $id = $req->get_param('id');
            $json = $req->get_json_params();
            if (!is_array($json)) {
                $json = [];
            }

            if ($op !== 'get') {
                $idem_key = $req->get_header('idempotency-key');
                if (!$idem_key) {
                    return self::error(400, 'invalid_request', 'idempotency_key_required', 'Idempotency-Key header is required');
                }
                $hash = UCPWC_Idempotency::hash('acp_' . $op, $id, $req->get_body());
                $stored = UCPWC_Idempotency::check($idem_key, $hash);
                if ($stored !== null) {
                    if (isset($stored['conflict'])) {
                        return self::error(422, 'invalid_request', 'idempotency_conflict', 'Idempotency key reused with different parameters');
                    }
                    $res = new WP_REST_Response($stored['body'], $stored['status']);
                    $res->header('Idempotent-Replayed', 'true');
                    return self::finish($res, $req);
                }
                $res = self::execute($op, $json, $id);
                if ($res->get_status() < 500) {
                    UCPWC_Idempotency::store($idem_key, $hash, $res->get_status(), $res->get_data());
                }
                return self::finish($res, $req);
            }
            return self::finish(self::execute($op, $json, $id), $req);
        } catch (UCPWC_Error $e) {
            return self::finish(self::translate_error($op, $e), $req);
        } catch (Throwable $e) {
            return self::finish(self::error(500, 'processing_error', 'internal_error', $e->getMessage()), $req);
        }
    }

    private static function finish(WP_REST_Response $res, WP_REST_Request $req): WP_REST_Response
    {
        if ($rid = $req->get_header('request-id')) {
            $res->header('Request-Id', $rid);
        }
        return $res;
    }

    private static function execute(string $op, array $json, ?string $id): WP_REST_Response
    {
        switch ($op) {
            case 'create':
                $doc = UCPWC_Checkout::create(self::to_ucp_request($json), '');
                return new WP_REST_Response(self::to_acp_session($doc), 201);
            case 'get':
                return new WP_REST_Response(self::to_acp_session(UCPWC_Checkout::load($id)), 200);
            case 'update':
                $doc = UCPWC_Checkout::update($id, self::to_ucp_request($json, UCPWC_Checkout::load($id)), '');
                return new WP_REST_Response(self::to_acp_session($doc), 200);
            case 'complete':
                return self::complete($id, $json);
            case 'cancel':
                $doc = UCPWC_Checkout::cancel($id);
                return new WP_REST_Response(self::to_acp_session($doc), 200);
        }
        throw new UCPWC_Error(500, 'INTERNAL_ERROR', 'Unknown operation');
    }

    private static function complete(string $id, array $json): WP_REST_Response
    {
        $pd = $json['payment_data'] ?? null;
        if (!$pd) {
            return self::error(400, 'invalid_request', 'missing', 'payment_data is required', '$.payment_data');
        }
        // 2026-04-17 shape: handler_id + instrument.credential.token.
        // Legacy (≤2025-12-12) {token, provider} accepted for compatibility.
        $token = $pd['instrument']['credential']['token'] ?? $pd['token'] ?? '';
        $handler = $pd['handler_id'] ?? '';
        if (!UCPWC_Payments::is_known($handler)) {
            // Legacy {token, provider: stripe} means an SPT; otherwise fall back to mock.
            $handler = ($pd['provider'] ?? '') === 'stripe' && UCPWC_Payments::stripe_secret_key()
                ? 'card_tokenized' : 'mock_payment_handler';
        }
        $ucp_body = ['payment' => ['instruments' => [[
            'id'         => 'acp_instr_1',
            'handler_id' => $handler,
            'type'       => $pd['instrument']['type'] ?? 'card',
            'credential' => ['type' => $pd['instrument']['credential']['type'] ?? 'token', 'token' => $token],
        ]]], 'risk_signals' => $json['risk_signals'] ?? []];
        if (isset($pd['billing_address'])) {
            $ucp_body['payment']['instruments'][0]['billing_address'] = self::to_ucp_address($pd['billing_address']);
        }
        try {
            $doc = UCPWC_Checkout::complete($id, $ucp_body);
        } catch (UCPWC_Error $e) {
            if (in_array($e->ucp_code, ['INSUFFICIENT_FUNDS', 'UNKNOWN_TOKEN', 'FRAUD_DETECTED', 'PAYMENT_DECLINED'], true)) {
                // ACP prefers business failures in-band: 200 session + error message.
                $session = self::to_acp_session(UCPWC_Checkout::load($id));
                $session['messages'][] = [
                    'type' => 'error', 'code' => 'payment_declined',
                    'content_type' => 'plain', 'content' => $e->content,
                ];
                return new WP_REST_Response($session, 200);
            }
            throw $e;
        }
        self::send_order_webhook($doc['order']['id'], 'order_create');
        return new WP_REST_Response(self::to_acp_session($doc), 200);
    }

    // -- ACP -> UCP request translation ------------------------------------------

    private static function to_ucp_request(array $acp, array $existing = []): array
    {
        $ucp = [];
        if (isset($acp['line_items'])) {
            $ucp['line_items'] = array_map(fn($li) => [
                'item'     => ['id' => $li['item']['id'] ?? $li['id'] ?? ''],
                'quantity' => (int)($li['quantity'] ?? 1), // Item has no quantity in 2026-04-17; default 1
            ], array_values($acp['line_items']));
        }
        if (isset($acp['buyer'])) {
            $ucp['buyer'] = $acp['buyer'];
        }
        $codes = $acp['discounts']['codes'] ?? $acp['coupons'] ?? null; // coupons[] deprecated
        if ($codes !== null) {
            $ucp['discounts'] = ['codes' => array_values($codes)];
        }
        if (isset($acp['fulfillment_details']) || isset($acp['selected_fulfillment_options'])) {
            $method = ['id' => 'acp', 'type' => 'shipping', 'line_item_ids' => []];
            $ex_method = ($existing['fulfillment']['methods'] ?? [])[0] ?? null;
            if (isset($acp['fulfillment_details']['address'])) {
                $dest = self::to_ucp_address($acp['fulfillment_details']['address']);
                $dest['id'] = 'acp_dest';
                if (isset($acp['fulfillment_details']['name'])) {
                    $dest['full_name'] = $acp['fulfillment_details']['name'];
                }
                $method['destinations'] = [$dest];
                $method['selected_destination_id'] = 'acp_dest';
            } elseif ($ex_method) {
                $method['destinations'] = $ex_method['destinations'] ?? [];
                $method['selected_destination_id'] = $ex_method['selected_destination_id'] ?? null;
            }
            $selected = ($acp['selected_fulfillment_options'] ?? [])[0]['option_id']
                ?? (($ex_method['groups'] ?? [])[0]['selected_option_id'] ?? null);
            if ($ex_method || $selected) {
                $method['groups'] = [[
                    'id'                 => ($ex_method['groups'] ?? [])[0]['id'] ?? 'acp_group',
                    'line_item_ids'      => [],
                    'selected_option_id' => $selected,
                ]];
            }
            $ucp['fulfillment'] = ['methods' => [$method]];
        }
        return $ucp;
    }

    private static function to_ucp_address(array $a): array
    {
        return [
            'street_address'   => trim(($a['line_one'] ?? '') . ' ' . ($a['line_two'] ?? '')),
            'address_locality' => $a['city'] ?? '',
            'address_region'   => $a['state'] ?? '',
            'postal_code'      => $a['postal_code'] ?? '',
            'address_country'  => $a['country'] ?? '',
        ];
    }

    // -- UCP doc -> ACP session translation ----------------------------------------

    const STATUS_MAP = [
        'incomplete'           => 'not_ready_for_payment',
        'requires_escalation'  => 'requires_escalation',
        'ready_for_complete'   => 'ready_for_payment',
        'complete_in_progress' => 'complete_in_progress',
        'completed'            => 'completed',
        'canceled'             => 'canceled',
    ];

    const TOTAL_LABELS = [
        'subtotal' => 'Subtotal', 'fulfillment' => 'Shipping', 'discount' => 'Discount',
        'tax' => 'Tax', 'total' => 'Total', 'items_base_amount' => 'Items',
    ];

    public static function to_acp_session(array $doc): array
    {
        $totals = array_map(fn($t) => [
            'type'         => $t['type'],
            'display_text' => self::TOTAL_LABELS[$t['type']] ?? ucfirst($t['type']),
            'amount'       => $t['amount'],
        ], $doc['totals'] ?? []);

        $line_items = array_map(fn($li) => [
            'id'       => $li['id'],
            'item'     => ['id' => $li['item']['id'], 'name' => $li['item']['title'] ?? null,
                           'unit_amount' => $li['item']['price'] ?? null],
            'quantity' => $li['quantity'],
            'totals'   => array_map(fn($t) => [
                'type' => $t['type'], 'display_text' => self::TOTAL_LABELS[$t['type']] ?? ucfirst($t['type']),
                'amount' => $t['amount'],
            ], $li['totals'] ?? []),
        ], $doc['line_items'] ?? []);

        $options = [];
        $selected = [];
        $details = null;
        foreach ($doc['fulfillment']['methods'] ?? [] as $method) {
            foreach ($method['destinations'] ?? [] as $d) {
                if ($d['id'] === ($method['selected_destination_id'] ?? null)) {
                    $details = ['address' => [
                        'name'        => $d['full_name'] ?? '',
                        'line_one'    => $d['street_address'],
                        'city'        => $d['address_locality'],
                        'state'       => $d['address_region'],
                        'country'     => $d['address_country'],
                        'postal_code' => $d['postal_code'],
                    ]];
                }
            }
            foreach ($method['groups'] ?? [] as $group) {
                foreach ($group['options'] ?? [] as $opt) {
                    $options[] = [
                        'type'   => 'shipping',
                        'id'     => $opt['id'],
                        'title'  => $opt['title'],
                        'totals' => array_map(fn($t) => [
                            'type' => $t['type'], 'display_text' => self::TOTAL_LABELS[$t['type']] ?? ucfirst($t['type']),
                            'amount' => $t['amount'],
                        ], $opt['totals']),
                    ];
                }
                if (!empty($group['selected_option_id'])) {
                    $selected[] = [
                        'type'      => 'shipping',
                        'option_id' => $group['selected_option_id'],
                        'item_ids'  => array_column($doc['line_items'] ?? [], 'id'),
                    ];
                }
            }
        }

        $session = [
            'protocol'   => ['version' => self::ACP_VERSION],
            'id'         => $doc['id'],
            'status'     => self::STATUS_MAP[$doc['status']] ?? $doc['status'],
            'currency'   => strtolower($doc['currency']),
            'line_items' => $line_items,
            'totals'     => $totals,
            'fulfillment_options' => $options,
            'messages'   => [],
            'links'      => [],
            'capabilities' => ['payment' => ['handlers' => UCPWC_Payments::acp_handlers()]],
        ];
        if ($selected) {
            $session['selected_fulfillment_options'] = $selected;
        }
        if ($details) {
            $session['fulfillment_details'] = $details;
        }
        if (isset($doc['buyer'])) {
            $session['buyer'] = $doc['buyer'];
        }
        if (isset($doc['discounts'])) {
            $session['discounts'] = $doc['discounts'];
        }
        if (isset($doc['order'])) {
            $session['order'] = [
                'id'                  => $doc['order']['id'],
                'checkout_session_id' => $doc['id'],
                'permalink_url'       => $doc['order']['permalink_url'],
            ];
        }
        return $session;
    }

    // -- webhooks (merchant -> OpenAI, HMAC-signed) ---------------------------------

    /**
     * POST an order_create/order_update event to the configured ACP webhook URL,
     * signed per spec: Merchant-Signature: t=<unix>,v1=<hex hmac-sha256(t.body)>.
     * URL + secret are provisioned out-of-band (options: ucpwc_acp_webhook_url/_secret).
     */
    public static function send_order_webhook(string $order_uuid, string $event_type): void
    {
        $url = get_option('ucpwc_acp_webhook_url');
        $secret = get_option('ucpwc_acp_webhook_secret');
        if (!$url || !$secret) {
            return;
        }
        try {
            $entity = UCPWC_Orders::load($order_uuid);
        } catch (UCPWC_Error) {
            return;
        }
        $body = wp_json_encode(['type' => $event_type, 'data' => self::to_acp_order($entity)]);
        $ts = time();
        $sig = 't=' . $ts . ',v1=' . hash_hmac('sha256', $ts . '.' . $body, $secret);
        for ($attempt = 0, $delay = 500000; $attempt < 3; $attempt++, $delay *= 2) {
            $res = wp_remote_post($url, ['headers' => [
                'Content-Type'       => 'application/json',
                'Merchant-Signature' => $sig,
                'Timestamp'          => gmdate('c', $ts),
            ], 'body' => $body, 'timeout' => 10]);
            if (!is_wp_error($res) && wp_remote_retrieve_response_code($res) < 500) {
                return;
            }
            usleep($delay);
        }
    }

    /** UCP order entity -> ACP Order object. */
    public static function to_acp_order(array $e): array
    {
        $shipped = false;
        $fulfillments = [];
        foreach ($e['fulfillment']['events'] ?? [] as $ev) {
            $shipped = $shipped || $ev['type'] === 'shipped';
            $fulfillments[] = [
                'id'         => $ev['id'],
                'type'       => 'shipping',
                'status'     => $ev['type'] === 'shipped' ? 'shipped' : 'processing',
                'line_items' => $ev['line_items'] ?? [],
                'events'     => [['id' => $ev['id'], 'type' => $ev['type'], 'occurred_at' => $ev['occurred_at']]],
            ];
        }
        return [
            'type'                => 'order',
            'id'                  => $e['id'],
            'checkout_session_id' => $e['checkout_id'],
            'permalink_url'       => $e['permalink_url'],
            'status'              => $shipped ? 'shipped' : 'created',
            'line_items'          => array_map(fn($li) => [
                'id'       => $li['id'],
                'title'    => $li['item']['title'] ?? $li['item']['id'],
                'quantity' => ['ordered' => $li['quantity']['total'], 'current' => $li['quantity']['total'],
                               'fulfilled' => $shipped ? $li['quantity']['total'] : $li['quantity']['fulfilled']],
                'unit_price' => $li['item']['price'] ?? null,
            ], $e['line_items']),
            'fulfillments'        => $fulfillments,
            'totals'              => array_map(fn($t) => [
                'type' => $t['type'], 'display_text' => self::TOTAL_LABELS[$t['type']] ?? ucfirst($t['type']),
                'amount' => $t['amount'],
            ], $e['totals']),
        ];
    }

    // -- errors ----------------------------------------------------------------------

    private static function translate_error(string $op, UCPWC_Error $e): WP_REST_Response
    {
        $code_map = [
            'RESOURCE_NOT_FOUND'      => 'not_found',
            'OUT_OF_STOCK'            => 'out_of_stock',
            'IDEMPOTENCY_CONFLICT'    => 'idempotency_conflict',
            'CHECKOUT_NOT_MODIFIABLE' => 'conflict',
            'INVALID_REQUEST'         => 'invalid',
        ];
        $http = $e->http;
        if ($op === 'cancel' && $e->ucp_code === 'CHECKOUT_NOT_MODIFIABLE') {
            $http = 405; // spec: cancel on a terminal session -> 405
        }
        $type = $http >= 500 ? 'processing_error' : 'invalid_request';
        return self::error($http, $type, $code_map[$e->ucp_code] ?? strtolower($e->ucp_code), $e->content);
    }

    private static function error(int $http, string $type, string $code, string $message, ?string $param = null): WP_REST_Response
    {
        $body = ['type' => $type, 'code' => $code, 'message' => $message];
        if ($param) {
            $body['param'] = $param;
        }
        return new WP_REST_Response($body, $http);
    }
}
