<?php
// Remove everything UCP for WooCommerce stored. WooCommerce orders are kept —
// they are the merchant's business records, not plugin data.

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ucp_sessions");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}ucp_idempotency");

// All plugin options share the ucpwc_ prefix (settings, keys, and per-order
// entities like ucpwc_order_<uuid>); transients use ucpwc_profile_*.
$wpdb->query(
    "DELETE FROM {$wpdb->options}
     WHERE option_name LIKE 'ucpwc\_%'
        OR option_name LIKE '\_transient\_ucpwc\_%'
        OR option_name LIKE '\_transient\_timeout\_ucpwc\_%'"
);
