<?php
defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages become JSON API payloads (UCP/ACP error envelopes), never HTML output.

class UCPWC_Orders
{
    // -- entity ----------------------------------------------------------------

    /** Build the UCP order entity from a completed checkout doc. */
    public static function build_entity(array $doc, string $order_uuid): array
    {
        $line_items = array_map(fn($li) => [
            'id'       => $li['id'],
            'item'     => $li['item'],
            'quantity' => ['total' => $li['quantity'], 'fulfilled' => 0],
            'totals'   => $li['totals'],
            'status'   => 'processing',
        ], $doc['line_items']);

        $expectations = [];
        foreach ($doc['fulfillment']['methods'] ?? [] as $method) {
            $dest = null;
            foreach ($method['destinations'] ?? [] as $d) {
                if ($d['id'] === ($method['selected_destination_id'] ?? null)) {
                    $dest = $d;
                }
            }
            foreach ($method['groups'] ?? [] as $group) {
                if (empty($group['selected_option_id'])) {
                    continue;
                }
                $title = null;
                foreach ($group['options'] ?? [] as $opt) {
                    if ($opt['id'] === $group['selected_option_id']) {
                        $title = $opt['title'];
                    }
                }
                // Expectation line items: checkout items matching the group ids,
                // falling back to all items (group ids may reference client-side ids).
                $items = array_values(array_filter($doc['line_items'],
                    fn($li) => in_array($li['id'], $group['line_item_ids'] ?? [], true)));
                if (!$items) {
                    $items = $doc['line_items'];
                }
                $exp = [
                    'id'          => 'exp_' . wp_generate_uuid4(),
                    'line_items'  => array_map(fn($li) => ['id' => $li['id'], 'quantity' => $li['quantity']], $items),
                    'method_type' => $method['type'] ?? 'shipping',
                    'description' => $title,
                ];
                if ($dest) {
                    $exp['destination'] = array_diff_key($dest, ['id' => 1, 'type' => 1]);
                }
                $expectations[] = $exp;
            }
        }

        return [
            'ucp' => [
                'version'      => UCPWC_VERSION,
                'capabilities' => [
                    'dev.ucp.shopping.checkout' => [['name' => 'dev.ucp.shopping.checkout', 'version' => UCPWC_VERSION]],
                ],
            ],
            'id'            => $order_uuid,
            'checkout_id'   => $doc['id'],
            'permalink_url' => UCPWC_Profile::endpoint() . '/orders/' . $order_uuid,
            'currency'      => $doc['currency'],
            'totals'        => $doc['totals'],
            'line_items'    => $line_items,
            'fulfillment'   => ['expectations' => $expectations, 'events' => []],
        ];
    }

    // -- storage (order JSON lives in options, mapped to the WC order) ----------

    public static function store(string $order_uuid, array $entity, int $wc_order_id = 0): void
    {
        $map = get_option('ucpwc_order_map', []);
        if ($wc_order_id || !isset($map[$order_uuid])) {
            $map[$order_uuid] = $wc_order_id ?: ($map[$order_uuid] ?? 0);
            update_option('ucpwc_order_map', $map, false);
        }
        update_option('ucpwc_order_' . $order_uuid, $entity, false);
    }

    public static function load(string $order_uuid): array
    {
        $entity = get_option('ucpwc_order_' . $order_uuid);
        if (!is_array($entity)) {
            throw new UCPWC_Error(404, 'RESOURCE_NOT_FOUND', 'Order not found');
        }
        return $entity;
    }

    public static function replace(string $order_uuid, array $body): array
    {
        self::load($order_uuid); // 404 when unknown
        self::validate_entity($body);
        self::store($order_uuid, $body);
        return $body;
    }

    /** Minimal shape validation for PUT — bad enums/containers must 422. */
    private static function validate_entity(array $e): void
    {
        foreach (['ucp', 'id', 'checkout_id', 'permalink_url', 'line_items', 'fulfillment', 'currency', 'totals'] as $k) {
            if (!isset($e[$k])) {
                throw new UCPWC_Error(422, 'INVALID_REQUEST', "Order field $k is required");
            }
        }
        if (isset($e['adjustments'])) {
            if (!is_array($e['adjustments']) || ($e['adjustments'] !== [] && !array_is_list($e['adjustments']))) {
                throw new UCPWC_Error(422, 'INVALID_REQUEST', 'adjustments must be a list');
            }
            foreach ($e['adjustments'] as $adj) {
                if (isset($adj['status']) && !in_array($adj['status'], ['pending', 'completed', 'failed'], true)) {
                    throw new UCPWC_Error(422, 'INVALID_REQUEST', 'Invalid adjustment status');
                }
            }
        }
        if (isset($e['fulfillment']['events']) && !is_array($e['fulfillment']['events'])) {
            throw new UCPWC_Error(422, 'INVALID_REQUEST', 'fulfillment.events must be a list');
        }
    }

