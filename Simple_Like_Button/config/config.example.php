<?php
declare(strict_types=1);

return [
    'database' => [
        'dsn' => 'mysql:host=localhost;dbname=pollyanna;charset=utf8mb4',
        'username' => 'replace-with-database-user',
        'password' => 'replace-with-database-password',
    ],
    // Generate a unique 64-character hexadecimal value for each installation.
    'app_secret' => 'replace-with-64-hexadecimal-characters-generated-randomly',
    // Origin hosting Pollyanna, plus the administrator-confirmed site domain policy.
    'site_origin' => 'https://example.com',
    // Optional admin-only policy. Existing HTTPS site_origin and app_secret provide
    // production defaults and a stable session identity when this key is absent.
    'admin_security' => [
        'environment' => 'production',
        // Set origin only when the admin HTTPS origin differs from site_origin.
        // 'origin' => 'https://example.com',
        // Set admin_path if SCRIPT_NAME does not identify the real install path.
        // 'admin_path' => '/pollyanna/admin/',
        'trusted_proxies' => [], // Exact IP addresses only; no implicit trust.
        // Optional unique 32-character lowercase hexadecimal installation ID.
        // 'instance_id' => 'replace-with-a-unique-32-hex-id',
    ],
    'site_base_domain' => 'example.com',
    'site_base_domain_include_subdomains' => true,
    // Default API state; maintenance mode rejects public Like and state requests.
    'system_state' => 'enabled',
    // New installation defaults, matching the Web installer. Existing configs are unchanged.
    'access_protection' => ['enabled' => true, 'auto_block' => true,
        'state' => ['window' => 60, 'threshold' => 2400],
        'like' => ['window' => 60, 'threshold' => 240]],
    // Default language for the admin interface.
    'admin_language' => 'ja',
    // Default color theme for the admin interface.
    'admin_theme' => 'green',
    // Language and success message used by the embedded Like button.
    'widget_language' => 'ja',
    'click_success_message' => 'like02',
    // Default public Like count visibility; the local admin can override it.
    'show_like_count' => false,
    // Default public Like count color in #RRGGBB format.
    'like_count_color' => '#767676',
    'button_symbol_preset' => 'heart',
    'button_unliked_color' => '#666666',
    'button_liked_color' => '#e53955',
    // Public Like count refresh interval in seconds; 0 disables automatic refresh.
    'count_refresh_interval' => 5,
    // Maximum Likes per Like ID and anonymous browser in a rolling 24-hour window.
    'likes_per_24_hours' => 3,
    // Aggregation starts at this many unaggregated Like events.
    'aggregation_start_threshold' => 1000,
    // Maximum number of individual Like events handled in one transaction.
    'aggregation_batch_size' => 500,
    // Reporting timezone used for date and hourly aggregates.
    'timezone' => 'Asia/Tokyo',
];
