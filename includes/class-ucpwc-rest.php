<?php
defined('ABSPATH') || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages become JSON API payloads (UCP/ACP error envelopes), never HTML output.

class UCPWC_Rest
{
    const NS = 'ucp/v1';

    public static function register_routes(): void
    {
        $r = fn(string $op, ?string $resource_key = null) =>
            fn(WP_REST_Request $req) => self::dispatch($op, $req, $resource_key);

        register_rest_route(self::NS, '/checkout-sessions', [
            'methods' => 'POST', 'callback' => $r('create_checkout'), 'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/checkout-sessions/(?P<id>[A-Za-z0-9\-]+)', [
            ['methods' => 'GET', 'callback' => $r('get_checkout', 'id'), 'permission_callback' => '__return_true'],
            ['methods' => 'PUT', 'callback' => $r('update_checkout', 'id'), 'permission_callback' => '__return_true'],
        ]);
        register_rest_route(self::NS, '/checkout-sessions/(?P<id>[A-Za-z0-9\-]+)/complete', [
            'methods' => 'POST', 'callback' => $r('complete_checkout', 'id'), 'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/checkout-sessions/(?P<id>[A-Za-z0-9\-]+)/cancel', [
            'methods' => 'POST', 'callback' => $r('cancel_checkout', 'id'), 'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/orders/(?P<id>[A-Za-z0-9\-]+)', [
            ['methods' => 'GET', 'callback' => $r('get_order', 'id'), 'permission_callback' => '__return_true'],
            ['methods' => 'PUT', 'callback' => $r('update_order', 'id'), 'permission_callback' => '__return_true'],
        ]);
    }

    private static function dispatch(string $op, WP_REST_Request $req, ?string $resource_key): WP_REST_Response
    {
        try {
            self::check_version($req->get_header('ucp-agent') ?? '');
            self::verify_signature_if_present($req);
            $id = $resource_key ? $req->get_param($resource_key) : null;
            $mutating = !str_starts_with($op, 'get_');
            $success_status = $op === 'create_checkout' ? 201 : 200;

            if ($mutating && $op !== 'update_order') {
                $idem_key = $req->get_header('idempotency-key');
                if (!$idem_key) {
                    throw new UCPWC_Error(422, 'INVALID_REQUEST', 'Idempotency-Key header is required');
                }
                $hash = UCPWC_Idempotency::hash($op, $id, $req->get_body());
                $stored = UCPWC_Idempotency::check($idem_key, $hash);
                if ($stored !== null) {
                    if (isset($stored['conflict'])) {
                        throw new UCPWC_Error(409, 'IDEMPOTENCY_CONFLICT', 'Idempotency key reused with different parameters');
                    }
                    return new WP_REST_Response($stored['body'], $stored['status']);
                }
                $body = self::execute($op, $req, $id);
                UCPWC_Idempotency::store($idem_key, $hash, $success_status, $body);
                return new WP_REST_Response($body, $success_status);
            }
            return new WP_REST_Response(self::execute($op, $req, $id), $success_status);
        } catch (UCPWC_Error $e) {
            return self::error($e);
        } catch (Throwable $e) {
            return self::error(new UCPWC_Error(500, 'INTERNAL_ERROR', $e->getMessage()));
        }
    }

    private static function execute(string $op, WP_REST_Request $req, ?string $id): array
    {
        $json = $req->get_json_params();
        if (!is_array($json)) {
            $json = [];
        }
        $agent = $req->get_header('ucp-agent') ?? '';
        return match ($op) {
            'create_checkout'   => UCPWC_Checkout::create(self::require_items($json), $agent),
            'get_checkout'      => UCPWC_Checkout::load($id),
            'update_checkout'   => UCPWC_Checkout::update($id, $json, $agent),
            'complete_checkout' => UCPWC_Checkout::complete($id, self::require_payment($json)),
            'cancel_checkout'   => UCPWC_Checkout::cancel($id),
            'get_order'         => UCPWC_Orders::load($id),
            'update_order'      => UCPWC_Orders::replace($id, $json),
        };
    }

    private static function require_items(array $json): array
    {
        if (empty($json['line_items']) && empty($json['cart_id'])) {
            throw new UCPWC_Error(422, 'INVALID_REQUEST', 'line_items or cart_id is required');
        }
        return $json;
    }

    private static function require_payment(array $json): array
    {
        if (!isset($json['payment'])) {
            throw new UCPWC_Error(422, 'INVALID_REQUEST', 'payment is required');
        }
        return $json;
    }

    /** Date-based version negotiation from the UCP-Agent header. */
    private static function check_version(string $ucp_agent): void
    {
        if (!$ucp_agent || !preg_match('/(?:^|;)\s*version=(?:"([^"]+)"|([^;]+))/i', $ucp_agent, $m)) {
            return; // no version param: compatible
        }
        $version = trim($m[1] !== '' ? $m[1] : $m[2]);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $version) || !strtotime($version)) {
            throw new UCPWC_Error(422, 'VERSION_INVALID_FORMAT', 'Invalid UCP version format: ' . $version);
        }
        if ($version > UCPWC_VERSION) {
            throw new UCPWC_Error(422, 'VERSION_UNSUPPORTED',
                "Version $version is not supported. This merchant implements version " . UCPWC_VERSION . '.');
        }
    }

    /**
     * RFC 9421 verification of inbound requests — enforced only when the request
     * carries signature headers (the spec permits unsigned requests with
     * alternative auth; strict mode is a future admin toggle).
     */
    private static function verify_signature_if_present(WP_REST_Request $req): void
    {
        $sig_input = $req->get_header('signature-input');
        if (!$sig_input || !$req->get_header('signature')) {
            if (get_option('ucpwc_strict_signatures') === 'yes' && ucpwc_can_premium('strict_signatures')) {
                throw new UCPWC_Error(401, 'signature_missing', 'This merchant requires signed requests (RFC 9421)');
            }
            return;
        }
        $agent = $req->get_header('ucp-agent') ?? '';
        $profile = UCPWC_Profile::fetch_platform_profile($agent);
        $keys = $profile['ucp']['keys'] ?? $profile['signing_keys'] ?? [];
        if (!$keys) {
            throw new UCPWC_Error(424, 'profile_unreachable', 'Unable to fetch signer profile keys');
        }
        $headers = [];
        foreach ($req->get_headers() as $name => $values) {
            $headers[str_replace('_', '-', strtolower($name))] = implode(', ', $values);
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the raw URI is required: it is cryptographic input (@path/@query components of the RFC 9421 signature base); altering it breaks verification.
        $parts = wp_parse_url(home_url(wp_unslash($_SERVER['REQUEST_URI'] ?? '/')));
        try {
            \UcpSpike\verify_rest_request([
                'method'    => $req->get_method(),
                'authority' => $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''),
                'path'      => $parts['path'] ?? '/',
                'query'     => $parts['query'] ?? '',
                'body'      => $req->get_body() ?: null,
                'headers'   => $headers,
            ], $keys);
        } catch (\UcpSpike\SignatureException $e) {
            $http = in_array($e->reason, ['digest_mismatch', 'algorithm_unsupported'], true) ? 400 : 401;
            throw new UCPWC_Error($http, $e->reason, $e->getMessage());
        }
    }

    private static function error(UCPWC_Error $e): WP_REST_Response
    {
        return new WP_REST_Response([
            'ucp'      => ['version' => UCPWC_VERSION, 'status' => 'error'],
            'messages' => [[
                'type'     => 'error',
                'code'     => $e->ucp_code,
                'content'  => $e->content,
                'severity' => $e->severity,
            ]],
        ], $e->http);
    }
}
