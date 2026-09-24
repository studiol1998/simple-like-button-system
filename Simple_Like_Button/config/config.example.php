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
    // Publicly display Like counts on the embedded button.
    'show_like_count' => false,
];
