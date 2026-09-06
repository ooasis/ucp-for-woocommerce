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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin-owned table; no core API covers it.
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT request_hash, response_status, response_body FROM {$wpdb->prefix}ucpwc_idempotency WHERE idem_key = %s",
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
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin-owned table; no core API covers it.
        $wpdb->replace($wpdb->prefix . 'ucpwc_idempotency', [
            'idem_key'        => $key,
            'request_hash'    => $hash,
            'response_status' => $status,
            'response_body'   => wp_json_encode($body),
            'created_at'      => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** Daily cron: idempotency keys only need to survive client retries. */
    public static function purge(int $max_age_seconds = 2 * DAY_IN_SECONDS): int
    {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- plugin-owned table; no core API covers it.
        return (int)$wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}ucpwc_idempotency WHERE created_at < %s",
            gmdate('Y-m-d H:i:s', time() - $max_age_seconds)
        ));
    }

    public static function hash(string $operation, ?string $resource_id, string $raw_body): string
    {
        return hash('sha256', $operation . '|' . ($resource_id ?? '') . '|' . hash('sha256', $raw_body));
    }
}
