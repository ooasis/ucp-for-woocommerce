<?php
defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages become JSON API payloads (UCP/ACP error envelopes), never HTML output.

/** Thrown to short-circuit a request into a UCP error envelope. */
class UCPWC_Error extends Exception
{
    public function __construct(public readonly int $http, public readonly string $ucp_code,
                                public readonly string $content, public readonly string $severity = 'unrecoverable')
    {
        parent::__construct($content);
    }
}

class UCPWC_Checkout
{
    // -- storage ------------------------------------------------------------

    public static function load(string $id): array
    {
        global $wpdb;
        $doc = $wpdb->get_var($wpdb->prepare(
            "SELECT doc FROM {$wpdb->prefix}ucp_sessions WHERE id = %s", $id));
        if (!$doc) {
            throw new UCPWC_Error(404, 'RESOURCE_NOT_FOUND', 'Checkout session not found');
        }
        return json_decode($doc, true);
    }

    public static function save(array $doc): void
    {
        global $wpdb;
        $wpdb->replace($wpdb->prefix . 'ucp_sessions', [
            'id' => $doc['id'], 'doc' => wp_json_encode($doc), 'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    // -- endpoints ------------------------------------------------------------

    public static function create(array $body, string $ucp_agent): array
    {
        $doc = [
            'ucp'      => UCPWC_Profile::response_envelope(),
            'id'       => wp_generate_uuid4(),
            'status'   => 'incomplete',
            'currency' => get_woocommerce_currency(),
            'links'    => [],
            'payment'  => ['instruments' => self::strip_credentials($body['payment']['instruments'] ?? [])],
        ];
        if (isset($body['buyer'])) {
            $doc['buyer'] = $body['buyer'];
        }
        $doc['line_items'] = array_map(
            fn($li) => ['id' => wp_generate_uuid4(), 'item' => ['id' => $li['item']['id'] ?? ''], 'quantity' => (int)($li['quantity'] ?? 1)],
            array_values($body['line_items'] ?? [])
        );
        if (!empty($body['fulfillment']['methods'])) {
            $doc['fulfillment'] = ['methods' => array_map([self::class, 'normalize_method'], array_values($body['fulfillment']['methods']))];
        }
        if (!empty($body['discounts']['codes'])) {
            $doc['discounts'] = ['codes' => array_values($body['discounts']['codes'])];
        }
        self::attach_webhook_url($doc, $ucp_agent);
        self::inject_known_addresses($doc);
        self::recalculate($doc);
        self::save($doc);
        return $doc;
    }

    public static function update(string $id, array $body, string $ucp_agent): array
    {
        $doc = self::load($id);
        if (in_array($doc['status'], ['completed', 'canceled'], true)) {
            throw new UCPWC_Error(409, 'CHECKOUT_NOT_MODIFIABLE', 'Checkout is ' . $doc['status'] . ' and cannot be modified');
        }
        if (isset($body['line_items'])) {
            $doc['line_items'] = array_map(
                fn($li) => ['id' => $li['id'] ?? wp_generate_uuid4(), 'item' => ['id' => $li['item']['id'] ?? ''], 'quantity' => (int)($li['quantity'] ?? 1)],
                array_values($body['line_items'])
            );
        }
        if (isset($body['buyer'])) {
            $doc['buyer'] = $body['buyer'];
        }
        if (isset($body['payment'])) {
            $doc['payment'] = ['instruments' => self::strip_credentials($body['payment']['instruments'] ?? [])];
        }
        if (isset($body['fulfillment'])) {
            $doc['fulfillment'] = self::merge_fulfillment($doc['fulfillment'] ?? ['methods' => []], $body['fulfillment']);
        }
        if (isset($body['discounts'])) {
            $doc['discounts'] = ['codes' => array_values($body['discounts']['codes'] ?? [])];
        }
        self::attach_webhook_url($doc, $ucp_agent);
        self::inject_known_addresses($doc);
        self::recalculate($doc);
        self::save($doc);
        return $doc;
    }

    public static function cancel(string $id): array
    {
        $doc = self::load($id);
        if (in_array($doc['status'], ['completed', 'canceled'], true)) {
            throw new UCPWC_Error(409, 'CHECKOUT_NOT_MODIFIABLE', 'Checkout is ' . $doc['status'] . ' and cannot be modified');
        }
        $doc['status'] = 'canceled';
        self::save($doc);
        return $doc;
    }

    public static function complete(string $id, array $body): array
    {
        $doc = self::load($id);
        if (in_array($doc['status'], ['completed', 'canceled'], true)) {
            throw new UCPWC_Error(409, 'CHECKOUT_NOT_MODIFIABLE', 'Checkout is ' . $doc['status'] . ' and cannot be modified');
        }
        if (!self::is_completable($doc)) {
            throw new UCPWC_Error(400, 'INVALID_REQUEST',
                'Fulfillment address and option must be selected before completion', 'requires_buyer_input');
        }
        $instrument = $body['payment']['instruments'][0] ?? null;
        if (!$instrument || empty($instrument['handler_id']) || empty($instrument['credential'])) {
            throw new UCPWC_Error(400, 'INVALID_REQUEST', 'A payment instrument with handler_id and credential is required');
        }
        $total = 0;
        foreach ($doc['totals'] as $t) {
            if ($t['type'] === 'total') {
                $total = $t['amount'];
            }
        }
        $transaction_id = UCPWC_Payments::charge($instrument, $total, $doc['currency']);

        // Reserve stock atomically-enough: re-check then decrement.
        $products = [];
        foreach ($doc['line_items'] as $li) {
            $p = self::product($li['item']['id']);
            if ($p->managing_stock() && $p->get_stock_quantity() < $li['quantity']) {
                throw new UCPWC_Error(409, 'OUT_OF_STOCK', 'Item ' . $li['item']['id'] . ' is out of stock');
            }
            $products[] = [$p, $li];
        }
        foreach ($products as [$p, $li]) {
            if ($p->managing_stock()) {
                wc_update_product_stock($p, $li['quantity'], 'decrease');
            }
        }

        $order_uuid = wp_generate_uuid4();
        $wc_order_id = self::create_wc_order($doc, $instrument, $order_uuid, $transaction_id);
        $order_entity = UCPWC_Orders::build_entity($doc, $order_uuid);
        UCPWC_Orders::store($order_uuid, $order_entity, $wc_order_id);

        $doc['status'] = 'completed';
        $doc['order'] = ['id' => $order_uuid, 'permalink_url' => UCPWC_Profile::endpoint() . '/orders/' . $order_uuid];
        self::save($doc);
        UCPWC_Orders::send_webhook($order_entity, $doc['platform']['webhook_url'] ?? null, 'order_placed');
        return $doc;
    }

    /** Spec: payment credentials must never be echoed back or persisted. */
    private static function strip_credentials(array $instruments): array
    {
        return array_map(function ($i) {
            unset($i['credential']);
            return $i;
        }, array_values($instruments));
    }

    // -- recalculation pipeline ----------------------------------------------

    public static function product(string $item_id): WC_Product
    {
        $pid = wc_get_product_id_by_sku($item_id);
        $p = $pid ? wc_get_product($pid) : null;
        if (!$p) {
            throw new UCPWC_Error(400, 'INVALID_REQUEST', 'Product ' . $item_id . ' not found');
        }
        return $p;
    }

    public static function recalculate(array &$doc): void
    {
        $subtotal = 0;
        foreach ($doc['line_items'] as &$li) {
            $p = self::product($li['item']['id']);
            if ($p->managing_stock() && $p->get_stock_quantity() < $li['quantity']) {
                throw new UCPWC_Error(400, 'OUT_OF_STOCK', 'Insufficient stock for item ' . $li['item']['id']);
            }
            $price = (int)round(((float)$p->get_price()) * 100);
            $li['item'] = ['id' => $li['item']['id'], 'title' => $p->get_name(), 'price' => $price];
            $line_total = $price * $li['quantity'];
            $li['totals'] = [['type' => 'subtotal', 'amount' => $line_total], ['type' => 'total', 'amount' => $line_total]];
            $subtotal += $line_total;
        }
        unset($li);

        $totals = [['type' => 'subtotal', 'amount' => $subtotal]];
        $fulfillment_total = 0;
        if (!empty($doc['fulfillment']['methods'])) {
            foreach ($doc['fulfillment']['methods'] as &$method) {
                self::recompute_options($method, $doc, $subtotal);
                foreach ($method['groups'] ?? [] as $group) {
                    if (empty($group['selected_option_id'])) {
                        continue;
                    }
                    foreach ($group['options'] ?? [] as $opt) {
                        if ($opt['id'] === $group['selected_option_id']) {
                            $amount = self::total_of($opt['totals']);
                            $totals[] = ['type' => 'fulfillment', 'amount' => $amount];
                            $fulfillment_total += $amount;
                        }
                    }
                }
            }
            unset($method);
        }

        // Discounts: sequential on the shrinking (subtotal + fulfillment) base.
        $running = $subtotal + $fulfillment_total;
        $applied = [];
        foreach ($doc['discounts']['codes'] ?? [] as $code) {
            $coupon_id = wc_get_coupon_id_by_code($code);
            if (!$coupon_id) {
                continue; // unknown codes are silently ignored
            }
            $coupon = new WC_Coupon($coupon_id);
            $amount = $coupon->get_discount_type() === 'percent'
                ? (int)($running * (float)$coupon->get_amount() / 100)
                : min($running, (int)round((float)$coupon->get_amount() * 100));
            if ($amount <= 0) {
                continue;
            }
            $running -= $amount;
            $applied[] = [
                'code'        => $coupon->get_code(),
                'title'       => $coupon->get_description() ?: $coupon->get_code(),
                'amount'      => $amount,
                'allocations' => [['path' => "$.totals[?(@.type=='subtotal')]", 'amount' => $amount]],
            ];
            $totals[] = ['type' => 'discount', 'amount' => -$amount];
        }
        if (isset($doc['discounts'])) {
            $doc['discounts']['applied'] = $applied;
        }

        $totals[] = ['type' => 'total', 'amount' => array_sum(array_map(
            fn($t) => $t['type'] === 'total' ? 0 : $t['amount'], $totals))];
        $doc['totals'] = $totals;
        $doc['status'] = self::is_completable($doc) ? 'ready_for_complete' : 'incomplete';
    }

    public static function is_completable(array $doc): bool
    {
        if (empty($doc['line_items'])) {
            return false;
        }
        foreach ($doc['fulfillment']['methods'] ?? [] as $method) {
            if (($method['type'] ?? 'shipping') === 'shipping' && empty($method['selected_destination_id'])) {
                continue;
            }
            foreach ($method['groups'] ?? [] as $group) {
                if (!empty($group['selected_option_id'])) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function total_of(array $totals): int
    {
        foreach ($totals as $t) {
            if ($t['type'] === 'total') {
                return $t['amount'];
            }
        }
        return 0;
    }

    // -- fulfillment ----------------------------------------------------------

    private static function normalize_method(array $m): array
    {
        $method = [
            'id'            => $m['id'] ?? ('method_' . wp_generate_uuid4()),
            'type'          => $m['type'] ?? 'shipping',
            'line_item_ids' => array_values($m['line_item_ids'] ?? []),
        ];
        if (isset($m['destinations'])) {
            $method['destinations'] = array_map([self::class, 'normalize_destination'], array_values($m['destinations']));
        }
        $method['selected_destination_id'] = $m['selected_destination_id'] ?? null;
        if (isset($m['groups'])) {
            $method['groups'] = array_map(fn($g) => [
                'id'                 => $g['id'] ?? ('group_' . wp_generate_uuid4()),
                'line_item_ids'      => array_values($g['line_item_ids'] ?? []),
                'selected_option_id' => $g['selected_option_id'] ?? null,
            ], array_values($m['groups']));
        }
        return $method;
    }

    /** Accept SDK aliases (locality/region) and emit the response shape. */
    private static function normalize_destination(array $d): array
    {
        $out = [
            'id'              => $d['id'] ?? ('dest_' . wp_generate_uuid4()),
            'type'            => 'shipping_address',
            'street_address'  => $d['street_address'] ?? '',
            'address_locality'=> $d['address_locality'] ?? $d['locality'] ?? '',
            'address_region'  => $d['address_region'] ?? $d['region'] ?? '',
            'postal_code'     => $d['postal_code'] ?? '',
            'address_country' => $d['address_country'] ?? '',
        ];
        foreach (['full_name', 'first_name', 'last_name', 'phone_number', 'extended_address'] as $extra) {
            if (isset($d[$extra])) {
                $out[$extra] = $d[$extra];
            }
        }
        return $out;
    }

    /** Hierarchical merge per the spec: match methods by id, replace-if-sent per field. */
    private static function merge_fulfillment(array $existing, array $incoming): array
    {
        $result = ['methods' => []];
        $existing_methods = $existing['methods'] ?? [];
        foreach (array_values($incoming['methods'] ?? []) as $in) {
            $match = null;
            foreach ($existing_methods as $ex) {
                if (isset($in['id']) && $ex['id'] === $in['id']) {
                    $match = $ex;
                }
            }
            if ($match === null && !isset($in['id']) && count($existing_methods) === 1) {
                $match = $existing_methods[0];
            }
            $normalized = self::normalize_method($in);
            if ($match) {
                $normalized['id'] = $match['id'];
                if (!isset($in['destinations'])) {
                    $normalized['destinations'] = $match['destinations'] ?? [];
                }
                if (!isset($in['groups']) && isset($match['groups'])) {
                    $normalized['groups'] = $match['groups'];
                }
                if (empty($normalized['line_item_ids'])) {
                    $normalized['line_item_ids'] = $match['line_item_ids'];
                }
            }
            $result['methods'][] = $normalized;
        }
        return $result;
    }

    /**
     * Compute shipping options for a method's selected destination from the
     * store's real WC shipping zones (flat_rate, free_shipping, local_pickup).
     * ponytail: flat-rate cost formulas ([qty] etc.) unsupported — numeric costs only;
     * extend per-method when a merchant needs formula rates.
     */
    private static function recompute_options(array &$method, array $doc, int $subtotal): void
    {
        if (($method['type'] ?? 'shipping') !== 'shipping' || empty($method['selected_destination_id'])) {
            return;
        }
        $dest = null;
        foreach ($method['destinations'] ?? [] as $d) {
            if ($d['id'] === $method['selected_destination_id']) {
                $dest = $d;
            }
        }
        if (!$dest) {
            return;
        }
        $package = ['destination' => [
            'country'  => $dest['address_country'],
            'state'    => $dest['address_region'],
            'postcode' => $dest['postal_code'],
            'city'     => $dest['address_locality'],
            'address'  => $dest['street_address'],
        ]];
        $zone = WC_Shipping_Zones::get_zone_matching_package($package);
        $options = [];
        foreach ($zone->get_shipping_methods(true) as $instance_id => $m) {
            $amount = null;
            if ($m->id === 'flat_rate' || $m->id === 'local_pickup') {
                $amount = (int)round(((float)$m->get_option('cost', '0')) * 100);
            } elseif ($m->id === 'free_shipping') {
                $min = (float)$m->get_option('min_amount', '0');
                if ($subtotal >= (int)round($min * 100)) {
                    $amount = 0;
                }
            }
            if ($amount === null) {
                continue;
            }
            $options[] = [
                'id'     => $m->id . '-' . $instance_id,
                'title'  => $m->get_title(),
                'totals' => [['type' => 'subtotal', 'amount' => $amount], ['type' => 'total', 'amount' => $amount]],
            ];
        }
        usort($options, fn($a, $b) => self::total_of($a['totals']) <=> self::total_of($b['totals']));

        if (empty($method['groups'])) {
            $method['groups'] = [[
                'id'                 => 'group_' . wp_generate_uuid4(),
                'line_item_ids'      => $method['line_item_ids'],
                'selected_option_id' => null,
            ]];
        }
        foreach ($method['groups'] as &$group) {
            $group['options'] = $options;
        }
        unset($group);
    }

    /** Known-customer address injection: WP user matched by buyer email. */
    private static function inject_known_addresses(array &$doc): void
    {
        $email = $doc['buyer']['email'] ?? null;
        if (!$email || empty($doc['fulfillment']['methods'])) {
            return;
        }
        $user = get_user_by('email', $email);
        if (!$user) {
            return;
        }
        $stored = self::stored_addresses($user->ID);
        foreach ($doc['fulfillment']['methods'] as &$method) {
            if (($method['type'] ?? 'shipping') !== 'shipping') {
                continue;
            }
            if (empty($method['destinations'])) {
                if ($stored) {
                    $method['destinations'] = array_values($stored);
                }
            } else {
                // Content-duplicate addresses adopt the stored id.
                foreach ($method['destinations'] as &$d) {
                    foreach ($stored as $s) {
                        if ($s['street_address'] === $d['street_address'] && $s['postal_code'] === $d['postal_code']) {
                            if (($method['selected_destination_id'] ?? null) === $d['id']) {
                                $method['selected_destination_id'] = $s['id'];
                            }
                            $d['id'] = $s['id'];
                        }
                    }
                }
                unset($d);
            }
        }
        unset($method);
    }

    private static function stored_addresses(int $user_id): array
    {
        $out = [];
        foreach (['billing', 'shipping'] as $kind) {
            $street = get_user_meta($user_id, $kind . '_address_1', true);
            if (!$street) {
                continue;
            }
            $out[] = [
                'id'              => $kind,
                'type'            => 'shipping_address',
                'street_address'  => $street,
                'address_locality'=> get_user_meta($user_id, $kind . '_city', true),
                'address_region'  => get_user_meta($user_id, $kind . '_state', true),
                'postal_code'     => get_user_meta($user_id, $kind . '_postcode', true),
                'address_country' => get_user_meta($user_id, $kind . '_country', true),
            ];
        }
        return $out;
    }

    private static function attach_webhook_url(array &$doc, string $ucp_agent): void
    {
        $url = UCPWC_Profile::webhook_url_from_profile(UCPWC_Profile::fetch_platform_profile($ucp_agent));
        if ($url) {
            $doc['platform'] = ['webhook_url' => $url];
        }
    }

    // -- WooCommerce order creation --------------------------------------------

    private static function create_wc_order(array $doc, array $instrument, string $order_uuid, ?string $transaction_id = null): int
    {
        $order = wc_create_order();
        foreach ($doc['line_items'] as $li) {
            $order->add_product(self::product($li['item']['id']), $li['quantity']);
        }
        [$dest, $option_title, $option_amount] = self::selected_fulfillment($doc);
        if ($dest) {
            $order->set_shipping_address([
                'first_name' => $dest['first_name'] ?? ($doc['buyer']['first_name'] ?? ''),
                'last_name'  => $dest['last_name'] ?? ($doc['buyer']['last_name'] ?? ''),
                'address_1'  => $dest['street_address'],
                'city'       => $dest['address_locality'],
                'state'      => $dest['address_region'],
                'postcode'   => $dest['postal_code'],
                'country'    => $dest['address_country'],
            ]);
        }
        $billing = $instrument['billing_address'] ?? $dest;
        if ($billing) {
            $order->set_billing_address([
                'email'     => $doc['buyer']['email'] ?? '',
                'address_1' => $billing['street_address'] ?? '',
                'city'      => $billing['address_locality'] ?? '',
                'state'     => $billing['address_region'] ?? '',
                'postcode'  => $billing['postal_code'] ?? '',
                'country'   => $billing['address_country'] ?? '',
            ]);
        }
        if ($option_title !== null) {
            $ship = new WC_Order_Item_Shipping();
            $ship->set_method_title($option_title);
            $ship->set_total((string)($option_amount / 100));
            $order->add_item($ship);
        }
        foreach ($doc['discounts']['codes'] ?? [] as $code) {
            if (wc_get_coupon_id_by_code($code)) {
                $order->apply_coupon($code);
            }
        }
        $order->calculate_totals();
        // The UCP checkout total is what the buyer agreed to; WC coupon math can
        // differ (WC discounts the product subtotal only, UCP includes fulfillment).
        foreach ($doc['totals'] as $t) {
            if ($t['type'] === 'total') {
                $order->set_total((string)($t['amount'] / 100));
            }
        }
        $order->update_meta_data('_ucp_order_id', $order_uuid);
        $order->update_meta_data('_ucp_checkout_id', $doc['id']);
        if ($transaction_id) {
            $order->set_transaction_id($transaction_id);
            $order->add_order_note('Charged via UCP payment handler, transaction ' . $transaction_id . '.');
        }
        $order->set_status('processing', 'UCP agent checkout completed.');
        $order->save();
        return $order->get_id();
    }

    /** Returns [selected destination array|null, selected option title|null, amount]. */
    public static function selected_fulfillment(array $doc): array
    {
        foreach ($doc['fulfillment']['methods'] ?? [] as $method) {
            $dest = null;
            foreach ($method['destinations'] ?? [] as $d) {
                if ($d['id'] === ($method['selected_destination_id'] ?? null)) {
                    $dest = $d;
                }
            }
            foreach ($method['groups'] ?? [] as $group) {
                foreach ($group['options'] ?? [] as $opt) {
                    if ($opt['id'] === ($group['selected_option_id'] ?? null)) {
                        return [$dest, $opt['title'], self::total_of($opt['totals'])];
                    }
                }
            }
            if ($dest) {
                return [$dest, null, 0];
            }
        }
        return [null, null, 0];
    }
}
