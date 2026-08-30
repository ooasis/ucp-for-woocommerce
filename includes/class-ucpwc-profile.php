<?php
defined('ABSPATH') || exit;

class UCPWC_Profile
{
    const CAPABILITIES = [
        'dev.ucp.shopping.checkout'      => [],
        'dev.ucp.shopping.order'         => [],
        'dev.ucp.shopping.discount'      => ['extends' => ['dev.ucp.shopping.checkout']],
        'dev.ucp.shopping.fulfillment'   => ['extends' => 'dev.ucp.shopping.checkout'],
        'dev.ucp.shopping.buyer_consent' => ['extends' => 'dev.ucp.shopping.checkout'],
    ];

    /** Generate + persist an ES256 signing key pair (JWK) on activation. */
    public static function ensure_signing_key(): void
    {
        if (get_option('ucpwc_signing_key')) {
            return;
        }
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        openssl_pkey_export($key, $pem);
        $jwk = \UcpSpike\ec_pem_to_jwk($key, '');
        // kid = RFC 7638 thumbprint (lexicographic members crv,kty,x,y)
        $thumb = ['crv' => $jwk['crv'], 'kty' => $jwk['kty'], 'x' => $jwk['x'], 'y' => $jwk['y']];
        $jwk['kid'] = \UcpSpike\b64url_encode(hash('sha256', json_encode($thumb, JSON_UNESCAPED_SLASHES), true));
        update_option('ucpwc_signing_key', ['pem' => $pem, 'jwk' => $jwk], false);
    }

    public static function public_jwk(): array
    {
        return get_option('ucpwc_signing_key')['jwk'];
    }

    /** Active key first, then retired keys still inside their rotation grace period. */
    public static function published_keys(): array
    {
        return array_merge([self::public_jwk()], get_option('ucpwc_retired_keys', []));
    }

    /** Spec rotation: publish a fresh key, keep the old one verifying during grace. */
    public static function rotate_signing_key(): void
    {
        $retired = get_option('ucpwc_retired_keys', []);
        array_unshift($retired, self::public_jwk());
        update_option('ucpwc_retired_keys', array_slice($retired, 0, 3), false);
        delete_option('ucpwc_signing_key');
        self::ensure_signing_key();
    }

    public static function private_key(): array
    {
        $pem = get_option('ucpwc_signing_key')['pem'];
        return ['kty' => 'EC', 'crv' => 'P-256', 'openssl_key' => openssl_pkey_get_private($pem)];
    }

    public static function endpoint(): string
    {
        return untrailingslashit(rest_url('ucp/v1'));
    }

    public static function capability_entries(): array
    {
        $caps = [];
        foreach (self::CAPABILITIES as $name => $extra) {
            $short = str_replace('dev.ucp.shopping.', '', $name);
            $caps[$name] = [array_merge([
                'version' => UCPWC_VERSION,
                'spec'    => 'https://ucp.dev/' . UCPWC_VERSION . '/specification/' . str_replace('_', '-', $short),
                'schema'  => 'https://ucp.dev/' . UCPWC_VERSION . '/schemas/shopping/' . $short . '.json',
            ], $extra)];
        }
        return $caps;
    }

    public static function payment_handlers(): array
    {
        return UCPWC_Payments::ucp_handlers();
    }

    public static function business_profile(): array
    {
        $keys = self::published_keys();
        return [
            'ucp' => [
                'version'  => UCPWC_VERSION,
                'services' => [
                    'dev.ucp.shopping' => [[
                        'version'   => UCPWC_VERSION,
                        'spec'      => 'https://ucp.dev/' . UCPWC_VERSION . '/specification/overview',
                        'transport' => 'rest',
                        'endpoint'  => self::endpoint(),
                        'schema'    => 'https://ucp.dev/' . UCPWC_VERSION . '/services/shopping/openapi.json',
                    ]],
                ],
                'capabilities'     => self::capability_entries(),
                'payment_handlers' => self::payment_handlers(),
                'keys'             => $keys,
            ],
            'signing_keys' => $keys,
        ];
    }

    /** The `ucp` envelope embedded in checkout responses. */
    public static function response_envelope(): array
    {
        $caps = [];
        foreach (array_keys(self::CAPABILITIES) as $name) {
            $caps[$name] = [['name' => $name, 'version' => UCPWC_VERSION]];
        }
        return ['version' => UCPWC_VERSION, 'capabilities' => $caps, 'payment_handlers' => self::payment_handlers()];
    }

    /** Fetch + cache the platform profile named in the UCP-Agent header. Failure is non-fatal. */
    public static function fetch_platform_profile(string $ucp_agent): ?array
    {
        if (!preg_match('/profile="([^"]+)"/', $ucp_agent, $m)) {
            return null;
        }
        $url = $m[1];
        $cached = get_transient('ucpwc_profile_' . md5($url));
        if (is_array($cached)) {
            return $cached;
        }
        $res = wp_remote_get($url, ['timeout' => 5]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
            return null;
        }
        $profile = json_decode(wp_remote_retrieve_body($res), true);
        if (is_array($profile)) {
            set_transient('ucpwc_profile_' . md5($url), $profile, 5 * MINUTE_IN_SECONDS);
        }
        return is_array($profile) ? $profile : null;
    }

    /** Extract the order webhook URL from a platform profile (first capability config that has one). */
    public static function webhook_url_from_profile(?array $profile): ?string
    {
        $caps = $profile['ucp']['capabilities'] ?? [];
        foreach ($caps as $entries) {
            foreach ((array)$entries as $entry) {
                if (!empty($entry['config']['webhook_url'])) {
                    return $entry['config']['webhook_url'];
                }
            }
        }
        return null;
    }
}
