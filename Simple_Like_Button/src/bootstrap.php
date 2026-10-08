<?php
declare(strict_types=1);

function pollyanna_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $path = dirname(__DIR__) . '/config/config.php';
    if (!is_file($path)) {
        throw new RuntimeException('Pollyanna configuration is missing.');
    }

    $loaded = require $path;
    if (!is_array($loaded)) {
        throw new RuntimeException('Pollyanna configuration is invalid.');
    }

    $database = $loaded['database'] ?? null;
    $secret = $loaded['app_secret'] ?? null;
    if (
        !is_array($database)
        || !isset($database['dsn'], $database['username'], $database['password'])
        || !is_string($database['dsn'])
        || !is_string($database['username'])
        || !is_string($database['password'])
        || !is_string($secret)
        || preg_match('/\A[a-f0-9]{64}\z/i', $secret) !== 1
    ) {
        throw new RuntimeException('Pollyanna configuration is incomplete.');
    }

    $config = $loaded;
    return $config;
}

function pollyanna_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = pollyanna_config();
    $pdo = new PDO(
        $config['database']['dsn'],
        $config['database']['username'],
        $config['database']['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}

function pollyanna_ensure_allowed_origins_table(PDO $pdo): void
{
    $pdo->exec('CREATE TABLE IF NOT EXISTS pollyanna_allowed_origins ('
        . 'id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
        . 'scheme VARCHAR(5) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
        . 'host VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
        . 'port SMALLINT UNSIGNED NOT NULL,'
        . 'include_subdomains TINYINT(1) NOT NULL DEFAULT 0,'
        . 'created_at DATETIME(6) NOT NULL,'
        . 'PRIMARY KEY (id), UNIQUE KEY uq_polyallowed_origin (scheme, host, port)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
}

function pollyanna_allowed_origins(): array
{
    $pdo = pollyanna_pdo();
    pollyanna_ensure_allowed_origins_table($pdo);
    return $pdo->query('SELECT id, scheme, host, port, include_subdomains, created_at FROM pollyanna_allowed_origins ORDER BY scheme, host, port')->fetchAll();
}

function pollyanna_save_config_values(array $values): void
{
    $path = dirname(__DIR__) . '/config/config.php';
    $config = pollyanna_config();
    foreach ($values as $key => $value) $config[$key] = $value;
    $contents = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(8));
    if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
        @unlink($temporary);
        throw new RuntimeException('Could not save configuration.');
    }
    $backup = $path . '.bak-' . bin2hex(random_bytes(8));
    if ((is_file($path) && !rename($path, $backup)) || !rename($temporary, $path)) {
        if (is_file($backup)) @rename($backup, $path);
        @unlink($temporary);
        throw new RuntimeException('Could not save configuration.');
    }
    @unlink($backup);
}

function pollyanna_count_display_enabled(): bool
{
    $settingsPath = dirname(__DIR__) . '/config/public_count_settings.json';
    if (is_file($settingsPath)) {
        $contents = file_get_contents($settingsPath);
        if ($contents === false) {
            throw new RuntimeException('Pollyanna public count settings could not be read.');
        }
        try {
            $settings = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Pollyanna public count settings are invalid.', 0, $error);
        }
        $enabled = is_array($settings) ? ($settings['enabled'] ?? null) : null;
    } else {
        $enabled = pollyanna_config()['show_like_count'] ?? false;
    }

    if (!is_bool($enabled)) {
        throw new RuntimeException('Pollyanna public count setting is invalid.');
    }
    return $enabled;
}

function pollyanna_save_count_display(bool $enabled): void
{
    $configDirectory = dirname(__DIR__) . '/config';
    $temporaryPath = tempnam($configDirectory, '.public-count-');
    if ($temporaryPath === false) {
        throw new RuntimeException('Pollyanna public count setting could not be saved.');
    }
    try {
        $json = json_encode(['enabled' => $enabled], JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
            throw new RuntimeException('Pollyanna public count setting could not be saved.');
        }
        if (!rename($temporaryPath, $configDirectory . '/public_count_settings.json')) {
            throw new RuntimeException('Pollyanna public count setting could not be saved.');
        }
    } finally {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }
}

function pollyanna_count_refresh_interval(): int
{
    $settingsPath = dirname(__DIR__) . '/config/count_refresh_settings.json';
    if (is_file($settingsPath)) {
        $contents = file_get_contents($settingsPath);
        if ($contents === false) {
            throw new RuntimeException('Pollyanna count refresh setting could not be read.');
        }
        try {
            $settings = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Pollyanna count refresh setting is invalid.', 0, $error);
        }
        $interval = is_array($settings) ? ($settings['seconds'] ?? null) : null;
    } else {
        $interval = pollyanna_config()['count_refresh_interval'] ?? 5;
    }

    if (!is_int($interval) || !in_array($interval, [0, 5, 10, 30, 60], true)) {
        throw new RuntimeException('Pollyanna count refresh interval is invalid.');
    }
    return $interval;
}

function pollyanna_save_count_refresh_interval(int $interval): void
{
    if (!in_array($interval, [0, 5, 10, 30, 60], true)) {
        throw new InvalidArgumentException('Pollyanna count refresh interval is invalid.');
    }

    $configDirectory = dirname(__DIR__) . '/config';
    $temporaryPath = tempnam($configDirectory, '.count-refresh-');
    if ($temporaryPath === false) {
        throw new RuntimeException('Pollyanna count refresh interval could not be saved.');
    }
    try {
        $json = json_encode(['seconds' => $interval], JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
            throw new RuntimeException('Pollyanna count refresh interval could not be saved.');
        }
        if (!rename($temporaryPath, $configDirectory . '/count_refresh_settings.json')) {
            throw new RuntimeException('Pollyanna count refresh interval could not be saved.');
        }
    } finally {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }
}

function pollyanna_count_display_color(): string
{
    $settingsPath = dirname(__DIR__) . '/config/public_count_color_settings.json';
    if (is_file($settingsPath)) {
        $contents = file_get_contents($settingsPath);
        if ($contents === false) {
            throw new RuntimeException('Pollyanna public count color could not be read.');
        }
        try {
            $settings = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Pollyanna public count color is invalid.', 0, $error);
        }
        $color = is_array($settings) ? ($settings['color'] ?? null) : null;
    } else {
        $color = pollyanna_config()['like_count_color'] ?? '#767676';
    }

    if (!is_string($color) || preg_match('/\A#[0-9a-fA-F]{6}\z/', $color) !== 1) {
        throw new RuntimeException('Pollyanna public count color is invalid.');
    }
    return strtolower($color);
}

function pollyanna_save_count_display_color(string $color): void
{
    if (preg_match('/\A#[0-9a-fA-F]{6}\z/', $color) !== 1) {
        throw new InvalidArgumentException('Pollyanna public count color is invalid.');
    }

    $configDirectory = dirname(__DIR__) . '/config';
    $temporaryPath = tempnam($configDirectory, '.public-count-color-');
    if ($temporaryPath === false) {
        throw new RuntimeException('Pollyanna public count color could not be saved.');
    }
    try {
        $json = json_encode(['color' => strtolower($color)], JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
            throw new RuntimeException('Pollyanna public count color could not be saved.');
        }
        if (!rename($temporaryPath, $configDirectory . '/public_count_color_settings.json')) {
            throw new RuntimeException('Pollyanna public count color could not be saved.');
        }
    } finally {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }
}

function pollyanna_button_symbol_presets(): array
{
    return [
        'heart' => ['unliked' => '♡', 'liked' => '♥'],
        'diamond' => ['unliked' => '♢', 'liked' => '♦'],
        'spade' => ['unliked' => '♤', 'liked' => '♠'],
        'club' => ['unliked' => '♧', 'liked' => '♣'],
        'star' => ['unliked' => '☆', 'liked' => '★'],
        'music' => ['unliked' => '♭', 'liked' => '♪'],
    ];
}

function pollyanna_button_display_settings(): array
{
    $defaults = ['preset' => 'heart', 'unliked_color' => '#666666', 'liked_color' => '#e53955'];
    $settingsPath = dirname(__DIR__) . '/config/button_display_settings.json';
    $settings = null;
    if (is_file($settingsPath)) {
        $contents = file_get_contents($settingsPath);
        if ($contents !== false) {
            try {
                $decoded = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) $settings = $decoded;
            } catch (JsonException) {
                $settings = null;
            }
        }
    } else {
        $config = pollyanna_config();
        $settings = [
            'preset' => $config['button_symbol_preset'] ?? $defaults['preset'],
            'unliked_color' => $config['button_unliked_color'] ?? $defaults['unliked_color'],
            'liked_color' => $config['button_liked_color'] ?? $defaults['liked_color'],
        ];
    }

    $preset = is_array($settings) && is_string($settings['preset'] ?? null) ? $settings['preset'] : $defaults['preset'];
    $unlikedColor = is_array($settings) && is_string($settings['unliked_color'] ?? null) ? strtolower($settings['unliked_color']) : $defaults['unliked_color'];
    $likedColor = is_array($settings) && is_string($settings['liked_color'] ?? null) ? strtolower($settings['liked_color']) : $defaults['liked_color'];
    if (!array_key_exists($preset, pollyanna_button_symbol_presets())) $preset = $defaults['preset'];
    if (!in_array($unlikedColor, ['#666666', '#cccccc'], true)) $unlikedColor = $defaults['unliked_color'];
    if (preg_match('/\A#[0-9a-f]{6}\z/', $likedColor) !== 1) $likedColor = $defaults['liked_color'];
    return ['preset' => $preset, 'unliked_color' => $unlikedColor, 'liked_color' => $likedColor];
}

