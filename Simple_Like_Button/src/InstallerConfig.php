<?php
declare(strict_types=1);
require_once __DIR__ . '/RequestSecurity.php';

/** Pure configuration generation, shared by the wizard and its regression tests. */
final class InstallerConfig
{
    public static function adminPath(array $server): string
    {
        $script = $server['SCRIPT_NAME'] ?? null;
        if (!is_string($script) || !str_ends_with($script, '/install/index.php')) throw new InvalidArgumentException('Unknown installer path.');
        $path = substr($script, 0, -strlen('install/index.php')) . 'admin/';
        // Validation-only identity, never written to generated configuration.
        return RequestSecurity::settings(['app_secret' => str_repeat('0', 64), 'site_origin' => 'https://example.invalid',
            'admin_security' => ['admin_path' => $path]], [])['admin_path'];
    }

    public static function adminSettings(string $environment, string $origin, string $path, bool $developmentConfirmed, array $server): array
    {
        $admin = ['environment' => $environment, 'origin' => $origin, 'admin_path' => $path, 'trusted_proxies' => []];
        $checked = RequestSecurity::settings(['app_secret' => str_repeat('0', 64), 'admin_security' => $admin], []);
        if ($checked['admin_path'] !== self::adminPath($server)) throw new InvalidArgumentException('Admin path differs from this installation.');
        if ($environment === 'development') {
            // Test the actual connection against the existing HTTP exception policy,
            // even if this installer request itself arrived over native TLS.
            $probe = array_replace($server, ['HTTPS' => 'off', 'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => $checked['admin_path']]);
            if (!$developmentConfirmed || !RequestSecurity::evaluate($checked, $probe)['allowed']) {
                throw new InvalidArgumentException('Explicit direct-loopback development confirmation is required.');
            }
        }
        $admin['origin'] = $checked['origin']->serialize();
        $admin['admin_path'] = $checked['admin_path'];
        return $admin;
    }

    public static function build(array $database, array $basic, string $secret, array $server): array
    {
        if (preg_match('/\A[a-f0-9]{64}\z/iD', $secret) !== 1) throw new InvalidArgumentException('Invalid installation secret.');
        $admin = $basic['admin_security'] ?? null;
        if (!is_array($admin) || !is_string($admin['environment'] ?? null) || !is_string($admin['origin'] ?? null)
            || !is_string($admin['admin_path'] ?? null)) throw new InvalidArgumentException('Admin security confirmation is missing.');
        $admin = self::adminSettings($admin['environment'], $admin['origin'], $admin['admin_path'],
            ($basic['admin_development_confirmed'] ?? false) === true, $server);
        return [
            'database' => ['dsn' => $database['dsn'], 'username' => $database['user'], 'password' => $database['password']],
            'app_secret' => $secret,
            'site_origin' => $basic['site_origin'],
            'admin_security' => $admin,
            'site_base_domain' => $basic['site_base_domain'],
            'site_base_domain_include_subdomains' => $basic['site_base_domain_include_subdomains'],
            'system_state' => 'enabled',
            'access_protection' => ['enabled' => true, 'auto_block' => true,
                'state' => ['window' => 60, 'threshold' => 2400], 'like' => ['window' => 60, 'threshold' => 240]],
            'admin_language' => 'ja', 'admin_theme' => 'green', 'widget_language' => 'ja', 'click_success_message' => 'like02',
            'show_like_count' => false, 'like_count_color' => '#767676', 'button_symbol_preset' => 'heart',
            'button_unliked_color' => '#666666', 'button_liked_color' => '#e53955', 'count_refresh_interval' => 5,
            'likes_per_24_hours' => 3, 'aggregation_start_threshold' => 1000, 'aggregation_batch_size' => 500,
            'timezone' => $basic['timezone'],
        ];
    }

    public static function contents(array $database, array $basic, string $secret, array $server): string
    {
        return "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export(self::build($database, $basic, $secret, $server), true) . ";\n";
    }
}
