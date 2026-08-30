<?php
defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages become JSON API payloads, never HTML output.

/**
 * Product feed generation, two serializations from one catalog mapper:
 *
 *  - ACP standard feed (spec 2026-04-17): nested Product/Variant JSON —
 *    served as a JSONL snapshot and pushed incrementally to an agent-hosted
 *    Feed API (PATCH /feeds/{id}/products).
 *  - OpenAI ChatGPT merchant feed: flat TSV with eligibility flags
 *    (is_eligible_search / is_eligible_checkout), served for OpenAI to ingest.
 *
 * Feed endpoints accept the ACP Bearer key or a dedicated read token
 * (?token=...) so fetchers that can't set headers can still pull the file.
 */
class UCPWC_Feed
{
    const BATCH = 100;

    // -- canonical catalog mapping ------------------------------------------------

    /** All published products as ACP Product objects (variants nested). */
    public static function products(): array
    {
        $out = [];
        for ($page = 1; ; $page++) {
            $batch = wc_get_products(['status' => 'publish', 'limit' => self::BATCH, 'page' => $page]);
            if (!$batch) {
                break;
            }
            foreach ($batch as $p) {
                $out[] = self::map_product($p);
            }
        }
        return $out;
    }

    private static function map_product(WC_Product $p): array
    {
        $product = [
            'id'    => $p->get_sku() ?: 'wc-' . $p->get_id(),
            'title' => $p->get_name(),
            'url'   => $p->get_permalink(),
        ];
        $desc = trim(wp_strip_all_tags($p->get_description() ?: $p->get_short_description()));
        if ($desc !== '') {
            $product['description'] = ['plain' => $desc];
        }
        if ($img = self::media($p)) {
            $product['media'] = [$img];
        }
        if ($p->is_type('variable')) {
            $product['variants'] = array_values(array_filter(array_map(
                fn($vid) => self::map_variant(wc_get_product($vid), $p),
                $p->get_children()
            )));
        } else {
            $product['variants'] = [self::map_variant($p, $p)];
        }
        return $product;
    }

    private static function map_variant(?WC_Product $v, WC_Product $parent): ?array
    {
        if (!$v) {
            return null;
        }
        $currency = get_woocommerce_currency();
        $variant = [
            'id'    => $v->get_sku() ?: 'wc-' . $v->get_id(),
            'title' => $v->get_name(),
            'url'   => $v->get_permalink(),
            'price' => ['amount' => (int)round((float)$v->get_price() * 100), 'currency' => $currency],
            'availability' => [
                'available' => $v->is_in_stock(),
                'status'    => ['instock' => 'in_stock', 'outofstock' => 'out_of_stock', 'onbackorder' => 'backorder'][$v->get_stock_status()] ?? 'in_stock',
            ],
        ];
        if ($v->is_on_sale() && $v->get_regular_price() !== '') {
            $variant['list_price'] = ['amount' => (int)round((float)$v->get_regular_price() * 100), 'currency' => $currency];
        }
        if (method_exists($v, 'get_global_unique_id') && $v->get_global_unique_id()) {
            $variant['barcodes'] = [['type' => 'GTIN', 'value' => $v->get_global_unique_id()]];
        }
        $options = [];
        foreach ($v->is_type('variation') ? $v->get_attributes() : [] as $name => $value) {
            $options[] = ['name' => wc_attribute_label($name), 'value' => (string)$value];
        }
        if ($options) {
            $variant['variant_options'] = $options;
        }
        $cats = array_map(
            fn($t) => ['value' => $t->name, 'taxonomy' => 'merchant'],
            get_the_terms($parent->get_id(), 'product_cat') ?: []
        );
        if ($cats) {
            $variant['categories'] = array_values($cats);
        }
        if ($img = self::media($v)) {
            $variant['media'] = [$img];
        }
        $variant['seller'] = ['name' => get_bloginfo('name')];
        return $variant;
    }

    private static function media(WC_Product $p): ?array
    {
        $url = wp_get_attachment_image_url($p->get_image_id(), 'woocommerce_single');
        return $url ? ['type' => 'image', 'url' => $url, 'alt_text' => $p->get_name()] : null;
    }

    // -- serializations --------------------------------------------------------------

    public static function to_jsonl(): string
    {
        return implode("\n", array_map('wp_json_encode', self::products())) . "\n";
    }

    /** OpenAI ChatGPT merchant feed: flat TSV, one row per variant. */
    public static function to_openai_tsv(): string
    {
        $cols = ['id', 'title', 'description', 'link', 'image_link', 'brand', 'availability', 'price',
                 'sale_price', 'gtin', 'item_group_id', 'condition', 'is_eligible_search', 'is_eligible_checkout'];
        $search = get_option('ucpwc_feed_eligible_search', 'yes') === 'yes' ? 'true' : 'false';
        $checkout = get_option('ucpwc_feed_eligible_checkout', 'yes') === 'yes' ? 'true' : 'false';
        $clean = fn(string $s, int $max) => mb_substr(preg_replace('/[\t\r\n]+/', ' ', $s), 0, $max);
        $money = fn(array $price) => number_format($price['amount'] / 100, 2, '.', '') . ' ' . $price['currency'];

        $rows = [implode("\t", $cols)];
        foreach (self::products() as $product) {
            $grouped = count($product['variants']) > 1;
            foreach ($product['variants'] as $v) {
                // OpenAI: price = pre-discount price, sale_price = active discount price.
                $price = $v['list_price'] ?? $v['price'];
                $sale = isset($v['list_price']) ? $money($v['price']) : '';
                $brand = self::brand($product) ?: $clean(get_bloginfo('name'), 70);
                $rows[] = implode("\t", [
                    $clean($v['id'], 100),
                    $clean($v['title'], 150),
                    $clean($product['description']['plain'] ?? $product['title'], 5000),
                    $v['url'] ?? $product['url'],
                    $v['media'][0]['url'] ?? $product['media'][0]['url'] ?? '',
                    $brand,
                    ['in_stock' => 'in_stock', 'out_of_stock' => 'out_of_stock', 'backorder' => 'backorder'][$v['availability']['status']] ?? 'unknown',
                    $money($price),
                    $sale,
                    $v['barcodes'][0]['value'] ?? '',
                    $grouped ? $product['id'] : '',
                    'new',
                    $search,
                    $checkout,
                ]);
            }
        }
        return implode("\n", $rows) . "\n";
    }