    // -- shipping simulation (conformance test hook) -----------------------------

    public static function simulate_shipping(string $order_uuid): void
    {
        $secret = get_option('ucpwc_simulation_secret');
        header('Content-Type: application/json');
        if (!$secret) {
            http_response_code(500);
            echo wp_json_encode(['error' => 'simulation secret not configured']);
            return;
        }
        $provided = sanitize_text_field(wp_unslash($_SERVER['HTTP_SIMULATION_SECRET'] ?? ''));
        if (!hash_equals((string)$secret, $provided)) {
            http_response_code(403);
            echo wp_json_encode(['error' => 'forbidden']);
            return;
        }
        try {
            $entity = self::load($order_uuid);
        } catch (UCPWC_Error) {
            http_response_code(404);
            echo wp_json_encode(['error' => 'order not found']);
            return;
        }
        $entity['fulfillment']['events'][] = [
            'id'          => 'evt_' . wp_generate_uuid4(),
            'type'        => 'shipped',
            'occurred_at' => gmdate('c'),
            'line_items'  => array_map(
                fn($li) => ['id' => $li['id'], 'quantity' => $li['quantity']['total']],
                $entity['line_items']
            ),
        ];
        self::store($order_uuid, $entity);

        // Webhook target: the checkout that produced this order knows the platform URL.
        $webhook_url = null;
        try {
            $doc = UCPWC_Checkout::load($entity['checkout_id']);
            $webhook_url = $doc['platform']['webhook_url'] ?? null;
        } catch (UCPWC_Error) {
        }
        self::send_webhook($entity, $webhook_url, 'order_shipped');
        UCPWC_Acp::send_order_webhook($order_uuid, 'order_update');
        echo wp_json_encode(['status' => 'shipped']);
    }

    // -- webhooks -----------------------------------------------------------------

    /**
     * POST the full order entity to the platform, signed per RFC 9421.
     * Retries up to 3 times on transport error / 5xx with the same
     * Webhook-Id, Webhook-Timestamp, and body.
     */
    public static function send_webhook(array $entity, ?string $url, string $event_type): void
    {
        if (!$url) {
            return;
        }
        $body = wp_json_encode($entity);
        $webhook_id = wp_generate_uuid4();
        $timestamp = (string)time();
        $parts = wp_parse_url($url);
        $authority = $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $path = $parts['path'] ?? '/';
        $ucp_agent = 'profile="' . home_url('/.well-known/ucp') . '"';
        $digest = \UcpSpike\content_digest($body);

        $key = UCPWC_Profile::private_key();
        $kid = UCPWC_Profile::public_jwk()['kid'];
        $components = ['@method', '@authority', '@path', 'content-digest', 'content-type',
                       'ucp-agent', 'webhook-id', 'webhook-timestamp', 'x-event-type'];
        $params = ';keyid="' . $kid . '"';
        $base = \UcpSpike\signature_base($components,
            ['method' => 'POST', 'authority' => $authority, 'path' => $path],
            [
                'content-digest'    => $digest,
                'content-type'      => 'application/json',
                'ucp-agent'         => $ucp_agent,
                'webhook-id'        => $webhook_id,
                'webhook-timestamp' => $timestamp,
                'x-event-type'      => $event_type,
            ], $params);
        $list = implode(' ', array_map(fn($c) => "\"$c\"", $components));

        $headers = [
            'Content-Type'      => 'application/json',
            'X-Event-Type'      => $event_type,
            'Webhook-Id'        => $webhook_id,
            'Webhook-Timestamp' => $timestamp,
            'Idempotency-Key'   => $webhook_id,
            'UCP-Agent'         => $ucp_agent,
            'Content-Digest'    => $digest,
            'Signature-Input'   => "sig1=($list)$params",
            'Signature'         => 'sig1=:' . base64_encode(\UcpSpike\sign_base($base, $key)) . ':',
        ];

        for ($attempt = 0, $delay = 500000; $attempt < 3; $attempt++, $delay *= 2) {
            $res = wp_remote_post($url, ['headers' => $headers, 'body' => $body, 'timeout' => 10]);
            if (!is_wp_error($res)) {
                $code = wp_remote_retrieve_response_code($res);
                if ($code < 500) {
                    return; // delivered (2xx) or permanent failure (4xx) — no retry
                }
            }
            usleep($delay);
        }
        // ponytail: in-request retry loop blocks the caller up to ~1.5s+timeouts;
        // move to Action Scheduler queued delivery when real traffic arrives.
    }
}
