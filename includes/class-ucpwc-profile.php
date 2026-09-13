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
        $args = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        // Some PHP builds warn or fail here (WordPress Studio's php-wasm cannot
        // generate EC keys at all; XAMPP lacks a default openssl.cnf); activation
        // must emit no output and failure is handled below, so silence the block.
        set_error_handler('__return_true'); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- not debug code: silences openssl warnings on PHP builds where EC keygen fails (failure handled below); restored in finally.
        try {
            $key = openssl_pkey_new($args);
            if (!$key) {
                // No reachable default openssl.cnf; retry with the bundled one.
                $args['config'] = __DIR__ . '/openssl.cnf';
                $key = openssl_pkey_new($args);
            }
            $exported = $key && openssl_pkey_export($key, $pem, null, $args);
        } finally {
            restore_error_handler();
        }
        if ($exported) {
            $jwk = \UCPWC\Signatures\ec_pem_to_jwk($key, '');
            $thumb = ['crv' => $jwk['crv'], 'kty' => $jwk['kty'], 'x' => $jwk['x'], 'y' => $jwk['y']];
            $stored = ['pem' => $pem];
        } else {
            // This PHP cannot generate EC keys: fall back to Ed25519 (EdDSA, also in
            // the UCP algorithm set). WordPress guarantees the sodium_crypto_sign_*
            // functions via the bundled sodium_compat polyfill.
            $kp = sodium_crypto_sign_keypair();
            $jwk = ['kid' => '', 'kty' => 'OKP', 'crv' => 'Ed25519',
                    'x' => \UCPWC\Signatures\b64url_encode(sodium_crypto_sign_publickey($kp))];
            $thumb = ['crv' => $jwk['crv'], 'kty' => $jwk['kty'], 'x' => $jwk['x']];
            $stored = ['ed25519_secret' => base64_encode(sodium_crypto_sign_secretkey($kp))];
        }
        // kid = RFC 7638 thumbprint (lexicographic members)
        $jwk['kid'] = \UCPWC\Signatures\b64url_encode(hash('sha256', wp_json_encode($thumb, JSON_UNESCAPED_SLASHES), true));
        $stored['jwk'] = $jwk;
        update_option('ucpwc_signing_key', $stored, false);
    }

    /** The stored key pair, generated on demand if activation did not run (e.g. network bulk-activate). */
    private static function signing_key(): array
    {
        $key = get_option('ucpwc_signing_key');
        if (!is_array($key)) {
            self::ensure_signing_key();
            $key = get_option('ucpwc_signing_key');
        }
        if (!is_array($key)) {
            throw new \RuntimeException('ucpwc: EC key generation failed — check the OpenSSL PHP extension');
        }
        return $key;
    }

    public static function public_jwk(): array
    {
        return self::signing_key()['jwk'];
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
        $key = self::signing_key();
        if (isset($key['ed25519_secret'])) {
            return ['kty' => 'OKP', 'crv' => 'Ed25519', 'ed25519_secret' => base64_decode($key['ed25519_secret'])];
        }
        return ['kty' => 'EC', 'crv' => 'P-256', 'openssl_key' => openssl_pkey_get_private($key['pem'])];
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

    /**
     * Fetch + cache the platform profile named in the UCP-Agent header. Failure is non-fatal.
     * The URL is attacker-controlled (any anonymous caller sets the header), so it goes through
     * wp_safe_remote_get: http(s) only, no loopback/private/link-local hosts, standard ports.
     */
    public static function fetch_platform_profile(string $ucp_agent): ?array
    {
        if (!preg_match('/profile="([^"]+)"/', $ucp_agent, $m)) {
            return null;
        }
        $url = esc_url_raw($m[1], ['http', 'https']);
        if (!$url) {
            return null;
        }
        $cached = get_transient('ucpwc_profile_' . md5($url));
        if (is_array($cached)) {
            return $cached;
        }
        $res = wp_safe_remote_get($url, ['timeout' => 5]);
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