function pollyanna_save_button_display_settings(string $preset, string $unlikedColor, string $likedColor): void
{
    $unlikedColor = strtolower($unlikedColor);
    $likedColor = strtolower($likedColor);
    if (
        !array_key_exists($preset, pollyanna_button_symbol_presets())
        || !in_array($unlikedColor, ['#666666', '#cccccc'], true)
        || preg_match('/\A#[0-9a-f]{6}\z/', $likedColor) !== 1
    ) {
        throw new InvalidArgumentException('Pollyanna button display settings are invalid.');
    }
    $configDirectory = dirname(__DIR__) . '/config';
    $temporaryPath = tempnam($configDirectory, '.button-display-');
    if ($temporaryPath === false) throw new RuntimeException('Pollyanna button display settings could not be saved.');
    try {
        $json = json_encode(
            ['preset' => $preset, 'unliked_color' => $unlikedColor, 'liked_color' => $likedColor],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
            throw new RuntimeException('Pollyanna button display settings could not be saved.');
        }
        if (!rename($temporaryPath, $configDirectory . '/button_display_settings.json')) {
            throw new RuntimeException('Pollyanna button display settings could not be saved.');
        }
    } finally {
        if (is_file($temporaryPath)) unlink($temporaryPath);
    }
}

function pollyanna_save_timezone(string $timezone): void
{
    try {
        new DateTimeZone($timezone);
    } catch (Throwable $error) {
        throw new InvalidArgumentException('Pollyanna timezone is invalid.', 0, $error);
    }

    $configDirectory = dirname(__DIR__) . '/config';
    $temporaryPath = tempnam($configDirectory, '.timezone-');
    if ($temporaryPath === false) {
        throw new RuntimeException('Pollyanna timezone could not be saved.');
    }
    try {
        $json = json_encode(['timezone' => $timezone], JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
            throw new RuntimeException('Pollyanna timezone could not be saved.');
        }
        if (!rename($temporaryPath, $configDirectory . '/timezone_settings.json')) {
            throw new RuntimeException('Pollyanna timezone could not be saved.');
        }
    } finally {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }
}

