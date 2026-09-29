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
        foreach (['button_label', 'count_label', 'identifier_missing', 'state_unavailable', 'maintenance', 'send_error', 'rate_limit'] as $field) {
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

    return [
        'button_label' => $locale['button_label'],
        'count_label' => $locale['count_label'],
        'identifier_missing' => $locale['identifier_missing'],
        'state_unavailable' => $locale['state_unavailable'],
        'maintenance' => $locale['maintenance'],
        'send_error' => $locale['send_error'],
        'rate_limit' => $locale['rate_limit'],
        'count_color' => pollyanna_count_display_color(),
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
