<?php
/**
 * Plugin Name: UCP/ACP Agent for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/ucp-for-woocommerce/
 * Description: Universal Commerce Protocol (UCP) merchant implementation for WooCommerce — discovery, signed checkout sessions, orders, webhooks.
 * Version: 0.1.0
 * Author: ooasis
 * Author URI: https://profiles.wordpress.org/ooasis/
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ucp-for-woocommerce
 */

defined('ABSPATH') || exit;

define('UCPWC_VERSION', '2026-04-08');
define('UCPWC_PATH', plugin_dir_path(__FILE__));

require_once UCPWC_PATH . 'includes/freemius.php';
require_once UCPWC_PATH . 'includes/signatures.php';
require_once UCPWC_PATH . 'includes/class-ucpwc-idempotency.php';
require_once UCPWC_PATH . 'includes/class-ucpwc-payments.php';
require_once UCPWC_PATH . 'includes/class-ucpwc-profile.php';
require_once UCPWC_PATH . 'includes/class-ucpwc-checkout.php';
require_once UCPWC_PATH . 'includes/class-ucpwc-orders.php';
require_once UCPWC_PATH . 'includes/class-ucpwc-rest.php';
require_once UCPWC_PATH . 'includes/class-ucpwc-acp.php';
require_once UCPWC_PATH . 'includes/class-ucpwc-admin.php';
require_once UCPWC_PATH . 'includes/class-ucpwc-feed.php';

register_activation_hook(__FILE__, function () {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $charset = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE {$wpdb->prefix}ucp_sessions (
        id varchar(64) NOT NULL,
        doc longtext NOT NULL,
        updated_at datetime NOT NULL,
        PRIMARY KEY (id)
    ) $charset;");
    dbDelta("CREATE TABLE {$wpdb->prefix}ucp_idempotency (
        idem_key varchar(191) NOT NULL,
        request_hash char(64) NOT NULL,
        response_status smallint NOT NULL,
        response_body longtext NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY (idem_key)
    ) $charset;");
    UCPWC_Profile::ensure_signing_key();
});

add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

add_action('rest_api_init', ['UCPWC_Rest', 'register_routes']);
add_action('rest_api_init', function () {
    if (ucpwc_can_premium('acp')) {
        UCPWC_Acp::register_routes();
        UCPWC_Feed::register_routes();
    }
});
add_action('admin_menu', ['UCPWC_Admin', 'register'], 60);
add_action('ucpwc_feed_push', ['UCPWC_Feed', 'push']);

// /.well-known/ucp and /testing/simulate-shipping/{id} live at the site root,
// outside the REST prefix — serve them straight off REQUEST_URI.
add_action('parse_request', function ($wp) {
    $path = strtok(sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'] ?? '')), '?');
    if ($path === '/.well-known/ucp') {
        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=300');
        echo wp_json_encode(UCPWC_Profile::business_profile());
        exit;
    }
    if ($path === '/.well-known/acp.json' && ucpwc_can_premium('acp')) {
        header('Content-Type: application/json');
        header('Cache-Control: public, max-age=300');
        echo wp_json_encode(UCPWC_Acp::discovery());
        exit;
    }
    if (preg_match('#^/testing/simulate-shipping/([A-Za-z0-9\-]+)$#', $path, $m)) {
        UCPWC_Orders::simulate_shipping($m[1]);
        exit;
    }
});