function pollyanna_timezone_change_state(): ?array
{
    $path = dirname(__DIR__) . '/config/timezone_change.json';
    if (!is_file($path)) {
        return null;
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Pollyanna timezone change state could not be read.');
    }
    try {
        $state = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('Pollyanna timezone change state is invalid.', 0, $error);
    }
    if (
        !is_array($state)
        || !is_string($state['old_timezone'] ?? null)
        || !is_string($state['new_timezone'] ?? null)
        || !in_array($state['previous_system_state'] ?? null, ['enabled', 'maintenance'], true)
    ) {
        throw new RuntimeException('Pollyanna timezone change state is invalid.');
    }
    return $state;
}

function pollyanna_save_timezone_change_state(array $state): void
{
    $configDirectory = dirname(__DIR__) . '/config';
    $temporaryPath = tempnam($configDirectory, '.timezone-change-');
    if ($temporaryPath === false) {
        throw new RuntimeException('Pollyanna timezone change state could not be saved.');
    }
    try {
        $json = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
            throw new RuntimeException('Pollyanna timezone change state could not be saved.');
        }
        if (!rename($temporaryPath, $configDirectory . '/timezone_change.json')) {
            throw new RuntimeException('Pollyanna timezone change state could not be saved.');
        }
    } finally {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }
}

function pollyanna_clear_timezone_change_state(): void
{
    $path = dirname(__DIR__) . '/config/timezone_change.json';
    if (is_file($path) && !unlink($path)) {
        throw new RuntimeException('Pollyanna timezone change state could not be cleared.');
    }
}

function pollyanna_acquire_like_request_lock(PDO $pdo, int $waitSeconds = 10): bool
{
    $databaseName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $lockName = 'pollyanna_like_' . substr(hash('sha256', $databaseName), 0, 48);
    $statement = $pdo->prepare('SELECT GET_LOCK(:lock_name, ' . max(0, min(60, $waitSeconds)) . ')');
    $statement->execute([':lock_name' => $lockName]);
    return (int) $statement->fetchColumn() === 1;
}

