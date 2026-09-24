<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/LikeService.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    pollyanna_json_response(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
if ($contentType !== 'application/json') {
    pollyanna_json_response(415, ['success' => false, 'message' => 'JSON is required.']);
}

$input = file_get_contents('php://input', false, null, 0, 4097);
if ($input === false || strlen($input) > 4096) {
    pollyanna_json_response(413, ['success' => false, 'message' => 'Request is too large.']);
}

try {
    $body = json_decode($input, true, 8, JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    pollyanna_json_response(400, ['success' => false, 'message' => 'Invalid JSON.']);
}

if (!is_array($body) || !is_string($body['identifier'] ?? null) || !is_string($body['client_token'] ?? null)) {
    pollyanna_json_response(400, ['success' => false, 'message' => 'Identifier and client token are required.']);
}

$identifier = trim($body['identifier']);
$characterCount = preg_match_all('/./us', $identifier);
if (
    $identifier === ''
    || $characterCount === false
    || $characterCount > 50
    || preg_match('/[\r\n\x00]/', $identifier) === 1
) {
    pollyanna_json_response(422, ['success' => false, 'message' => 'Invalid identifier.']);
}

$clientToken = $body['client_token'];
if (preg_match('/\A[a-f0-9]{64}\z/i', $clientToken) !== 1) {
    pollyanna_json_response(422, ['success' => false, 'message' => 'Invalid client token.']);
}

try {
    $config = pollyanna_config();
    $clientHash = hash_hmac('sha256', strtolower($clientToken), $config['app_secret']);
    $service = new LikeService(pollyanna_pdo());
    $response = [
        'success' => true,
        'liked' => $service->hasLiked($identifier, $clientHash),
    ];
    if (pollyanna_count_display_enabled()) {
        $response['count'] = $service->count($identifier);
    }
    pollyanna_json_response(200, $response);
} catch (Throwable $error) {
    error_log('Pollyanna Like state request failed: ' . $error->getMessage());
    pollyanna_json_response(503, ['success' => false, 'message' => 'Like state could not be loaded.']);
}
