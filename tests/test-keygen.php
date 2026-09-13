<?php
// Standalone check of the ES256 keygen fallback for PHPs with no reachable
// default openssl.cnf (the WordPress Studio / XAMPP activation fatal).
// Run: php tests/test-keygen.php   (re-execs itself with OPENSSL_CONF broken)
declare(strict_types=1);

if (getenv('UCPWC_KEYGEN_CHILD') === false) {
    passthru('OPENSSL_CONF=/nonexistent UCPWC_KEYGEN_CHILD=1 ' . escapeshellarg(PHP_BINARY) . ' -d zend.assertions=1 ' . escapeshellarg(__FILE__), $code);
    exit($code);
}

define('ABSPATH', '/');
require __DIR__ . '/../includes/signatures.php';

$args = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
$broken = openssl_pkey_new($args); // may or may not fail depending on the local build
$args['config'] = __DIR__ . '/../includes/openssl.cnf';
$key = openssl_pkey_new($args);

assert($key !== false || print("FAIL  openssl_pkey_new with bundled config\n"));
assert(openssl_pkey_export($key, $pem, null, $args) || print("FAIL  openssl_pkey_export\n"));
$jwk = \UCPWC\Signatures\ec_pem_to_jwk($key, 'k1');
assert($jwk['crv'] === 'P-256' && strlen(\UCPWC\Signatures\b64url_decode($jwk['x'])) === 32
    || print("FAIL  jwk shape\n"));

// Round-trip: sign with the generated key, verify via the exported JWK.
$priv = ['kty' => 'EC', 'crv' => 'P-256', 'openssl_key' => openssl_pkey_get_private($pem)];
$sig = \UCPWC\Signatures\sign_base('test-base', $priv);
assert(\UCPWC\Signatures\verify_base('test-base', $sig, $jwk) || print("FAIL  sign/verify round-trip\n"));

echo "PASS  keygen fallback (default config " . ($broken ? "worked" : "broken, as in Studio") . ")\n";