function pollyanna_release_like_request_lock(PDO $pdo): void
{
    $databaseName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $lockName = 'pollyanna_like_' . substr(hash('sha256', $databaseName), 0, 48);
    $statement = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
    $statement->execute([':lock_name' => $lockName]);
}

function pollyanna_save_aggregation_threshold(int $threshold): void
{
    if (!in_array($threshold, [1000, 2000, 4000, 6000, 8000, 10000], true)) {
        throw new InvalidArgumentException('Pollyanna aggregation threshold is invalid.');
    }

    $configDirectory = dirname(__DIR__) . '/config';
    $temporaryPath = tempnam($configDirectory, '.aggregation-threshold-');
    if ($temporaryPath === false) {
        throw new RuntimeException('Pollyanna aggregation threshold could not be saved.');
    }
    try {
        $json = json_encode(['aggregation_start_threshold' => $threshold], JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
            throw new RuntimeException('Pollyanna aggregation threshold could not be saved.');
        }
        if (!rename($temporaryPath, $configDirectory . '/aggregation_threshold_settings.json')) {
            throw new RuntimeException('Pollyanna aggregation threshold could not be saved.');
        }
    } finally {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }
}

function pollyanna_system_state(): string
{
    $statePath = dirname(__DIR__) . '/config/system_state.json';
    if (is_file($statePath)) {
        $contents = file_get_contents($statePath);
        if ($contents === false) {
            throw new RuntimeException('Pollyanna system state could not be read.');
        }
        try {
            $savedState = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Pollyanna system state is invalid.', 0, $error);
        }
        $state = is_array($savedState) ? ($savedState['state'] ?? null) : null;
    } else {
        $state = pollyanna_config()['system_state'] ?? 'enabled';
    }

    if (!is_string($state) || !in_array($state, ['enabled', 'maintenance'], true)) {
        throw new RuntimeException('Pollyanna system state is invalid.');
    }

    return $state;
}

function pollyanna_save_system_state(string $state): void
{
    if (!in_array($state, ['enabled', 'maintenance'], true)) {
        throw new InvalidArgumentException('Pollyanna system state is invalid.');
    }

    $configDirectory = dirname(__DIR__) . '/config';
    $temporaryPath = tempnam($configDirectory, '.system-state-');
    if ($temporaryPath === false) {
        throw new RuntimeException('Pollyanna system state could not be saved.');
    }

    try {
        $json = json_encode(['state' => $state], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
            throw new RuntimeException('Pollyanna system state could not be saved.');
        }
        if (!rename($temporaryPath, $configDirectory . '/system_state.json')) {
            throw new RuntimeException('Pollyanna system state could not be saved.');
        }
    } finally {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }
}

function pollyanna_widget_catalog(): array
{
    $catalog = [];
    foreach (glob(dirname(__DIR__) . '/lang/widget.*.json') ?: [] as $path) {
        $code = substr(basename($path, '.json'), strlen('widget.'));
        if (!preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $code)) {
            continue;
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            continue;
        }
        try {
            $locale = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            continue;
        }
        $validLocale =
            is_array($locale)
            && isset($locale['language_name'], $locale['success_messages'])
            && is_string($locale['language_name'])
            && is_array($locale['success_messages'])
            && $locale['success_messages'] !== [];
        foreach (['button_label', 'count_label', 'identifier_missing', 'identifier_invalid', 'identifier_generation_failed', 'state_unavailable', 'maintenance', 'send_error', 'rate_limit'] as $field) {
            $validLocale = $validLocale && isset($locale[$field]) && is_string($locale[$field]);
        }
        foreach ($locale['success_messages'] ?? [] as $key => $message) {
            $validLocale = $validLocale && is_string($key) && is_string($message);
        }
        if ($validLocale) {
            $catalog[$code] = $locale;
        }
    }

    if (!isset($catalog['ja'])) {
        throw new RuntimeException('Japanese widget language file is missing or invalid.');
    }

    return $catalog;
}

function pollyanna_admin_catalog(): array
{
    $catalog = [];
    foreach (glob(dirname(__DIR__) . '/lang/admin.*.json') ?: [] as $path) {
        $code = substr(basename($path, '.json'), strlen('admin.'));
        if (!preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $code)) {
            continue;
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            continue;
        }
        try {
            $locale = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            continue;
        }
        if (is_array($locale) && is_string($locale['language_name'] ?? null)) {
            $catalog[$code] = $locale;
        }
    }

    if (!isset($catalog['ja'])) {
        throw new RuntimeException('Japanese admin language file is missing or invalid.');
    }
    return $catalog;
}

