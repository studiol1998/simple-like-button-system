<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/LikeService.php';
require_once dirname(__DIR__) . '/src/AggregationService.php';

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
    $widgetText = pollyanna_widget_runtime_text();
    $pdo = pollyanna_pdo();
    if (!pollyanna_acquire_like_request_lock($pdo)) {
        pollyanna_json_response(503, ['success' => false, 'message' => 'Like service is busy. Please try again.']);
    }
    if (pollyanna_system_state() === 'maintenance') {
        pollyanna_json_response(503, [
            'success' => false,
            'maintenance' => true,
            'widget_text' => $widgetText,
            'message' => 'The Like service is under maintenance.',
        ]);
    }

    $config = pollyanna_config();
    $successMessage = pollyanna_widget_success_message();
    $clientHash = hash_hmac('sha256', strtolower($clientToken), $config['app_secret']);
    $service = new LikeService($pdo);
    if (!$service->recordWithinLimit($identifier, $clientHash, pollyanna_rate_limit())) {
        pollyanna_json_response(429, [
            'success' => false,
            'rate_limited' => true,
            'widget_text' => $widgetText,
            'message' => $widgetText['rate_limit'],
        ]);
    }
    try {
        $aggregationSettings = pollyanna_aggregation_settings();
        if ($service->pendingCount() >= $aggregationSettings['aggregation_start_threshold']) {
            $aggregator = new AggregationService($pdo);
            $aggregator->aggregateBatch(
                $aggregationSettings['aggregation_batch_size'],
                $aggregationSettings['timezone']
            );
        }
    } catch (Throwable $aggregationError) {
        error_log('Pollyanna automatic aggregation failed: ' . $aggregationError->getMessage());
    }
    $response = ['success' => true];
    $response['success_message'] = $successMessage;
    $response['widget_text'] = $widgetText;
    if (pollyanna_count_display_enabled()) {
        try {
            $response['count'] = $service->count($identifier);
        } catch (Throwable $countError) {
            error_log('Pollyanna Like count request failed: ' . $countError->getMessage());
        }
    }
    pollyanna_json_response(201, $response);
} catch (Throwable $error) {
    error_log('Pollyanna Like request failed: ' . $error->getMessage());
    pollyanna_json_response(503, ['success' => false, 'message' => 'Like could not be saved.']);
}
