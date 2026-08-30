<?php
// Standalone check of Stripe response -> UCP error mapping (no WordPress needed).
// Run: php tests/test-stripe-mapping.php
declare(strict_types=1);

define('ABSPATH', '/');
class UCPWC_Error extends Exception
{
    public function __construct(public readonly int $http, public readonly string $ucp_code,
                                public readonly string $content, public readonly string $severity = 'unrecoverable')
    {
        parent::__construct($content);
    }
}
require __DIR__ . '/../includes/class-ucpwc-payments.php';

$fails = 0;
function check(bool $ok, string $name): void
{
    global $fails;
    echo ($ok ? 'PASS' : 'FAIL') . "  $name\n";
    if (!$ok) $fails++;
}
function expect(callable $fn, int $http, string $code, string $name): void
{
    try {
        $fn();
        check(false, "$name (no exception)");
    } catch (UCPWC_Error $e) {
        check($e->http === $http && $e->ucp_code === $code, "$name (got {$e->http} {$e->ucp_code})");
    }
}

// success shapes
check(UCPWC_Payments::map_stripe_response(200, ['id' => 'pi_1', 'status' => 'succeeded']) === 'pi_1', 'succeeded intent returns id');
check(UCPWC_Payments::map_stripe_response(200, ['id' => 'pi_2', 'status' => 'processing']) === 'pi_2', 'processing intent accepted');

// card declined (Stripe's documented 402 shape)
expect(fn() => UCPWC_Payments::map_stripe_response(402, ['error' => [
    'type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'insufficient_funds',
    'message' => 'Your card was declined.',
]]), 402, 'PAYMENT_DECLINED', 'card_declined maps to 402 PAYMENT_DECLINED');

// non-decline card error (bad cvc)
expect(fn() => UCPWC_Payments::map_stripe_response(402, ['error' => [
    'type' => 'card_error', 'code' => 'incorrect_cvc', 'message' => 'Incorrect CVC.',
]]), 402, 'PAYMENT_DECLINED', 'incorrect_cvc maps to 402 PAYMENT_DECLINED');

// API error (invalid key etc.) is not a decline
expect(fn() => UCPWC_Payments::map_stripe_response(401, ['error' => [
    'type' => 'invalid_request_error', 'message' => 'Invalid API Key provided',
]]), 502, 'PAYMENT_FAILED', 'API error maps to 502 PAYMENT_FAILED');

// intent stuck in requires_action (3DS we cannot drive server-side)
expect(fn() => UCPWC_Payments::map_stripe_response(200, ['id' => 'pi_3', 'status' => 'requires_action']),
    402, 'PAYMENT_DECLINED', 'requires_action treated as not completed');

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILURES\n";
exit($fails === 0 ? 0 : 1);
