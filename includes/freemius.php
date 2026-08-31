<?php
defined('ABSPATH') || exit;

/**
 * Freemius licensing rails — dormant until account credentials are supplied.
 *
 * Setup (once, when ready to monetize):
 *   1. Create the product at https://dashboard.freemius.com -> copy its ID + public key.
 *   2. Define in wp-config.php (or replace the defaults below):
 *        define('UCPWC_FS_ID', '12345');
 *        define('UCPWC_FS_PUBLIC_KEY', 'pk_...');
 *   3. In the Freemius dashboard, create the paid plan and mark features premium;
 *      then flip the corresponding ucpwc_can_premium() gates below to enforce it.
 *
 * Until then every feature is free: ucpwc_can_premium() returns true when
 * Freemius is not configured, so behavior is identical to the pre-Freemius plugin.
 */

function ucpwc_fs()
{
    global $ucpwc_fs;
    // The SDK directory ships only in the premium build (distributed by Freemius);
    // the wordpress.org free build omits it, so guard for absence.
    if (!isset($ucpwc_fs) && defined('UCPWC_FS_ID') && defined('UCPWC_FS_PUBLIC_KEY')
        && file_exists(UCPWC_PATH . 'freemius/start.php')) {
        require_once UCPWC_PATH . 'freemius/start.php';
        $ucpwc_fs = fs_dynamic_init([
            'id'             => UCPWC_FS_ID,
            'slug'           => 'ucp-acp-agent-for-woocommerce',
            'type'           => 'plugin',
            'public_key'     => UCPWC_FS_PUBLIC_KEY,
            'is_premium'     => false,   // free version baseline; premium builds flip this at deploy
            'has_addons'     => false,
            'has_paid_plans' => true,
            'menu'           => ['slug' => 'ucpwc-settings', 'parent' => ['slug' => 'woocommerce']],
        ]);
        do_action('ucpwc_fs_loaded');
    }
    return $ucpwc_fs ?? null;
}

/**
 * Central premium gate. Feature keys mark the future Pro surface:
 * 'acp' (dual-protocol checkout + feeds), 'stripe' (real payment handlers),
 * 'strict_signatures'. All free while Freemius is unconfigured or no paid
 * plan exists — flip per-feature enforcement here when plans go live.
 */
function ucpwc_can_premium(string $feature): bool
{
    $fs = ucpwc_fs();
    $allowed = $fs === null || $fs->can_use_premium_code() || !$fs->has_paid_plan();
    return (bool)apply_filters('ucpwc_can_premium', $allowed, $feature);
}

ucpwc_fs();
