<?php
declare(strict_types=1);
require_once __DIR__ . '/OriginPolicy.php';

/** Pure admin transport policy. Never infer a trusted URL from HTTP_HOST. */
final class RequestSecurity
{
    public static function settings(array $config, array $server): array
    {
        $settings = $config['admin_security'] ?? [];
        if (!is_array($settings)) throw new RuntimeException('Invalid admin transport settings.');
        $environment = $settings['environment'] ?? 'production';
        if (!in_array($environment, ['production', 'development'], true)) throw new RuntimeException('Invalid environment.');
        $originValue = $settings['origin'] ?? $config['site_origin'] ?? null;
        if (!is_string($originValue)) throw new RuntimeException('Admin origin is required.');
        $origin = PollyannaOrigin::parse($originValue);
        if ($environment === 'production' && $origin->scheme !== 'https') throw new RuntimeException('HTTPS origin is required.');
        if ($environment === 'development' && !self::loopbackHost($origin->host)) throw new RuntimeException('Development requires a loopback origin.');
        $script = $server['SCRIPT_NAME'] ?? '';
        $path = $settings['admin_path'] ?? (is_string($script) && str_ends_with($script, '/admin/index.php') ? substr($script, 0, -9) : null);
        if (!is_string($path) || !str_starts_with($path, '/') || !str_ends_with($path, '/admin/')) throw new RuntimeException('Admin path must be configured.');
        $segments = explode('/', trim($path, '/'));
        foreach ($segments as &$segment) {
            $segment = rawurldecode($segment);
            if ($segment === '' || in_array($segment, ['.', '..'], true)
                || preg_match('~[\x00-\x20\x7f/\\\\?#]~', $segment) !== 0 || preg_match('//u', $segment) !== 1) {
                throw new RuntimeException('Invalid admin path.');
            }
            $segment = rawurlencode($segment);
        }
        unset($segment);
        $path = '/' . implode('/', $segments) . '/';
        $proxies = $settings['trusted_proxies'] ?? [];
        if (!is_array($proxies) || !array_is_list($proxies)) throw new RuntimeException('Invalid trusted proxies.');
        foreach ($proxies as $proxy) {
            if (!is_string($proxy) || filter_var($proxy, FILTER_VALIDATE_IP) === false) throw new RuntimeException('Trusted proxies must be exact IP addresses.');
        }
        $secret = $config['app_secret'] ?? null;
        if (!is_string($secret) || preg_match('/\A[a-f0-9]{64}\z/iD', $secret) !== 1) throw new RuntimeException('Installation identity is unavailable.');
        // Stable beta-compatible fallback, independent of paths/Host/proxy headers.
        $instance = $settings['instance_id'] ?? substr(hash_hmac('sha256', 'pollyanna.admin.instance.v1', $secret), 0, 32);
        if (!is_string($instance) || preg_match('/\A[a-f0-9]{32}\z/D', $instance) !== 1) throw new RuntimeException('Invalid installation identity.');
        return ['environment' => $environment, 'origin' => $origin, 'admin_path' => $path,
            'trusted_proxies' => $proxies, 'instance_id' => $instance];
    }

    public static function evaluate(array $settings, array $server): array
    {
        $deny = static fn (int $status): array => ['allowed' => false, 'secure' => false, 'status' => $status, 'location' => null];
        $peer = $server['REMOTE_ADDR'] ?? null;
        $host = $server['HTTP_HOST'] ?? null;
        if (!is_string($peer) || filter_var($peer, FILTER_VALIDATE_IP) === false || !is_string($host)) return $deny(400);
        $native = $server['HTTPS'] ?? 'off';
        if (!is_string($native) || !in_array(strtolower($native), ['on', '1', 'off', '0', ''], true)) return $deny(400);
        $secure = in_array(strtolower($native), ['on', '1'], true);
        $trusted = false;
        foreach ($settings['trusted_proxies'] as $proxy) {
            if (inet_pton($proxy) === inet_pton($peer)) $trusted = true;
        }
        if ($trusted) {
            // One designated header and one value; no comma chains or fallback guessing.
            $proto = $server['HTTP_X_FORWARDED_PROTO'] ?? null;
            if (!is_string($proto) || !in_array($proto, ['https', 'http'], true)) return $deny(400);
            $secure = $proto === 'https';
        }
        try { $requestOrigin = PollyannaOrigin::parse(($secure ? 'https' : 'http') . '://' . $host); }
        catch (Throwable) { return $deny(400); }
        $expected = $settings['origin'];
        if ($requestOrigin->host !== $expected->host) return $deny(400);
        if ($secure && $requestOrigin->port !== $expected->port) return $deny(400);
        if (!$secure && !in_array($requestOrigin->port, [80, $expected->port], true)) return $deny(400);
        $forwarded = array_filter(array_keys($server), static fn ($key): bool => is_string($key)
            && ($key === 'HTTP_FORWARDED' || str_starts_with($key, 'HTTP_X_FORWARDED_')));
        $local = $settings['environment'] === 'development' && self::loopbackHost($peer)
            && self::loopbackHost($requestOrigin->host) && $settings['trusted_proxies'] === [] && $forwarded === [];
        if ($secure || $local) {
            $uri = $server['REQUEST_URI'] ?? null;
            $requestPath = is_string($uri) ? explode('?', $uri, 2)[0] : null;
            // Cookie Path matching is lexical. Do not authenticate an alias or
            // differently encoded URL that would fail to receive this cookie.
            if (!in_array($requestPath, [$settings['admin_path'], $settings['admin_path'] . 'index.php'], true)) return $deny(400);
            return ['allowed' => true, 'secure' => $secure, 'status' => 200, 'location' => null];
        }
        if (($server['REQUEST_METHOD'] ?? '') === 'GET' && $expected->scheme === 'https') {
            return ['allowed' => false, 'secure' => false, 'status' => 302,
                'location' => $expected->serialize() . $settings['admin_path']];
        }
        return $deny(403);
    }

    private static function loopbackHost(string $host): bool
    {
        return in_array(strtolower($host), ['localhost', '127.0.0.1', '::1', '::ffff:127.0.0.1'], true);
    }
}