function pollyanna_admin_language(): string
{
    $catalog = pollyanna_admin_catalog();
    $settingsPath = dirname(__DIR__) . '/config/admin_settings.json';
    if (is_file($settingsPath)) {
        $contents = file_get_contents($settingsPath);
        if ($contents === false) {
            throw new RuntimeException('Pollyanna admin settings could not be read.');
        }
        try {
            $settings = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Pollyanna admin settings are invalid.', 0, $error);
        }
        $language = is_array($settings) ? ($settings['language'] ?? null) : null;
    } else {
        $language = pollyanna_config()['admin_language'] ?? 'ja';
    }

    if (!is_string($language) || !isset($catalog[$language])) {
        throw new RuntimeException('Pollyanna admin language is invalid.');
    }
    return $language;
}

function pollyanna_save_admin_language(string $language): void
{
    $catalog = pollyanna_admin_catalog();
    if (!isset($catalog[$language])) {
        throw new InvalidArgumentException('Pollyanna admin language is invalid.');
    }

    $configDirectory = dirname(__DIR__) . '/config';
    $temporaryPath = tempnam($configDirectory, '.admin-settings-');
    if ($temporaryPath === false) {
        throw new RuntimeException('Pollyanna admin language could not be saved.');
    }
    try {
        $json = json_encode(
            ['language' => $language, 'theme' => pollyanna_admin_theme()],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
            throw new RuntimeException('Pollyanna admin language could not be saved.');
        }
        if (!rename($temporaryPath, $configDirectory . '/admin_settings.json')) {
            throw new RuntimeException('Pollyanna admin language could not be saved.');
        }
    } finally {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }
}

function pollyanna_admin_themes(): array
{
    return ['system', 'light', 'dark', 'green', 'blue', 'orange', 'rose', 'purple', 'monochrome'];
}

function pollyanna_admin_theme(): string
{
    $settingsPath = dirname(__DIR__) . '/config/admin_settings.json';
    if (is_file($settingsPath)) {
        $contents = file_get_contents($settingsPath);
        if ($contents === false) {
            throw new RuntimeException('Pollyanna admin settings could not be read.');
        }
        try {
            $settings = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Pollyanna admin settings are invalid.', 0, $error);
        }
        $theme = is_array($settings) ? ($settings['theme'] ?? 'green') : null;
    } else {
        $theme = pollyanna_config()['admin_theme'] ?? 'green';
    }

    if (!is_string($theme) || !in_array($theme, pollyanna_admin_themes(), true)) {
        throw new RuntimeException('Pollyanna admin theme is invalid.');
    }
    return $theme;
}

function pollyanna_save_admin_theme(string $theme): void
{
    if (!in_array($theme, pollyanna_admin_themes(), true)) {
        throw new InvalidArgumentException('Pollyanna admin theme is invalid.');
    }

    $configDirectory = dirname(__DIR__) . '/config';
    $temporaryPath = tempnam($configDirectory, '.admin-settings-');
    if ($temporaryPath === false) {
        throw new RuntimeException('Pollyanna admin theme could not be saved.');
    }
    try {
        $json = json_encode(
            ['language' => pollyanna_admin_language(), 'theme' => $theme],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
            throw new RuntimeException('Pollyanna admin theme could not be saved.');
        }
        if (!rename($temporaryPath, $configDirectory . '/admin_settings.json')) {
            throw new RuntimeException('Pollyanna admin theme could not be saved.');
        }
    } finally {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }
}

