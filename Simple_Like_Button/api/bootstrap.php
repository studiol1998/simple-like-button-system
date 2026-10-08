<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/OriginPolicy.php';

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
            pollyanna_json_response(403, ['success' => false, 'message' => 'Preflight request is not allowed.']);
        }
        header('Access-Control-Allow-Methods: POST');
        header('Access-Control-Allow-Headers: Content-Type');
        http_response_code(204);
        exit;
    }
    return $policy;
}
