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
    'site_base_domain' => 'example.com',
    'site_base_domain_include_subdomains' => true,
    // Default API state; maintenance mode rejects public Like and state requests.
    'system_state' => 'enabled',
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