function pollyanna_widget_settings(): array
{
    $catalog = pollyanna_widget_catalog();
    $legacyMessageKeys = [
        'like' => 'like01',
        'good' => 'like02',
        'like_en' => 'like03',
        'thanks_en' => 'like04',
        'thanks' => 'like05',
        'yay' => 'like06',
    ];
    $settingsPath = dirname(__DIR__) . '/config/widget_settings.json';
    if (is_file($settingsPath)) {
        $contents = file_get_contents($settingsPath);
        if ($contents === false) {
            throw new RuntimeException('Pollyanna widget settings could not be read.');
        }
        try {
            $settings = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Pollyanna widget settings are invalid.', 0, $error);
        }
        $language = is_array($settings) ? ($settings['language'] ?? null) : null;
        $messageKey = is_array($settings) ? ($settings['success_message'] ?? null) : null;
    } else {
        $config = pollyanna_config();
        $language = $config['widget_language'] ?? 'ja';
        $messageKey = $config['click_success_message'] ?? 'like02';

        // Read selections saved by the previous local test page.
        $legacyPath = dirname(__DIR__) . '/config/click_message.json';
        if (is_file($legacyPath)) {
            $legacyContents = file_get_contents($legacyPath);
            $legacy = $legacyContents === false ? null : json_decode($legacyContents, true);
            $legacyMessage = is_array($legacy) ? ($legacy['message'] ?? null) : null;
            if (is_string($legacyMessage)) {
                foreach ($catalog as $code => $locale) {
                    $key = array_search($legacyMessage, $locale['success_messages'], true);
                    if (is_string($key)) {
                        $language = $code;
                        $messageKey = $key;
                        break;
                    }
                }
            }
        }
        if (
            is_string($language)
            && isset($catalog[$language]['success_messages'])
            && is_string($messageKey)
            && !isset($catalog[$language]['success_messages'][$messageKey])
        ) {
            foreach ($catalog as $code => $locale) {
                $key = array_search($messageKey, $locale['success_messages'], true);
                if (is_string($key)) {
                    $language = $code;
                    $messageKey = $key;
                    break;
                }
            }
        }
    }

    if (is_string($messageKey) && isset($legacyMessageKeys[$messageKey])) {
        $messageKey = $legacyMessageKeys[$messageKey];
    }

    if (
        !is_string($language)
        || !isset($catalog[$language])
        || !is_string($messageKey)
        || !isset($catalog[$language]['success_messages'][$messageKey])
        || !is_string($catalog[$language]['success_messages'][$messageKey])
    ) {
        throw new RuntimeException('Pollyanna widget settings are invalid.');
    }

    return ['language' => $language, 'success_message' => $messageKey];
}

function pollyanna_widget_success_message(): string
{
    $catalog = pollyanna_widget_catalog();
    $settings = pollyanna_widget_settings();
    return $catalog[$settings['language']]['success_messages'][$settings['success_message']];
}

function pollyanna_widget_runtime_text(): array
{
    $catalog = pollyanna_widget_catalog();
    $settings = pollyanna_widget_settings();
    $locale = $catalog[$settings['language']];

    $buttonDisplay = pollyanna_button_display_settings();
    $symbols = pollyanna_button_symbol_presets()[$buttonDisplay['preset']];
    return [
        'button_label' => $locale['button_label'],
        'count_label' => $locale['count_label'],
        'identifier_missing' => $locale['identifier_missing'],
        'identifier_invalid' => $locale['identifier_invalid'],
        'identifier_generation_failed' => $locale['identifier_generation_failed'],
        'state_unavailable' => $locale['state_unavailable'],
        'maintenance' => $locale['maintenance'],
        'send_error' => $locale['send_error'],
        'rate_limit' => $locale['rate_limit'],
        'count_color' => pollyanna_count_display_color(),
        'symbol_unliked' => $symbols['unliked'],
        'symbol_liked' => $symbols['liked'],
        'symbol_unliked_color' => $buttonDisplay['unliked_color'],
        'symbol_liked_color' => $buttonDisplay['liked_color'],
    ];
}

function pollyanna_save_widget_settings(string $language, string $messageKey): void
{
    $catalog = pollyanna_widget_catalog();
    if (!isset($catalog[$language]['success_messages'][$messageKey])) {
        throw new InvalidArgumentException('Pollyanna widget settings are invalid.');
    }

    $configDirectory = dirname(__DIR__) . '/config';
    $temporaryPath = tempnam($configDirectory, '.widget-settings-');
    if ($temporaryPath === false) {
        throw new RuntimeException('Pollyanna widget settings could not be saved.');
    }
    try {
        $json = json_encode(
            ['language' => $language, 'success_message' => $messageKey],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
        if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
            throw new RuntimeException('Pollyanna widget settings could not be saved.');
        }
        if (!rename($temporaryPath, $configDirectory . '/widget_settings.json')) {
            throw new RuntimeException('Pollyanna widget settings could not be saved.');
        }
    } finally {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }
}

function pollyanna_rate_limit(): int
{
    $settingsPath = dirname(__DIR__) . '/config/rate_limit_settings.json';
    if (is_file($settingsPath)) {
        $contents = file_get_contents($settingsPath);
        if ($contents === false) {
            throw new RuntimeException('Pollyanna rate-limit settings could not be read.');
        }
        try {
            $settings = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Pollyanna rate-limit settings are invalid.', 0, $error);
        }
        $limit = is_array($settings) ? ($settings['likes_per_24_hours'] ?? null) : null;
    } else {
        $limit = pollyanna_config()['likes_per_24_hours'] ?? 3;
    }

    if (!is_int($limit) || !in_array($limit, [1, 2, 3, 4, 5], true)) {
        throw new RuntimeException('Pollyanna rate-limit setting is invalid.');
    }
    return $limit;
}

