<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/src/AdminPasswordPolicy.php';
$checks = 0;
$assert = static function (bool $ok, string $label) use (&$checks): void {
    $checks++; if (!$ok) throw new RuntimeException($label);
};
foreach ([str_repeat('a', 11), str_repeat('a', 73), str_repeat('日', 25), "\xff" . str_repeat('a', 12), str_repeat('a', 12) . "\0"] as $value) {
    $assert(!AdminPasswordPolicy::isValid($value), 'Invalid new password rejected');
}
foreach ([str_repeat('a', 12), str_repeat('a', 71), str_repeat('a', 72), str_repeat('日', 12), str_repeat('日', 24), str_repeat('😀', 18), '  Mixed_日本語_12!  '] as $value) {
    $assert(AdminPasswordPolicy::isValid($value), 'Valid new password accepted');
}
$value = '  Mixed_日本語_12!  ';
$hash = AdminPasswordPolicy::hash($value);
$assert(password_get_info($hash)['algoName'] === 'bcrypt', 'Explicit bcrypt');
$assert(password_verify($value, $hash), 'Spaces and Unicode preserved');
$assert(!password_verify(trim($value), $hash), 'Spaces are significant');
$assert(!password_verify('  Mixed_日本語_12?  ', $hash), 'Symbols are significant');
$hash = AdminPasswordPolicy::hash(str_repeat('a', 72));
$assert(password_verify(str_repeat('a', 72), $hash), '72-byte boundary accepted');
$assert(!password_verify(str_repeat('a', 71) . 'b', $hash), 'Different allowed input rejected');
try { AdminPasswordPolicy::hash(str_repeat('a', 72) . 'b'); $assert(false, 'No truncation'); }
catch (InvalidArgumentException) { $assert(true, 'Overlong input is not hashed'); }
$legacy = password_hash(str_repeat('a', 72) . 'old-tail', PASSWORD_BCRYPT);
$assert(password_verify(str_repeat('a', 72) . 'old-tail', $legacy), 'Legacy long password remains verifiable');
$assert(password_verify(str_repeat('a', 72) . 'other-tail', $legacy), 'Legacy bcrypt limitation is explicit');
echo "Administrator password policy tests passed ({$checks} assertions).\n";