    private static function brand(array $product): string
    {
        // ponytail: WC core brands taxonomy only; per-product brand overrides when a merchant asks.
        static $cache = [];
        $id = $product['id'];
        if (!isset($cache[$id])) {
            $pid = wc_get_product_id_by_sku($id) ?: (int)str_replace('wc-', '', $id);
            $terms = taxonomy_exists('product_brand') ? get_the_terms($pid, 'product_brand') : false;
            $cache[$id] = $terms && !is_wp_error($terms) ? $terms[0]->name : '';
        }
        return $cache[$id];
    }

    // -- REST endpoints ---------------------------------------------------------------

    public static function register_routes(): void
    {
        $guard = function (WP_REST_Request $req) {
            $token = $req->get_param('token');
            return hash_equals('Bearer ' . UCPWC_Acp::api_key(), $req->get_header('authorization') ?? '')
                || ($token && hash_equals(self::read_token(), $token));
        };
        register_rest_route('acp/v1', '/feed/products', [
            'methods' => 'GET', 'permission_callback' => $guard,
            'callback' => fn() => new WP_REST_Response(['products' => self::products()], 200),
        ]);
        register_rest_route('acp/v1', '/feed/products.jsonl', [
            'methods' => 'GET', 'permission_callback' => $guard,
            'callback' => function () {
                header('Content-Type: application/jsonl');
                echo self::to_jsonl(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSONL feed body
                exit;
            },
        ]);
        register_rest_route('acp/v1', '/feed/openai.tsv', [
            'methods' => 'GET', 'permission_callback' => $guard,
            'callback' => function () {
                header('Content-Type: text/tab-separated-values; charset=utf-8');
                echo self::to_openai_tsv(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- TSV feed body
                exit;
            },
        ]);
    }

    public static function read_token(): string
    {
        $token = get_option('ucpwc_feed_token');
        if (!$token) {
            $token = 'feed_' . wp_generate_password(32, false);
            update_option('ucpwc_feed_token', $token, false);
        }
        return $token;
    }

    // -- push to an agent-hosted Feed API (ACP spec §5) ---------------------------------

    /** PATCH the full catalog (in batches) to the configured Feed API. Returns a summary line. */
    public static function push(): string
    {
        $base = untrailingslashit(get_option('ucpwc_feed_api_base', ''));
        $feed_id = get_option('ucpwc_feed_id', '');
        $token = get_option('ucpwc_feed_api_token', '');
        if (!$base || !$feed_id || !$token) {
            return __('Feed push is not configured (API base, feed id, and token are required).', 'ucp-for-woocommerce');
        }
        $products = self::products();
        $pushed = 0;
        foreach (array_chunk($products, self::BATCH) as $chunk) {
            $res = wp_remote_request("$base/feeds/$feed_id/products", [
                'method'  => 'PATCH',
                'headers' => [
                    'Content-Type'    => 'application/json',
                    'Authorization'   => 'Bearer ' . $token,
                    'API-Version'     => UCPWC_Acp::ACP_VERSION,
                    'Idempotency-Key' => wp_generate_uuid4(),
                ],
                'body'    => wp_json_encode(['products' => $chunk]),
                'timeout' => 30,
            ]);
            $code = is_wp_error($res) ? 0 : wp_remote_retrieve_response_code($res);
            if ($code !== 200) {
                $detail = is_wp_error($res) ? $res->get_error_message() : wp_remote_retrieve_body($res);
                /* translators: 1: number of products pushed, 2: error detail */
                return sprintf(__('Push failed after %1$d products: %2$s', 'ucp-for-woocommerce'), $pushed, $detail);
            }
            $pushed += count($chunk);
        }
        update_option('ucpwc_feed_last_push', gmdate('c'), false);
        /* translators: %d: number of products pushed */
        return sprintf(__('Pushed %d products to the feed API.', 'ucp-for-woocommerce'), $pushed);
    }

    /** Daily scheduled push, active only while push is configured. */
    public static function maybe_schedule(): void
    {
        $configured = get_option('ucpwc_feed_api_base') && get_option('ucpwc_feed_id') && get_option('ucpwc_feed_api_token');
        $scheduled = wp_next_scheduled('ucpwc_feed_push');
        if ($configured && !$scheduled) {
            wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'ucpwc_feed_push');
        } elseif (!$configured && $scheduled) {
            wp_unschedule_event($scheduled, 'ucpwc_feed_push');
        }
    }
}