function pollyanna_save_rate_limit(int $limit): void
{
    if (!in_array($limit, [1, 2, 3, 4, 5], true)) {
        throw new InvalidArgumentException('Pollyanna rate-limit setting is invalid.');
    }

    $configDirectory = dirname(__DIR__) . '/config';
    $temporaryPath = tempnam($configDirectory, '.rate-limit-');
    if ($temporaryPath === false) {
        throw new RuntimeException('Pollyanna rate-limit setting could not be saved.');
    }
    try {
        $json = json_encode(['likes_per_24_hours' => $limit], JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
            throw new RuntimeException('Pollyanna rate-limit setting could not be saved.');
        }
        if (!rename($temporaryPath, $configDirectory . '/rate_limit_settings.json')) {
            throw new RuntimeException('Pollyanna rate-limit setting could not be saved.');
        }
    } finally {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }
}

/**
 * Applies a group of file-backed settings and restores every file if one write fails.
 *
 * @param list<string> $relativePaths Paths below config/ that the callback may change.
 */
function pollyanna_save_settings_group(array $relativePaths, callable $save): void
{
    $configDirectory = dirname(__DIR__) . '/config';
    $snapshots = [];
    foreach ($relativePaths as $relativePath) {
        if (!is_string($relativePath) || basename($relativePath) !== $relativePath) {
            throw new InvalidArgumentException('Pollyanna settings path is invalid.');
        }
        $path = $configDirectory . '/' . $relativePath;
        if (is_file($path)) {
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException('Pollyanna settings could not be backed up.');
            }
            $snapshots[$path] = $contents;
        } else {
            $snapshots[$path] = null;
        }
    }

    try {
        $save();
    } catch (Throwable $error) {
        foreach ($snapshots as $path => $contents) {
            if ($contents === null) {
                if (is_file($path)) {
                    unlink($path);
                }
                continue;
            }
            file_put_contents($path, $contents, LOCK_EX);
        }
        throw $error;
    }
}

function pollyanna_aggregation_settings(): array
{
    $config = pollyanna_config();
    $threshold = $config['aggregation_start_threshold'] ?? 1000;
    $thresholdPath = dirname(__DIR__) . '/config/aggregation_threshold_settings.json';
    if (is_file($thresholdPath)) {
        $contents = file_get_contents($thresholdPath);
        if ($contents === false) {
            throw new RuntimeException('Pollyanna aggregation threshold could not be read.');
        }
        try {
            $savedSettings = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Pollyanna aggregation threshold is invalid.', 0, $error);
        }
        $threshold = is_array($savedSettings) ? ($savedSettings['aggregation_start_threshold'] ?? null) : null;
    }
    $batchSize = $config['aggregation_batch_size'] ?? 500;
    $timezone = $config['timezone'] ?? 'Asia/Tokyo';
    $timezonePath = dirname(__DIR__) . '/config/timezone_settings.json';
    if (is_file($timezonePath)) {
        $contents = file_get_contents($timezonePath);
        if ($contents === false) {
            throw new RuntimeException('Pollyanna timezone could not be read.');
        }
        try {
            $savedTimezone = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('Pollyanna timezone is invalid.', 0, $error);
        }
        $timezone = is_array($savedTimezone) ? ($savedTimezone['timezone'] ?? null) : null;
    }

    if (!is_int($threshold) || $threshold < 1 || $threshold > 10000) {
        throw new RuntimeException('Pollyanna aggregation threshold is invalid.');
    }
    if (!is_int($batchSize) || $batchSize < 1 || $batchSize > 10000) {
        throw new RuntimeException('Pollyanna aggregation batch size is invalid.');
    }
    if (!is_string($timezone)) {
        throw new RuntimeException('Pollyanna timezone is invalid.');
    }

    try {
        new DateTimeZone($timezone);
    } catch (Throwable $error) {
        throw new RuntimeException('Pollyanna timezone is invalid.', 0, $error);
    }

    return [
        'aggregation_start_threshold' => $threshold,
        'aggregation_batch_size' => $batchSize,
        'timezone' => $timezone,
    ];
}

