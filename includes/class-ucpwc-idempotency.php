<?php
defined('ABSPATH') || exit;

class UCPWC_Idempotency
{
    /**
     * Look up a stored response for this key. Returns [status, body-array] to replay,
     * null when the key is new, or throws-equivalent conflict marker on hash mismatch.
     */
    public static function check(string $key, string $hash): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT request_hash, response_status, response_body FROM {$wpdb->prefix}ucp_idempotency WHERE idem_key = %s",
            $key
        ), ARRAY_A);
        if (!$row) {
            return null;
        }
        if ($row['request_hash'] !== $hash) {
            return ['conflict' => true];
        }
        return ['status' => (int)$row['response_status'], 'body' => json_decode($row['response_body'], true)];
    }

    public static function store(string $key, string $hash, int $status, array $body): void
    {
        global $wpdb;
        $wpdb->replace($wpdb->prefix . 'ucp_idempotency', [
            'idem_key'        => $key,
            'request_hash'    => $hash,
            'response_status' => $status,
            'response_body'   => wp_json_encode($body),
            'created_at'      => gmdate('Y-m-d H:i:s'),
        ]);
        // ponytail: no purge job — add a daily cron deleting rows older than 48h when the table grows.
    }

    public static function hash(string $operation, ?string $resource_id, string $raw_body): string
    {
        return hash('sha256', $operation . '|' . ($resource_id ?? '') . '|' . hash('sha256', $raw_body));
    }
}
