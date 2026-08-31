<?php
defined('ABSPATH') || exit;

class UCPWC_Admin
{
    public static function register(): void
    {
        add_submenu_page(
            'woocommerce',
            __('UCP Settings', 'ucpacp-agent-for-woocommerce'),
            __('UCP', 'ucpacp-agent-for-woocommerce'),
            'manage_woocommerce', 'ucpwc-settings', [self::class, 'render']
        );
    }

    private static function handle_actions(): ?string
    {
        if ((sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'] ?? ''))) !== 'POST' || !check_admin_referer('ucpwc_settings')) {
            return null;
        }
        $action = sanitize_key($_POST['ucpwc_action'] ?? 'save');
        switch ($action) {
            case 'rotate_ucp_key':
                UCPWC_Profile::rotate_signing_key();
                return __('UCP signing key rotated. The previous key stays published while platforms pick up the new one (spec recommends a 7-day grace period).', 'ucpacp-agent-for-woocommerce');
            case 'regenerate_acp_key':
                delete_option('ucpwc_acp_api_key');
                UCPWC_Acp::api_key();
                return __('ACP API key regenerated. Update it with your ACP platform (e.g. OpenAI) — the old key stops working immediately.', 'ucpacp-agent-for-woocommerce');
            case 'push_feed':
                return UCPWC_Feed::push();
            default:
                update_option('ucpwc_strict_signatures', isset($_POST['strict_signatures']) ? 'yes' : 'no');
                if (isset($_POST['ucpwc_stripe_secret_key'])) {
                    delete_option('ucpwc_stripe_account_id'); // re-derive from the (possibly new) key
                }
                update_option('ucpwc_feed_eligible_search', isset($_POST['feed_eligible_search']) ? 'yes' : 'no');
                update_option('ucpwc_feed_eligible_checkout', isset($_POST['feed_eligible_checkout']) ? 'yes' : 'no');
                foreach (['ucpwc_simulation_secret', 'ucpwc_acp_webhook_url', 'ucpwc_acp_webhook_secret',
                          'ucpwc_stripe_secret_key', 'ucpwc_stripe_publishable_key',
                          'ucpwc_feed_api_base', 'ucpwc_feed_id', 'ucpwc_feed_api_token'] as $opt) {
                    $value = trim(sanitize_text_field(wp_unslash($_POST[$opt] ?? '')));
                    $value === '' ? delete_option($opt) : update_option($opt, $value, false);
                }
                UCPWC_Feed::maybe_schedule();
                return __('Settings saved.', 'ucpacp-agent-for-woocommerce');
        }
    }

    public static function render(): void
    {
        $notice = self::handle_actions();
        UCPWC_Profile::ensure_signing_key();
        $jwk = UCPWC_Profile::public_jwk();
        $retired = get_option('ucpwc_retired_keys', []);
        $field = fn(string $opt) => get_option($opt, '');
        ?>
        <div class="wrap">
        <h1><?php esc_html_e('UCP/ACP Agent for WooCommerce', 'ucpacp-agent-for-woocommerce'); ?></h1>
        <?php if ($notice) : ?><div class="notice notice-success"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>

        <h2><?php esc_html_e('Status', 'ucpacp-agent-for-woocommerce'); ?></h2>
        <table class="widefat striped" style="max-width:900px">
            <tbody>
            <tr><td><?php esc_html_e('UCP discovery profile', 'ucpacp-agent-for-woocommerce'); ?></td>
                <td><a href="<?php echo esc_url(home_url('/.well-known/ucp')); ?>" target="_blank"><?php echo esc_html(home_url('/.well-known/ucp')); ?></a></td></tr>
            <tr><td><?php esc_html_e('UCP REST endpoint', 'ucpacp-agent-for-woocommerce'); ?></td><td><code><?php echo esc_html(UCPWC_Profile::endpoint()); ?></code></td></tr>
            <tr><td><?php esc_html_e('ACP discovery document', 'ucpacp-agent-for-woocommerce'); ?></td>
                <td><a href="<?php echo esc_url(home_url('/.well-known/acp.json')); ?>" target="_blank"><?php echo esc_html(home_url('/.well-known/acp.json')); ?></a></td></tr>
            <tr><td><?php esc_html_e('ACP REST endpoint', 'ucpacp-agent-for-woocommerce'); ?></td><td><code><?php echo esc_html(untrailingslashit(rest_url('acp/v1'))); ?></code></td></tr>
            </tbody>
        </table>

        <form method="post">
        <?php wp_nonce_field('ucpwc_settings'); ?>

        <h2><?php esc_html_e('UCP (Google / Gemini and other UCP platforms)', 'ucpacp-agent-for-woocommerce'); ?></h2>
        <table class="form-table" style="max-width:900px">
            <tr>
                <th><?php esc_html_e('Signing key (ES256)', 'ucpacp-agent-for-woocommerce'); ?></th>
                <td>
                    <code>kid: <?php echo esc_html($jwk['kid']); ?></code>
                    <?php if ($retired) : ?>
                        <p class="description"><?php
                        /* translators: %d: number of retired signing keys */
                        echo esc_html(sprintf(_n('%d retired key still published for rotation grace.', '%d retired keys still published for rotation grace.', count($retired), 'ucpacp-agent-for-woocommerce'), count($retired)));
                        ?></p>
                    <?php endif; ?>
                    <p><button class="button" name="ucpwc_action" value="rotate_ucp_key"
                        onclick="return confirm('<?php echo esc_js(__('Rotate the UCP signing key? The current key stays published during the grace period.', 'ucpacp-agent-for-woocommerce')); ?>');"><?php esc_html_e('Rotate key', 'ucpacp-agent-for-woocommerce'); ?></button></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Strict signatures', 'ucpacp-agent-for-woocommerce'); ?></th>
                <td><label><input type="checkbox" name="strict_signatures" <?php checked(get_option('ucpwc_strict_signatures'), 'yes'); ?>>
                    <?php esc_html_e('Reject unsigned UCP requests (RFC 9421 required)', 'ucpacp-agent-for-woocommerce'); ?></label>
                    <p class="description"><?php esc_html_e('Leave off while testing — the official conformance suite sends unsigned requests.', 'ucpacp-agent-for-woocommerce'); ?></p></td>
            </tr>
        </table>

        <h2><?php esc_html_e('ACP (OpenAI / ChatGPT)', 'ucpacp-agent-for-woocommerce'); ?></h2>
        <table class="form-table" style="max-width:900px">
            <tr>
                <th><?php esc_html_e('API key', 'ucpacp-agent-for-woocommerce'); ?></th>
                <td><code><?php echo esc_html(UCPWC_Acp::api_key()); ?></code>
                    <p class="description"><?php esc_html_e('Give this to your ACP platform; every ACP request must carry it as a Bearer token.', 'ucpacp-agent-for-woocommerce'); ?></p>
                    <p><button class="button" name="ucpwc_action" value="regenerate_acp_key"
                        onclick="return confirm('<?php echo esc_js(__('Regenerate the ACP API key? The old key stops working immediately.', 'ucpacp-agent-for-woocommerce')); ?>');"><?php esc_html_e('Regenerate', 'ucpacp-agent-for-woocommerce'); ?></button></p></td>
            </tr>
            <tr>
                <th><label for="ucpwc_acp_webhook_url"><?php esc_html_e('Order webhook URL', 'ucpacp-agent-for-woocommerce'); ?></label></th>
                <td><input type="url" class="regular-text" id="ucpwc_acp_webhook_url" name="ucpwc_acp_webhook_url" value="<?php echo esc_attr($field('ucpwc_acp_webhook_url')); ?>">
                    <p class="description"><?php esc_html_e('Provided by the ACP platform (out-of-band provisioning). Empty = ACP order webhooks disabled.', 'ucpacp-agent-for-woocommerce'); ?></p></td>
            </tr>
            <tr>
                <th><label for="ucpwc_acp_webhook_secret"><?php esc_html_e('Webhook signing secret', 'ucpacp-agent-for-woocommerce'); ?></label></th>
                <td><input type="text" class="regular-text" id="ucpwc_acp_webhook_secret" name="ucpwc_acp_webhook_secret" value="<?php echo esc_attr($field('ucpwc_acp_webhook_secret')); ?>" autocomplete="off">
                    <p class="description"><?php esc_html_e('Shared secret for the HMAC-SHA256 Merchant-Signature header.', 'ucpacp-agent-for-woocommerce'); ?></p></td>
            </tr>
        </table>

        <h2><?php esc_html_e('Payments', 'ucpacp-agent-for-woocommerce'); ?></h2>
        <table class="form-table" style="max-width:900px">
            <tr>
                <th><?php esc_html_e('Active handlers', 'ucpacp-agent-for-woocommerce'); ?></th>
                <td>
                    <?php
                    $names = array_keys(UCPWC_Payments::ucp_handlers());
                    foreach (UCPWC_Payments::acp_handlers() as $h) {
                        $names[] = $h['name'] . ' (ACP)';
                    }
                    echo $names ? '<code>' . implode('</code>, <code>', array_map('esc_html', array_unique($names))) . '</code>'
                                : '<em>' . esc_html__('none — configure Stripe below or set a simulation secret for the mock handler', 'ucpacp-agent-for-woocommerce') . '</em>';
                    ?>
                </td>
            </tr>
            <tr>
                <th><label for="ucpwc_stripe_secret_key"><?php esc_html_e('Stripe secret key', 'ucpacp-agent-for-woocommerce'); ?></label></th>
                <td><input type="password" class="regular-text" id="ucpwc_stripe_secret_key" name="ucpwc_stripe_secret_key" value="<?php echo esc_attr($field('ucpwc_stripe_secret_key')); ?>" autocomplete="off">
                    <p class="description"><?php esc_html_e('Enables Google Pay (UCP) and Shared Payment Token (ACP) handlers.', 'ucpacp-agent-for-woocommerce'); ?>
                    <?php if (!get_option('ucpwc_stripe_secret_key') && UCPWC_Payments::stripe_secret_key()) : ?>
                        <?php esc_html_e('Currently inherited from the WooCommerce Stripe gateway settings.', 'ucpacp-agent-for-woocommerce'); ?>
                    <?php endif; ?></p></td>
            </tr>
            <tr>
                <th><label for="ucpwc_stripe_publishable_key"><?php esc_html_e('Stripe publishable key', 'ucpacp-agent-for-woocommerce'); ?></label></th>
                <td><input type="text" class="regular-text" id="ucpwc_stripe_publishable_key" name="ucpwc_stripe_publishable_key" value="<?php echo esc_attr($field('ucpwc_stripe_publishable_key')); ?>" autocomplete="off">
                    <p class="description"><?php esc_html_e('Advertised in the Google Pay handler config so platforms can tokenize for this store.', 'ucpacp-agent-for-woocommerce'); ?></p></td>
            </tr>
        </table>

        <h2><?php esc_html_e('Product feed', 'ucpacp-agent-for-woocommerce'); ?></h2>
        <table class="form-table" style="max-width:900px">
            <tr>
                <th><?php esc_html_e('Feed URLs', 'ucpacp-agent-for-woocommerce'); ?></th>
                <td>
                    <?php $t = UCPWC_Feed::read_token(); ?>
                    <p><code><?php echo esc_html(rest_url('acp/v1/feed/openai.tsv') . '?token=' . $t); ?></code><br>
                       <span class="description"><?php esc_html_e('OpenAI ChatGPT merchant feed (TSV).', 'ucpacp-agent-for-woocommerce'); ?></span></p>
                    <p><code><?php echo esc_html(rest_url('acp/v1/feed/products.jsonl') . '?token=' . $t); ?></code><br>
                       <span class="description"><?php esc_html_e('ACP standard feed snapshot (JSON Lines).', 'ucpacp-agent-for-woocommerce'); ?></span></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Eligibility defaults', 'ucpacp-agent-for-woocommerce'); ?></th>
                <td>
                    <label><input type="checkbox" name="feed_eligible_search" <?php checked(get_option('ucpwc_feed_eligible_search', 'yes'), 'yes'); ?>> <?php esc_html_e('is_eligible_search (ChatGPT search visibility)', 'ucpacp-agent-for-woocommerce'); ?></label><br>
                    <label><input type="checkbox" name="feed_eligible_checkout" <?php checked(get_option('ucpwc_feed_eligible_checkout', 'yes'), 'yes'); ?>> <?php esc_html_e('is_eligible_checkout (direct purchase in ChatGPT; requires OpenAI approval)', 'ucpacp-agent-for-woocommerce'); ?></label>
                </td>
            </tr>
            <tr>
                <th><label for="ucpwc_feed_api_base"><?php esc_html_e('Feed API push (ACP)', 'ucpacp-agent-for-woocommerce'); ?></label></th>
                <td>
                    <input type="url" class="regular-text" id="ucpwc_feed_api_base" name="ucpwc_feed_api_base" value="<?php echo esc_attr($field('ucpwc_feed_api_base')); ?>" placeholder="https://agent.example/feed-api">
                    <input type="text" class="regular-text" name="ucpwc_feed_id" value="<?php echo esc_attr($field('ucpwc_feed_id')); ?>" placeholder="feed id" style="max-width:150px">
                    <input type="password" class="regular-text" name="ucpwc_feed_api_token" value="<?php echo esc_attr($field('ucpwc_feed_api_token')); ?>" placeholder="bearer token" autocomplete="off" style="max-width:200px">
                    <p class="description">
                        <?php esc_html_e('Provisioned by the agent platform. When configured, the catalog is pushed daily and on demand.', 'ucpacp-agent-for-woocommerce'); ?>
                        <?php if ($last = get_option('ucpwc_feed_last_push')) : ?>
                            <?php /* translators: %s: date/time of last push */ echo esc_html(sprintf(__('Last push: %s.', 'ucpacp-agent-for-woocommerce'), $last)); ?>
                        <?php endif; ?>
                    </p>
                    <p><button class="button" name="ucpwc_action" value="push_feed"><?php esc_html_e('Push now', 'ucpacp-agent-for-woocommerce'); ?></button></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Testing', 'ucpacp-agent-for-woocommerce'); ?></h2>
        <table class="form-table" style="max-width:900px">
            <tr>
                <th><label for="ucpwc_simulation_secret"><?php esc_html_e('Simulation secret', 'ucpacp-agent-for-woocommerce'); ?></label></th>
                <td><input type="text" class="regular-text" id="ucpwc_simulation_secret" name="ucpwc_simulation_secret" value="<?php echo esc_attr($field('ucpwc_simulation_secret')); ?>" autocomplete="off">
                    <p class="description"><?php esc_html_e('Enables POST /testing/simulate-shipping/{order_id} (used by the conformance suite). Empty = disabled. Never set this on a production store.', 'ucpacp-agent-for-woocommerce'); ?></p></td>
            </tr>
        </table>

        <p><button class="button button-primary" name="ucpwc_action" value="save"><?php esc_html_e('Save settings', 'ucpacp-agent-for-woocommerce'); ?></button></p>
        </form>
        </div>
        <?php
    }
}