function pollyanna_json_response(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function pollyanna_identifier_is_valid(string $identifier): bool
{
    $characterCount = preg_match_all('/./us', $identifier);
    if ($identifier === '' || $characterCount === false || $characterCount > 100) {
        return false;
    }

    // Unicode letters, marks, numbers, punctuation and symbols are accepted.
    // ASCII punctuation and symbols are rejected except for "-" and "_".
    return preg_match('/\A[\p{L}\p{M}\p{N}\p{P}\p{S}]+\z/uD', $identifier) === 1
        && preg_match('/[\x21-\x2C\x2E-\x2F\x3A-\x40\x5B-\x5E\x60\x7B-\x7E]/', $identifier) !== 1;
}

function pollyanna_normalize_installation_url(?string $url): ?string
{
    if ($url === null || trim($url) === '') {
        return null;
    }
    $url = trim($url);
    if (strlen($url) > 2048) {
        throw new InvalidArgumentException('Pollyanna installation URL is invalid.');
    }

    $url = substr($url, 0, strcspn($url, '?#'));
    if ($url === '' || preg_match('/[\x00-\x20\x7F]/u', $url) === 1) {
        throw new InvalidArgumentException('Pollyanna installation URL is invalid.');
    }
    try {
        $parts = parse_url($url);
    } catch (ValueError $error) {
        throw new InvalidArgumentException('Pollyanna installation URL is invalid.', 0, $error);
    }
    if (
        !is_array($parts)
        || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
        || !is_string($parts['host'] ?? null)
        || $parts['host'] === ''
        || isset($parts['user'])
        || isset($parts['pass'])
    ) {
        throw new InvalidArgumentException('Pollyanna installation URL is invalid.');
    }
    return $url;
}

function pollyanna_ensure_identifier_urls_table(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS pollyanna_identifier_urls ('
        . 'identifier VARCHAR(100) NOT NULL,'
        . 'url_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,'
        . 'canonical_url TEXT NOT NULL,'
        . 'first_seen_at DATETIME(6) NOT NULL,'
        . 'last_seen_at DATETIME(6) NOT NULL,'
        . 'PRIMARY KEY (identifier, url_hash),'
        . 'KEY idx_polyidentifier_urls_last_seen (last_seen_at)'
        . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin'
    );
}

function pollyanna_register_identifier_url(PDO $pdo, string $identifier, ?string $url): void
{
    $url = pollyanna_normalize_installation_url($url);
    if ($url === null) {
        return;
    }
    pollyanna_ensure_identifier_urls_table($pdo);
    $statement = $pdo->prepare(
        'INSERT INTO pollyanna_identifier_urls (identifier, url_hash, canonical_url, first_seen_at, last_seen_at) '
        . 'VALUES (:identifier, :url_hash, :canonical_url, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6)) '
        . 'ON DUPLICATE KEY UPDATE canonical_url = VALUES(canonical_url), last_seen_at = UTC_TIMESTAMP(6)'
    );
    $statement->execute([
        ':identifier' => $identifier,
        ':url_hash' => hash('sha256', $url),
        ':canonical_url' => $url,
    ]);
}

function pollyanna_admin_password_hash(): ?string
{
    $path = dirname(__DIR__) . '/config/admin_credentials.json';
    if (!is_file($path)) {
        return null;
    }
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Pollyanna admin credentials could not be read.');
    }
    try {
        $credentials = json_decode($contents, true, 4, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException('Pollyanna admin credentials are invalid.', 0, $error);
    }
    $hash = is_array($credentials) ? ($credentials['password_hash'] ?? null) : null;
    if (!is_string($hash) || $hash === '') {
        throw new RuntimeException('Pollyanna admin credentials are invalid.');
    }
    return $hash;
}

function pollyanna_save_admin_password(string $password): void
{
    if (strlen($password) < 12 || strlen($password) > 1024) {
        throw new InvalidArgumentException('Pollyanna admin password length is invalid.');
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($hash)) {
        throw new RuntimeException('Pollyanna admin password could not be hashed.');
    }
    $configDirectory = dirname(__DIR__) . '/config';
    $temporaryPath = tempnam($configDirectory, '.admin-credentials-');
    if ($temporaryPath === false) {
        throw new RuntimeException('Pollyanna admin credentials could not be saved.');
    }
    try {
        $json = json_encode(['password_hash' => $hash], JSON_THROW_ON_ERROR) . PHP_EOL;
        if (file_put_contents($temporaryPath, $json, LOCK_EX) === false) {
            throw new RuntimeException('Pollyanna admin credentials could not be saved.');
        }
        if (!rename($temporaryPath, $configDirectory . '/admin_credentials.json')) {
            throw new RuntimeException('Pollyanna admin credentials could not be saved.');
        }
    } finally {
        if (is_file($temporaryPath)) unlink($temporaryPath);
    }
}
