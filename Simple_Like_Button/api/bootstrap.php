<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/OriginPolicy.php';
require_once dirname(__DIR__) . '/src/AccessProtectionService.php';

function pollyanna_public_access_check(bool $countRequest = true): int
{
    $api = basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'state.php' ? 'state' : 'like';
    try {
        $service = new AccessProtectionService(pollyanna_pdo());
        if (!$service->available()) {
            error_log('Pollyanna access protection unavailable: verify database connectivity and required access protection tables.');
            return 0; // Preserve existing installations until their additive migration is applied.
        }
        if (!$countRequest) { $service->maintain(pollyanna_access_settings()); return 0; }
        $hash = AccessProtectionService::ipHash((string) ($_SERVER['REMOTE_ADDR'] ?? ''), pollyanna_config()['app_secret']);
        return $service->record($api, $hash, pollyanna_access_settings());
    } catch (Throwable) {
        // Never include connection addresses or hashes in application logs.
        error_log('Pollyanna access protection check failed; public API continues without this check.');
        return 0;
    }
}

function pollyanna_public_api_origin_guard(): PollyannaOriginPolicy
{
    $raw = $_SERVER['HTTP_ORIGIN'] ?? '';
    try {
        if (!is_string($raw) || $raw === '') throw new InvalidArgumentException('Origin is required.');
        $origin = PollyannaOrigin::parse($raw);
        $config = pollyanna_config();
        $policy = new PollyannaOriginPolicy($config, []);
        if (!$policy->allows($origin)) {
            $policy = new PollyannaOriginPolicy($config, pollyanna_allowed_origins());
            if (!$policy->allows($origin)) throw new InvalidArgumentException('Origin is not allowed.');
        }
    } catch (Throwable $error) {
        pollyanna_public_access_check();
        pollyanna_json_response(403, ['success' => false, 'message' => 'Origin is not allowed.']);
    }

    $config = $config ?? pollyanna_config();
    $sameOrigin = false;
    try { $sameOrigin = $origin->serialize() === PollyannaOrigin::parse((string) ($config['site_origin'] ?? ''))->serialize(); } catch (Throwable) {}
    if (!$sameOrigin) {
        header('Access-Control-Allow-Origin: ' . $origin->serialize());
        header('Vary: Origin');
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        $method = strtoupper(trim((string) ($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ?? '')));
        $headers = strtolower(trim((string) ($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? '')));
        $tokens = array_values(array_filter(array_map('trim', explode(',', $headers)), static fn (string $v): bool => $v !== ''));
        if ($method !== 'POST' || $tokens !== ['content-type']) {
            pollyanna_public_access_check();
            pollyanna_json_response(403, ['success' => false, 'message' => 'Preflight request is not allowed.']);
        }
        pollyanna_public_access_check(false);
        header('Access-Control-Allow-Methods: POST');
        header('Access-Control-Allow-Headers: Content-Type');
        http_response_code(204);
        exit;
    }
    $retryAfter = pollyanna_public_access_check();
    if ($retryAfter > 0) {
        header('Retry-After: ' . $retryAfter);
        $response = ['success' => false, 'access_blocked' => true,
            'retry_after' => $retryAfter, 'message' => 'Public API access is temporarily blocked.'];
        try { $response['widget_text'] = pollyanna_widget_runtime_text(); } catch (Throwable) {}
        pollyanna_json_response(429, $response);
    }
    return $policy;
}
