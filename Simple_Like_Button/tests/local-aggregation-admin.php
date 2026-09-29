<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/LikeService.php';
require_once dirname(__DIR__) . '/src/AggregationService.php';
require_once dirname(__DIR__) . '/src/TimezoneChangeService.php';

$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remoteAddress, ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('この確認ページはローカル接続からのみ利用できます。');
}

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params([
    'httponly' => true,
    'secure' => $isHttps,
    'samesite' => 'Strict',
]);
session_start();

$languageDirectory = dirname(__DIR__) . '/lang';
$languageFiles = glob($languageDirectory . '/admin.*.json') ?: [];
$availableLanguages = [];
foreach ($languageFiles as $languageFile) {
    $languageCode = basename($languageFile, '.json');
    $languageCode = substr($languageCode, strlen('admin.'));
    if (!preg_match('/^[a-z]{2}(?:-[A-Z]{2})?$/', $languageCode)) {
        continue;
    }

    $languageContents = file_get_contents($languageFile);
    if ($languageContents === false) {
        continue;
    }

    try {
        $translations = json_decode($languageContents, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        continue;
    }

    if (is_array($translations) && isset($translations['language_name']) && is_string($translations['language_name'])) {
        $availableLanguages[$languageCode] = $translations;
    }
}

if (!isset($availableLanguages['ja'])) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Japanese language file is missing or invalid.');
}

$requestedLanguage = $_GET['language'] ?? $_SESSION['pollyanna_admin_language'] ?? 'ja';
$language = is_string($requestedLanguage) && isset($availableLanguages[$requestedLanguage])
    ? $requestedLanguage
    : 'ja';
$_SESSION['pollyanna_admin_language'] = $language;
$ui = $availableLanguages[$language];

if (!isset($_SESSION['pollyanna_csrf_token']) || !is_string($_SESSION['pollyanna_csrf_token'])) {
    $_SESSION['pollyanna_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['pollyanna_csrf_token'];

function pollyanna_local_hourly_rows(PDO $pdo, string $timezoneName): array
{
    $timezone = new DateTimeZone($timezoneName);
    $currentMonth = new DateTimeImmutable('first day of this month', $timezone);
    $oldestMonth = $currentMonth->modify('-23 months')->format('Y-m-01');
    $statement = $pdo->prepare(
        'SELECT month_start, hour_of_day, like_count FROM pollyanna_hourly_aggregates '
        . 'WHERE month_start >= :oldest_month ORDER BY month_start DESC, hour_of_day ASC'
    );
    $statement->execute([':oldest_month' => $oldestMonth]);

    return array_map(
        static fn (array $row): array => [
            'month_start' => (string) $row['month_start'],
            'hour_of_day' => (int) $row['hour_of_day'],
            'like_count' => (int) $row['like_count'],
        ],
        $statement->fetchAll()
    );
}

function pollyanna_local_period_rows(PDO $pdo): array
{
    $statement = $pdo->query(
        "SELECT period_type, period_start, identifier, like_count "
        . "FROM pollyanna_period_aggregates "
        . "ORDER BY period_start DESC, FIELD(period_type, 'day', 'month', 'year'), identifier ASC "
        . 'LIMIT 100'
    );

    return array_map(
        static fn (array $row): array => [
            'period_type' => (string) $row['period_type'],
            'period_start' => (string) $row['period_start'],
            'identifier' => (string) $row['identifier'],
            'like_count' => (int) $row['like_count'],
        ],
        $statement->fetchAll()
    );
}

function pollyanna_local_lifetime_rows(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT identifier, total_likes FROM pollyanna_lifetime_totals '
        . 'ORDER BY total_likes DESC, identifier ASC LIMIT 100'
    );

    return array_map(
        static fn (array $row): array => [
            'identifier' => (string) $row['identifier'],
            'total_likes' => (int) $row['total_likes'],
        ],
        $statement->fetchAll()
    );
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $postedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($postedToken) || !hash_equals($csrfToken, $postedToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => $ui['csrf_error']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $postedAction = $_POST['action'] ?? '';
    if (
        pollyanna_timezone_change_state() !== null
        && !in_array($postedAction, ['timezone_change_batch', 'finish_timezone_change'], true)
    ) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => $ui['timezone_change_interrupted']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (($_POST['action'] ?? '') === 'save_system_state') {
        $requestedState = $_POST['system_state'] ?? '';
        if (!is_string($requestedState) || !in_array($requestedState, ['enabled', 'maintenance'], true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $ui['state_save_error']], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            pollyanna_save_system_state($requestedState);
            echo json_encode(['success' => true, 'system_state' => $requestedState], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $error) {
            error_log('Pollyanna local system state update failed: ' . $error->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $ui['state_save_error']], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if (($_POST['action'] ?? '') === 'save_click_message') {
        $requestedLanguage = $_POST['widget_language'] ?? '';
        $requestedMessage = $_POST['click_message'] ?? '';
        $widgetCatalog = pollyanna_widget_catalog();
        if (
            !is_string($requestedLanguage)
            || !isset($widgetCatalog[$requestedLanguage])
            || !is_string($requestedMessage)
            || !isset($widgetCatalog[$requestedLanguage]['success_messages'][$requestedMessage])
        ) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $ui['message_save_error']], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            pollyanna_save_widget_settings($requestedLanguage, $requestedMessage);
            echo json_encode([
                'success' => true,
                'widget_language' => $requestedLanguage,
                'click_message_key' => $requestedMessage,
                'click_message' => $widgetCatalog[$requestedLanguage]['success_messages'][$requestedMessage],
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $error) {
            error_log('Pollyanna local click message update failed: ' . $error->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $ui['message_save_error']], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if (($_POST['action'] ?? '') === 'save_rate_limit') {
        $requestedLimit = filter_var($_POST['likes_per_24_hours'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($requestedLimit) || !in_array($requestedLimit, [1, 2, 3, 4, 5], true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $ui['rate_limit_save_error']], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            pollyanna_save_rate_limit($requestedLimit);
            echo json_encode(['success' => true, 'likes_per_24_hours' => $requestedLimit], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $error) {
            error_log('Pollyanna local rate-limit update failed: ' . $error->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $ui['rate_limit_save_error']], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if (($_POST['action'] ?? '') === 'save_aggregation_threshold') {
        $requestedThreshold = filter_var($_POST['aggregation_start_threshold'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($requestedThreshold) || !in_array($requestedThreshold, [1000, 2000, 4000, 6000, 8000, 10000], true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $ui['threshold_save_error']], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            pollyanna_save_aggregation_threshold($requestedThreshold);
            echo json_encode(['success' => true, 'aggregation_start_threshold' => $requestedThreshold], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $error) {
            error_log('Pollyanna local aggregation threshold update failed: ' . $error->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $ui['threshold_save_error']], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if (($_POST['action'] ?? '') === 'begin_timezone_change') {
        $requestedTimezone = $_POST['timezone'] ?? '';
        if (!is_string($requestedTimezone) || !in_array($requestedTimezone, DateTimeZone::listIdentifiers(), true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $ui['timezone_change_error']], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            $result = (new TimezoneChangeService(pollyanna_pdo()))->begin($requestedTimezone);
            echo json_encode(['success' => true] + $result, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $error) {
            error_log('Pollyanna local timezone change could not start: ' . $error->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $ui['timezone_change_error']], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if (($_POST['action'] ?? '') === 'timezone_change_batch') {
        try {
            $result = (new TimezoneChangeService(pollyanna_pdo()))->aggregateNextBatch();
            echo json_encode(['success' => true] + $result, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $error) {
            error_log('Pollyanna local timezone change aggregation failed: ' . $error->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $ui['timezone_change_error']], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if (($_POST['action'] ?? '') === 'finish_timezone_change') {
        try {
            $result = (new TimezoneChangeService(pollyanna_pdo()))->archiveAndReset();
            echo json_encode(['success' => true] + $result, JSON_UNESCAPED_UNICODE);
        } catch (Throwable $error) {
            error_log('Pollyanna local timezone change finalization failed: ' . $error->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $ui['timezone_change_error']], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if (pollyanna_timezone_change_state() !== null) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => $ui['timezone_change_interrupted']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (($_POST['action'] ?? '') === 'save_public_count') {
        $requestedVisibility = $_POST['show_like_count'] ?? '';
        if (!is_string($requestedVisibility) || !in_array($requestedVisibility, ['on', 'off'], true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $ui['public_count_save_error']], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $enabled = $requestedVisibility === 'on';
        try {
            pollyanna_save_count_display($enabled);
            echo json_encode(['success' => true, 'show_like_count' => $enabled], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $error) {
            error_log('Pollyanna local public count update failed: ' . $error->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $ui['public_count_save_error']], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if (($_POST['action'] ?? '') === 'save_count_refresh_interval') {
        $requestedInterval = filter_var($_POST['count_refresh_interval'] ?? null, FILTER_VALIDATE_INT);
        if ($requestedInterval === false || !in_array($requestedInterval, [0, 5, 10, 30, 60], true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $ui['count_refresh_save_error']], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            pollyanna_save_count_refresh_interval($requestedInterval);
            echo json_encode(['success' => true, 'seconds' => $requestedInterval], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $error) {
            error_log('Pollyanna local count refresh interval update failed: ' . $error->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $ui['count_refresh_save_error']], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if (($_POST['action'] ?? '') === 'save_public_count_color') {
        $requestedColor = $_POST['like_count_color'] ?? '';
        if (!is_string($requestedColor) || preg_match('/\A#[0-9a-fA-F]{6}\z/', $requestedColor) !== 1) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => $ui['public_count_color_save_error']], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            pollyanna_save_count_display_color($requestedColor);
            echo json_encode(['success' => true, 'color' => strtolower($requestedColor)], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $error) {
            error_log('Pollyanna local public count color update failed: ' . $error->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $ui['public_count_color_save_error']], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    try {
        $pdo = pollyanna_pdo();
        $settings = pollyanna_aggregation_settings();
        $testBatchSize = min($settings['aggregation_batch_size'], 30);
        $likes = new LikeService($pdo);
        $pendingBefore = $likes->pendingCount();
        $processed = (new AggregationService($pdo))->aggregateBatch(
            $testBatchSize,
            $settings['timezone']
        );
        $pendingAfter = $likes->pendingCount();
        $aggregatedAfter = (int) $pdo->query(
            'SELECT COALESCE(SUM(total_likes), 0) FROM pollyanna_lifetime_totals'
        )->fetchColumn();
        $dailyRowCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM pollyanna_period_aggregates WHERE period_type = 'day'"
        )->fetchColumn();
        $hourlyRows = pollyanna_local_hourly_rows($pdo, $settings['timezone']);
        $periodRows = pollyanna_local_period_rows($pdo);
        $lifetimeRows = pollyanna_local_lifetime_rows($pdo);
        $showLikeCount = pollyanna_count_display_enabled();
        $systemState = pollyanna_system_state();

        echo json_encode([
            'success' => true,
            'processed' => $processed,
            'aggregated_after' => $aggregatedAfter,
            'daily_row_count' => $dailyRowCount,
            'daily_row_limit' => AggregationService::DAILY_ROW_LIMIT,
            'timezone' => $settings['timezone'],
            'aggregation_start_threshold' => $settings['aggregation_start_threshold'],
            'show_like_count' => $showLikeCount,
            'system_state' => $systemState,
            'pending_before' => $pendingBefore,
            'pending_after' => $pendingAfter,
            'configured_batch_size' => $settings['aggregation_batch_size'],
            'test_batch_limit' => $testBatchSize,
            'hourly_rows' => $hourlyRows,
            'period_rows' => $periodRows,
            'lifetime_rows' => $lifetimeRows,
            'message' => $processed === 0
                ? $ui['empty_batch']
                : $processed . ($language === 'en' ? ' ' . $ui['processed_suffix'] : $ui['processed_suffix']),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $error) {
        error_log('Pollyanna local aggregation page failed: ' . $error->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => $ui['aggregation_error']], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET, POST');
    http_response_code(405);
    exit;
}

try {
    $pdo = pollyanna_pdo();
    $pendingCount = (new LikeService($pdo))->pendingCount();
    $aggregatedCount = (int) $pdo->query(
        'SELECT COALESCE(SUM(total_likes), 0) FROM pollyanna_lifetime_totals'
    )->fetchColumn();
    $dailyRowCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM pollyanna_period_aggregates WHERE period_type = 'day'"
    )->fetchColumn();
    $settings = pollyanna_aggregation_settings();
    (new TimezoneChangeService($pdo))->recoverCommittedChange();
    $timezoneChangeState = pollyanna_timezone_change_state();
    $testBatchLimit = min($settings['aggregation_batch_size'], 30);
    $hourlyRows = pollyanna_local_hourly_rows($pdo, $settings['timezone']);
    $periodRows = pollyanna_local_period_rows($pdo);
    $lifetimeRows = pollyanna_local_lifetime_rows($pdo);
    $showLikeCount = pollyanna_count_display_enabled();
    $likeCountColor = pollyanna_count_display_color();
    $countRefreshInterval = pollyanna_count_refresh_interval();
    $systemState = pollyanna_system_state();
    $rateLimit = pollyanna_rate_limit();
    $widgetCatalog = pollyanna_widget_catalog();
    $widgetSettings = pollyanna_widget_settings();
    $clickSuccessMessage = $widgetCatalog[$widgetSettings['language']]['success_messages'][$widgetSettings['success_message']];
} catch (Throwable $error) {
    error_log('Pollyanna local aggregation page status failed: ' . $error->getMessage());
    http_response_code(503);
    exit($ui['database_error']);
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
?>
<!doctype html>
<html lang="<?= htmlspecialchars($language, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($ui['title'], ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        body { max-width: 42rem; margin: 2rem auto; padding: 0 1rem; font: 16px/1.6 sans-serif; }
        .notice { padding: 1rem; background: #fff4d6; border: 1px solid #d7a900; }
        button { padding: .6rem 1rem; font: inherit; }
        #result { min-height: 1.6em; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { padding: .4rem .6rem; border: 1px solid #ccc; text-align: left; }
        .table-wrap { overflow-x: auto; }
    </style>
</head>
<body>
    <h1><?= htmlspecialchars($ui['title'], ENT_QUOTES, 'UTF-8') ?></h1>
    <form method="get">
        <label for="language"><?= htmlspecialchars($ui['language'], ENT_QUOTES, 'UTF-8') ?>:</label>
        <select id="language" name="language" onchange="this.form.submit()">
            <?php foreach ($availableLanguages as $code => $translations): ?>
                <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>" <?= $language === $code ? 'selected' : '' ?>><?= htmlspecialchars($translations['language_name'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
    </form>
    <p class="notice"><strong><?= htmlspecialchars($ui['local_only'], ENT_QUOTES, 'UTF-8') ?></strong></p>
    <ul>
        <li><?= htmlspecialchars($ui['aggregated'], ENT_QUOTES, 'UTF-8') ?>：<strong id="aggregated-count"><?= htmlspecialchars((string) $aggregatedCount, ENT_QUOTES, 'UTF-8') ?></strong></li>
        <li><?= htmlspecialchars($ui['pending'], ENT_QUOTES, 'UTF-8') ?>：<strong id="pending-count"><?= htmlspecialchars((string) $pendingCount, ENT_QUOTES, 'UTF-8') ?></strong></li>
        <li><?= htmlspecialchars($ui['daily_rows'], ENT_QUOTES, 'UTF-8') ?>：<strong id="daily-row-count"><?= htmlspecialchars((string) $dailyRowCount, ENT_QUOTES, 'UTF-8') ?></strong> / <strong id="daily-row-limit"><?= AggregationService::DAILY_ROW_LIMIT ?></strong><?= htmlspecialchars($ui['rows'], ENT_QUOTES, 'UTF-8') ?></li>
        <li><?= htmlspecialchars($ui['timezone'], ENT_QUOTES, 'UTF-8') ?>：<strong id="timezone-name"><?= htmlspecialchars($settings['timezone'], ENT_QUOTES, 'UTF-8') ?></strong></li>
        <li><?= htmlspecialchars($ui['threshold'], ENT_QUOTES, 'UTF-8') ?>：<strong id="aggregation-threshold"><?= htmlspecialchars((string) $settings['aggregation_start_threshold'], ENT_QUOTES, 'UTF-8') ?></strong></li>
        <li><?= htmlspecialchars($ui['configured_batch'], ENT_QUOTES, 'UTF-8') ?>：<strong id="configured-batch-size"><?= htmlspecialchars((string) $settings['aggregation_batch_size'], ENT_QUOTES, 'UTF-8') ?></strong></li>
        <li><?= htmlspecialchars($ui['test_batch'], ENT_QUOTES, 'UTF-8') ?>：<strong id="test-batch-limit"><?= htmlspecialchars((string) $testBatchLimit, ENT_QUOTES, 'UTF-8') ?></strong></li>
        <li><?= htmlspecialchars($ui['public_count'], ENT_QUOTES, 'UTF-8') ?>：<strong id="show-like-count"><?= htmlspecialchars($showLikeCount ? $ui['on'] : $ui['off'], ENT_QUOTES, 'UTF-8') ?></strong></li>
        <li><?= htmlspecialchars($ui['count_refresh_interval'], ENT_QUOTES, 'UTF-8') ?>：<strong id="count-refresh-value"><?= htmlspecialchars($ui['refresh_intervals'][(string) $countRefreshInterval], ENT_QUOTES, 'UTF-8') ?></strong></li>
        <li><?= htmlspecialchars($ui['public_count_color'], ENT_QUOTES, 'UTF-8') ?>：<strong id="public-count-color-value"><?= htmlspecialchars($likeCountColor, ENT_QUOTES, 'UTF-8') ?></strong></li>
        <li><?= htmlspecialchars($ui['system_state'], ENT_QUOTES, 'UTF-8') ?>：<strong id="system-state"><?= htmlspecialchars($ui['system_states'][$systemState], ENT_QUOTES, 'UTF-8') ?></strong></li>
        <li><?= htmlspecialchars($ui['click_message'], ENT_QUOTES, 'UTF-8') ?>：<strong id="click-message-value"><?= htmlspecialchars($clickSuccessMessage, ENT_QUOTES, 'UTF-8') ?></strong></li>
        <li><?= htmlspecialchars($ui['rate_limit'], ENT_QUOTES, 'UTF-8') ?>：<strong id="rate-limit-value"><?= htmlspecialchars((string) $rateLimit, ENT_QUOTES, 'UTF-8') ?></strong> / 24h</li>
    </ul>
    <form id="system-state-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="save_system_state">
        <label for="system-state-select"><?= htmlspecialchars($ui['system_state'], ENT_QUOTES, 'UTF-8') ?>:</label>
        <select id="system-state-select" name="system_state">
            <?php foreach ($ui['system_states'] as $state => $label): ?>
                <option value="<?= htmlspecialchars($state, ENT_QUOTES, 'UTF-8') ?>" <?= $systemState === $state ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
        <button id="system-state-save" type="submit"><?= htmlspecialchars($ui['save_state'], ENT_QUOTES, 'UTF-8') ?></button>
    </form>
    <p id="system-state-result" role="status" aria-live="polite"></p>
    <form id="click-message-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="save_click_message">
        <label for="widget-language-select"><?= htmlspecialchars($ui['widget_language'], ENT_QUOTES, 'UTF-8') ?>:</label>
        <select id="widget-language-select" name="widget_language">
            <?php foreach ($widgetCatalog as $code => $locale): ?>
                <option value="<?= htmlspecialchars($code, ENT_QUOTES, 'UTF-8') ?>" <?= $widgetSettings['language'] === $code ? 'selected' : '' ?>><?= htmlspecialchars($locale['language_name'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
        <label for="click-message-select"><?= htmlspecialchars($ui['click_message'], ENT_QUOTES, 'UTF-8') ?>:</label>
        <select id="click-message-select" name="click_message">
            <?php foreach ($widgetCatalog[$widgetSettings['language']]['success_messages'] as $key => $message): ?>
                <option value="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" <?= $widgetSettings['success_message'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
        <button id="click-message-save" type="submit"><?= htmlspecialchars($ui['save_message'], ENT_QUOTES, 'UTF-8') ?></button>
    </form>
    <p id="click-message-result" role="status" aria-live="polite"></p>
    <form id="rate-limit-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="save_rate_limit">
        <label for="rate-limit-select"><?= htmlspecialchars($ui['rate_limit'], ENT_QUOTES, 'UTF-8') ?>:</label>
        <select id="rate-limit-select" name="likes_per_24_hours">
            <?php foreach ([1, 2, 3, 4, 5] as $limit): ?>
                <option value="<?= $limit ?>" <?= $rateLimit === $limit ? 'selected' : '' ?>><?= $limit ?> / 24h</option>
            <?php endforeach; ?>
        </select>
        <button id="rate-limit-save" type="submit"><?= htmlspecialchars($ui['save_rate_limit'], ENT_QUOTES, 'UTF-8') ?></button>
    </form>
    <p><?= htmlspecialchars($ui['rate_limit_note'], ENT_QUOTES, 'UTF-8') ?></p>
    <p id="rate-limit-result" role="status" aria-live="polite"></p>
    <form id="aggregation-threshold-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="save_aggregation_threshold">
        <label for="aggregation-threshold-select"><?= htmlspecialchars($ui['threshold'], ENT_QUOTES, 'UTF-8') ?>:</label>
        <select id="aggregation-threshold-select" name="aggregation_start_threshold" required>
            <?php if (!in_array($settings['aggregation_start_threshold'], [1000, 2000, 4000, 6000, 8000, 10000], true)): ?>
                <option value="" selected><?= htmlspecialchars((string) $settings['aggregation_start_threshold'] . ' (' . $ui['current_config'] . ')', ENT_QUOTES, 'UTF-8') ?></option>
            <?php endif; ?>
            <?php foreach ([1000, 2000, 4000, 6000, 8000, 10000] as $threshold): ?>
                <option value="<?= $threshold ?>" <?= $settings['aggregation_start_threshold'] === $threshold ? 'selected' : '' ?>><?= number_format($threshold) ?></option>
            <?php endforeach; ?>
        </select>
        <button id="aggregation-threshold-save" type="submit"><?= htmlspecialchars($ui['save_threshold'], ENT_QUOTES, 'UTF-8') ?></button>
    </form>
    <p id="aggregation-threshold-result" role="status" aria-live="polite"></p>
    <form id="public-count-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="save_public_count">
        <label for="public-count-select"><?= htmlspecialchars($ui['public_count'], ENT_QUOTES, 'UTF-8') ?>:</label>
        <select id="public-count-select" name="show_like_count">
            <option value="off" <?= !$showLikeCount ? 'selected' : '' ?>><?= htmlspecialchars($ui['off'], ENT_QUOTES, 'UTF-8') ?></option>
            <option value="on" <?= $showLikeCount ? 'selected' : '' ?>><?= htmlspecialchars($ui['on'], ENT_QUOTES, 'UTF-8') ?></option>
        </select>
        <button id="public-count-save" type="submit"><?= htmlspecialchars($ui['save_public_count'], ENT_QUOTES, 'UTF-8') ?></button>
    </form>
    <p id="public-count-result" role="status" aria-live="polite"></p>
    <form id="count-refresh-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="save_count_refresh_interval">
        <label for="count-refresh-select"><?= htmlspecialchars($ui['count_refresh_interval'], ENT_QUOTES, 'UTF-8') ?>:</label>
        <select id="count-refresh-select" name="count_refresh_interval">
            <?php foreach ([5, 10, 30, 60, 0] as $seconds): ?>
                <option value="<?= $seconds ?>" <?= $countRefreshInterval === $seconds ? 'selected' : '' ?>><?= htmlspecialchars($ui['refresh_intervals'][(string) $seconds], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
        <button id="count-refresh-save" type="submit"><?= htmlspecialchars($ui['save_count_refresh'], ENT_QUOTES, 'UTF-8') ?></button>
    </form>
    <p id="count-refresh-result" role="status" aria-live="polite"></p>
    <form id="public-count-color-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="save_public_count_color">
        <label for="public-count-color-input"><?= htmlspecialchars($ui['public_count_color'], ENT_QUOTES, 'UTF-8') ?>:</label>
        <input id="public-count-color-input" type="color" name="like_count_color" value="<?= htmlspecialchars($likeCountColor, ENT_QUOTES, 'UTF-8') ?>">
        <button id="public-count-color-save" type="submit"><?= htmlspecialchars($ui['save_public_count_color'], ENT_QUOTES, 'UTF-8') ?></button>
    </form>
    <p id="public-count-color-result" role="status" aria-live="polite"></p>
    <form id="timezone-change-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="begin_timezone_change">
        <label for="timezone-select"><?= htmlspecialchars($ui['timezone'], ENT_QUOTES, 'UTF-8') ?>:</label>
        <select id="timezone-select" name="timezone" required>
            <?php if ($timezoneChangeState !== null): ?>
                <option value="<?= htmlspecialchars($timezoneChangeState['new_timezone'], ENT_QUOTES, 'UTF-8') ?>" selected><?= htmlspecialchars($timezoneChangeState['new_timezone'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endif; ?>
            <?php foreach (DateTimeZone::listIdentifiers() as $timezoneOption): ?>
                <option value="<?= htmlspecialchars($timezoneOption, ENT_QUOTES, 'UTF-8') ?>" <?= $settings['timezone'] === $timezoneOption && $timezoneChangeState === null ? 'selected' : '' ?>><?= htmlspecialchars($timezoneOption, ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
        </select>
        <button id="timezone-change-button" type="submit"><?= htmlspecialchars($timezoneChangeState === null ? $ui['change_timezone'] : $ui['resume_timezone_change'], ENT_QUOTES, 'UTF-8') ?></button>
    </form>
    <p><?= htmlspecialchars($ui['timezone_change_warning'], ENT_QUOTES, 'UTF-8') ?></p>
    <p id="timezone-change-result" role="status" aria-live="polite"><?= $timezoneChangeState === null ? '' : htmlspecialchars($ui['timezone_change_interrupted'], ENT_QUOTES, 'UTF-8') ?></p>
    <h2><?= htmlspecialchars($ui['lifetime_title'], ENT_QUOTES, 'UTF-8') ?></h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th><?= htmlspecialchars($ui['like_id'], ENT_QUOTES, 'UTF-8') ?></th><th><?= htmlspecialchars($ui['lifetime_count'], ENT_QUOTES, 'UTF-8') ?></th></tr></thead>
            <tbody id="lifetime-rows">
            <?php if ($lifetimeRows === []): ?>
                <tr><td colspan="2"><?= htmlspecialchars($ui['no_data'], ENT_QUOTES, 'UTF-8') ?></td></tr>
            <?php else: ?>
                <?php foreach ($lifetimeRows as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['identifier'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) $row['total_likes'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <p><?= htmlspecialchars($ui['run_note'], ENT_QUOTES, 'UTF-8') ?></p>
    <form id="aggregate-form" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <button id="aggregate-button" type="submit"><?= htmlspecialchars($ui['run_button'], ENT_QUOTES, 'UTF-8') ?></button>
    </form>
    <p id="result" role="status" aria-live="polite"><?= htmlspecialchars($ui['starting'], ENT_QUOTES, 'UTF-8') ?></p>
    <h2><?= htmlspecialchars($ui['hourly_title'], ENT_QUOTES, 'UTF-8') ?></h2>
    <table>
        <thead><tr><th><?= htmlspecialchars($ui['month'], ENT_QUOTES, 'UTF-8') ?></th><th><?= htmlspecialchars($ui['hour'], ENT_QUOTES, 'UTF-8') ?></th><th><?= htmlspecialchars($ui['count'], ENT_QUOTES, 'UTF-8') ?></th></tr></thead>
        <tbody id="hourly-rows">
        <?php if ($hourlyRows === []): ?>
            <tr><td colspan="3"><?= htmlspecialchars($ui['no_data'], ENT_QUOTES, 'UTF-8') ?></td></tr>
        <?php else: ?>
            <?php foreach ($hourlyRows as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['month_start'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= sprintf('%02d:00', $row['hour_of_day']) ?></td>
                    <td><?= htmlspecialchars((string) $row['like_count'], ENT_QUOTES, 'UTF-8') ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
    <h2><?= htmlspecialchars($ui['period_title'], ENT_QUOTES, 'UTF-8') ?></h2>
    <div class="table-wrap">
        <table>
            <thead><tr><th><?= htmlspecialchars($ui['period'], ENT_QUOTES, 'UTF-8') ?></th><th><?= htmlspecialchars($ui['start_date'], ENT_QUOTES, 'UTF-8') ?></th><th><?= htmlspecialchars($ui['like_id'], ENT_QUOTES, 'UTF-8') ?></th><th><?= htmlspecialchars($ui['count'], ENT_QUOTES, 'UTF-8') ?></th></tr></thead>
            <tbody id="period-rows">
            <?php if ($periodRows === []): ?>
                <tr><td colspan="4"><?= htmlspecialchars($ui['no_data'], ENT_QUOTES, 'UTF-8') ?></td></tr>
            <?php else: ?>
                <?php foreach ($periodRows as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($ui['period_types'][$row['period_type']] ?? $row['period_type'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($row['period_start'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($row['identifier'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) $row['like_count'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <script>
        (() => {
            const labels = <?= json_encode([
                'no_data' => $ui['no_data'],
                'aggregating' => $ui['aggregating'],
                'aggregation_error' => $ui['aggregation_error'],
                'pending_transition' => $ui['pending_transition'],
                'count_suffix' => $ui['count_suffix'],
                'on' => $ui['on'],
                'off' => $ui['off'],
                'system_states' => $ui['system_states'],
                'saving_state' => $ui['saving_state'],
                'state_saved' => $ui['state_saved'],
                'state_save_error' => $ui['state_save_error'],
                'saving_message' => $ui['saving_message'],
                'message_saved' => $ui['message_saved'],
                'message_save_error' => $ui['message_save_error'],
                'saving_rate_limit' => $ui['saving_rate_limit'],
                'rate_limit_saved' => $ui['rate_limit_saved'],
                'rate_limit_save_error' => $ui['rate_limit_save_error'],
                'saving_threshold' => $ui['saving_threshold'],
                'threshold_saved' => $ui['threshold_saved'],
                'threshold_save_error' => $ui['threshold_save_error'],
                'saving_public_count' => $ui['saving_public_count'],
                'public_count_saved' => $ui['public_count_saved'],
                'public_count_save_error' => $ui['public_count_save_error'],
                'saving_count_refresh' => $ui['saving_count_refresh'],
                'count_refresh_saved' => $ui['count_refresh_saved'],
                'count_refresh_save_error' => $ui['count_refresh_save_error'],
                'refresh_intervals' => $ui['refresh_intervals'],
                'saving_public_count_color' => $ui['saving_public_count_color'],
                'public_count_color_saved' => $ui['public_count_color_saved'],
                'public_count_color_save_error' => $ui['public_count_color_save_error'],
                'change_timezone' => $ui['change_timezone'],
                'resume_timezone_change' => $ui['resume_timezone_change'],
                'timezone_change_confirm' => $ui['timezone_change_confirm'],
                'timezone_aggregating' => $ui['timezone_aggregating'],
                'timezone_archiving' => $ui['timezone_archiving'],
                'timezone_change_done' => $ui['timezone_change_done'],
                'timezone_archive_storage_note' => $ui['timezone_archive_storage_note'],
                'timezone_change_error' => $ui['timezone_change_error'],
                'timezone_change_warning' => $ui['timezone_change_warning'],
                'timezone_change_interrupted' => $ui['timezone_change_interrupted'],
                'period_types' => $ui['period_types'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const form = document.getElementById('aggregate-form');
            const button = document.getElementById('aggregate-button');
            const result = document.getElementById('result');
            const pending = document.getElementById('pending-count');
            const aggregated = document.getElementById('aggregated-count');
            const dailyRowCount = document.getElementById('daily-row-count');
            const dailyRowLimit = document.getElementById('daily-row-limit');
            const timezoneName = document.getElementById('timezone-name');
            const aggregationThreshold = document.getElementById('aggregation-threshold');
            const configuredBatchSize = document.getElementById('configured-batch-size');
            const testBatchLimit = document.getElementById('test-batch-limit');
            const showLikeCount = document.getElementById('show-like-count');
            const systemState = document.getElementById('system-state');
            const systemStateForm = document.getElementById('system-state-form');
            const systemStateSelect = document.getElementById('system-state-select');
            const systemStateSaveButton = document.getElementById('system-state-save');
            const systemStateResult = document.getElementById('system-state-result');
            const clickMessageForm = document.getElementById('click-message-form');
            const clickMessageSelect = document.getElementById('click-message-select');
            const widgetLanguageSelect = document.getElementById('widget-language-select');
            const clickMessageValue = document.getElementById('click-message-value');
            const clickMessageSaveButton = document.getElementById('click-message-save');
            const clickMessageResult = document.getElementById('click-message-result');
            const rateLimitForm = document.getElementById('rate-limit-form');
            const rateLimitSelect = document.getElementById('rate-limit-select');
            const rateLimitValue = document.getElementById('rate-limit-value');
            const rateLimitSaveButton = document.getElementById('rate-limit-save');
            const rateLimitResult = document.getElementById('rate-limit-result');
            const aggregationThresholdForm = document.getElementById('aggregation-threshold-form');
            const aggregationThresholdSelect = document.getElementById('aggregation-threshold-select');
            const aggregationThresholdSaveButton = document.getElementById('aggregation-threshold-save');
            const aggregationThresholdResult = document.getElementById('aggregation-threshold-result');
            const publicCountForm = document.getElementById('public-count-form');
            const publicCountSelect = document.getElementById('public-count-select');
            const publicCountSaveButton = document.getElementById('public-count-save');
            const publicCountResult = document.getElementById('public-count-result');
            const countRefreshForm = document.getElementById('count-refresh-form');
            const countRefreshSelect = document.getElementById('count-refresh-select');
            const countRefreshValue = document.getElementById('count-refresh-value');
            const countRefreshSaveButton = document.getElementById('count-refresh-save');
            const countRefreshResult = document.getElementById('count-refresh-result');
            const publicCountColorForm = document.getElementById('public-count-color-form');
            const publicCountColorInput = document.getElementById('public-count-color-input');
            const publicCountColorValue = document.getElementById('public-count-color-value');
            const publicCountColorSaveButton = document.getElementById('public-count-color-save');
            const publicCountColorResult = document.getElementById('public-count-color-result');
            const timezoneChangeForm = document.getElementById('timezone-change-form');
            const timezoneSelect = document.getElementById('timezone-select');
            const timezoneChangeButton = document.getElementById('timezone-change-button');
            const timezoneChangeResult = document.getElementById('timezone-change-result');
            let timezoneChangeInProgress = <?= $timezoneChangeState === null ? 'false' : 'true' ?>;
            const hourlyRowsBody = document.getElementById('hourly-rows');
            const periodRowsBody = document.getElementById('period-rows');
            const lifetimeRowsBody = document.getElementById('lifetime-rows');
            const widgetCatalog = <?= json_encode($widgetCatalog, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

            function renderHourlyRows(rows) {
                hourlyRowsBody.replaceChildren();
                if (rows.length === 0) {
                    const row = document.createElement('tr');
                    const cell = document.createElement('td');
                    cell.colSpan = 3;
                    cell.textContent = labels.no_data;
                    row.appendChild(cell);
                    hourlyRowsBody.appendChild(row);
                    return;
                }

                rows.forEach((item) => {
                    const row = document.createElement('tr');
                    [item.month_start, `${String(item.hour_of_day).padStart(2, '0')}:00`, item.like_count]
                        .forEach((value) => {
                            const cell = document.createElement('td');
                            cell.textContent = String(value);
                            row.appendChild(cell);
                        });
                    hourlyRowsBody.appendChild(row);
                });
            }

            function renderPeriodRows(rows) {
                periodRowsBody.replaceChildren();
                if (rows.length === 0) {
                    const row = document.createElement('tr');
                    const cell = document.createElement('td');
                    cell.colSpan = 4;
                    cell.textContent = labels.no_data;
                    row.appendChild(cell);
                    periodRowsBody.appendChild(row);
                    return;
                }

                rows.forEach((item) => {
                    const row = document.createElement('tr');
                    [labels.period_types[item.period_type] || item.period_type, item.period_start, item.identifier, item.like_count]
                        .forEach((value) => {
                            const cell = document.createElement('td');
                            cell.textContent = String(value);
                            row.appendChild(cell);
                        });
                    periodRowsBody.appendChild(row);
                });
            }

            function renderLifetimeRows(rows) {
                lifetimeRowsBody.replaceChildren();
                if (rows.length === 0) {
                    const row = document.createElement('tr');
                    const cell = document.createElement('td');
                    cell.colSpan = 2;
                    cell.textContent = labels.no_data;
                    row.appendChild(cell);
                    lifetimeRowsBody.appendChild(row);
                    return;
                }

                rows.forEach((item) => {
                    const row = document.createElement('tr');
                    [item.identifier, item.total_likes].forEach((value) => {
                        const cell = document.createElement('td');
                        cell.textContent = String(value);
                        row.appendChild(cell);
                    });
                    lifetimeRowsBody.appendChild(row);
                });
            }

            async function aggregateOnce() {
                button.disabled = true;
                result.textContent = labels.aggregating;
                try {
                    const response = await fetch(location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                        body: new URLSearchParams(new FormData(form)),
                        credentials: 'same-origin',
                    });
                    const data = await response.json();
                    if (!response.ok || !data.success) {
                        throw new Error(data.message || labels.aggregation_error);
                    }
                    aggregated.textContent = String(data.aggregated_after);
                    dailyRowCount.textContent = String(data.daily_row_count);
                    dailyRowLimit.textContent = String(data.daily_row_limit);
                    timezoneName.textContent = String(data.timezone);
                    aggregationThreshold.textContent = String(data.aggregation_start_threshold);
                    configuredBatchSize.textContent = String(data.configured_batch_size);
                    testBatchLimit.textContent = String(data.test_batch_limit);
                    showLikeCount.textContent = data.show_like_count ? labels.on : labels.off;
                    systemState.textContent = labels.system_states[data.system_state] || data.system_state;
                    pending.textContent = String(data.pending_after);
                    renderHourlyRows(data.hourly_rows);
                    renderPeriodRows(data.period_rows);
                    renderLifetimeRows(data.lifetime_rows);
                    result.textContent = `${data.message} ${labels.pending_transition}: ${data.pending_before}${labels.count_suffix} → ${data.pending_after}${labels.count_suffix}`;
                } catch (error) {
                    result.textContent = error instanceof Error ? error.message : labels.aggregation_error;
                } finally {
                    button.disabled = false;
                }
            }

            form.addEventListener('submit', (event) => {
                event.preventDefault();
                aggregateOnce();
            });
            systemStateForm.addEventListener('submit', async (event) => {
                event.preventDefault();
                systemStateSaveButton.disabled = true;
                systemStateResult.textContent = labels.saving_state;
                try {
                    const response = await fetch(location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                        body: new URLSearchParams(new FormData(systemStateForm)),
                        credentials: 'same-origin',
                    });
                    const data = await response.json();
                    if (!response.ok || data.success !== true) {
                        throw new Error(data.message || labels.state_save_error);
                    }
                    systemState.textContent = labels.system_states[data.system_state];
                    systemStateSelect.value = data.system_state;
                    systemStateResult.textContent = labels.state_saved;
                } catch (error) {
                    systemStateResult.textContent = error instanceof Error ? error.message : labels.state_save_error;
                } finally {
                    systemStateSaveButton.disabled = false;
                }
            });
            clickMessageForm.addEventListener('submit', async (event) => {
                event.preventDefault();
                clickMessageSaveButton.disabled = true;
                clickMessageResult.textContent = labels.saving_message;
                try {
                    const response = await fetch(location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                        body: new URLSearchParams(new FormData(clickMessageForm)),
                        credentials: 'same-origin',
                    });
                    const data = await response.json();
                    if (!response.ok || data.success !== true) {
                        throw new Error(data.message || labels.message_save_error);
                    }
                    clickMessageValue.textContent = data.click_message;
                    clickMessageSelect.value = data.click_message_key;
                    widgetLanguageSelect.value = data.widget_language;
                    clickMessageResult.textContent = labels.message_saved;
                } catch (error) {
                    clickMessageResult.textContent = error instanceof Error ? error.message : labels.message_save_error;
                } finally {
                    clickMessageSaveButton.disabled = false;
                }
            });
            rateLimitForm.addEventListener('submit', async (event) => {
                event.preventDefault();
                rateLimitSaveButton.disabled = true;
                rateLimitResult.textContent = labels.saving_rate_limit;
                try {
                    const response = await fetch(location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                        body: new URLSearchParams(new FormData(rateLimitForm)),
                        credentials: 'same-origin',
                    });
                    const data = await response.json();
                    if (!response.ok || data.success !== true) {
                        throw new Error(data.message || labels.rate_limit_save_error);
                    }
                    rateLimitSelect.value = String(data.likes_per_24_hours);
                    rateLimitValue.textContent = String(data.likes_per_24_hours);
                    rateLimitResult.textContent = labels.rate_limit_saved;
                } catch (error) {
                    rateLimitResult.textContent = error instanceof Error ? error.message : labels.rate_limit_save_error;
                } finally {
                    rateLimitSaveButton.disabled = false;
                }
            });
            aggregationThresholdForm.addEventListener('submit', async (event) => {
                event.preventDefault();
                aggregationThresholdSaveButton.disabled = true;
                aggregationThresholdResult.textContent = labels.saving_threshold;
                try {
                    const response = await fetch(location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                        body: new URLSearchParams(new FormData(aggregationThresholdForm)),
                        credentials: 'same-origin',
                    });
                    const data = await response.json();
                    if (!response.ok || data.success !== true) {
                        throw new Error(data.message || labels.threshold_save_error);
                    }
                    aggregationThresholdSelect.value = String(data.aggregation_start_threshold);
                    aggregationThreshold.textContent = String(data.aggregation_start_threshold);
                    aggregationThresholdResult.textContent = labels.threshold_saved;
                } catch (error) {
                    aggregationThresholdResult.textContent = error instanceof Error ? error.message : labels.threshold_save_error;
                } finally {
                    aggregationThresholdSaveButton.disabled = false;
                }
            });
            publicCountForm.addEventListener('submit', async (event) => {
                event.preventDefault();
                publicCountSaveButton.disabled = true;
                publicCountResult.textContent = labels.saving_public_count;
                try {
                    const response = await fetch(location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                        body: new URLSearchParams(new FormData(publicCountForm)),
                        credentials: 'same-origin',
                    });
                    const data = await response.json();
                    if (!response.ok || data.success !== true) {
                        throw new Error(data.message || labels.public_count_save_error);
                    }
                    publicCountSelect.value = data.show_like_count ? 'on' : 'off';
                    showLikeCount.textContent = data.show_like_count ? labels.on : labels.off;
                    publicCountResult.textContent = labels.public_count_saved;
                } catch (error) {
                    publicCountResult.textContent = error instanceof Error ? error.message : labels.public_count_save_error;
                } finally {
                    publicCountSaveButton.disabled = false;
                }
            });
            countRefreshForm.addEventListener('submit', async (event) => {
                event.preventDefault();
                countRefreshSaveButton.disabled = true;
                countRefreshResult.textContent = labels.saving_count_refresh;
                try {
                    const response = await fetch(location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                        body: new URLSearchParams(new FormData(countRefreshForm)),
                        credentials: 'same-origin',
                    });
                    const data = await response.json();
                    if (!response.ok || data.success !== true) {
                        throw new Error(data.message || labels.count_refresh_save_error);
                    }
                    countRefreshSelect.value = String(data.seconds);
                    countRefreshValue.textContent = labels.refresh_intervals[String(data.seconds)];
                    countRefreshResult.textContent = labels.count_refresh_saved;
                } catch (error) {
                    countRefreshResult.textContent = error instanceof Error ? error.message : labels.count_refresh_save_error;
                } finally {
                    countRefreshSaveButton.disabled = false;
                }
            });
            publicCountColorForm.addEventListener('submit', async (event) => {
                event.preventDefault();
                publicCountColorSaveButton.disabled = true;
                publicCountColorResult.textContent = labels.saving_public_count_color;
                try {
                    const response = await fetch(location.href, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                        body: new URLSearchParams(new FormData(publicCountColorForm)),
                        credentials: 'same-origin',
                    });
                    const data = await response.json();
                    if (!response.ok || data.success !== true) {
                        throw new Error(data.message || labels.public_count_color_save_error);
                    }
                    publicCountColorInput.value = data.color;
                    publicCountColorValue.textContent = data.color;
                    publicCountColorResult.textContent = labels.public_count_color_saved;
                } catch (error) {
                    publicCountColorResult.textContent = error instanceof Error ? error.message : labels.public_count_color_save_error;
                } finally {
                    publicCountColorSaveButton.disabled = false;
                }
            });
            async function postTimezoneAction(action, selectedTimezone = '') {
                const payload = new URLSearchParams(new FormData(timezoneChangeForm));
                payload.set('action', action);
                if (selectedTimezone) payload.set('timezone', selectedTimezone);
                const response = await fetch(location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                    body: payload,
                    credentials: 'same-origin',
                });
                const data = await response.json();
                if (!response.ok || data.success !== true) {
                    throw new Error(data.message || labels.timezone_change_error);
                }
                return data;
            }

            async function runTimezoneChange(selectedTimezone) {
                timezoneChangeButton.disabled = true;
                try {
                    if (!timezoneChangeInProgress) {
                        timezoneChangeResult.textContent = labels.timezone_aggregating;
                        await postTimezoneAction('begin_timezone_change', selectedTimezone);
                        timezoneChangeInProgress = true;
                    }
                    while (true) {
                        const batch = await postTimezoneAction('timezone_change_batch');
                        timezoneChangeResult.textContent = `${labels.timezone_aggregating} ${batch.pending}`;
                        if (batch.ready_to_archive) break;
                        if (batch.processed === 0) throw new Error(labels.timezone_change_error);
                    }
                    timezoneChangeResult.textContent = labels.timezone_archiving;
                    const completed = await postTimezoneAction('finish_timezone_change');
                    timezoneName.textContent = completed.timezone;
                    aggregated.textContent = '0';
                    pending.textContent = '0';
                    dailyRowCount.textContent = '0';
                    renderHourlyRows([]);
                    renderPeriodRows([]);
                    renderLifetimeRows([]);
                    timezoneChangeResult.textContent = `${labels.timezone_change_done} ${completed.archive_directory}. ${labels.timezone_archive_storage_note}`;
                    timezoneChangeInProgress = false;
                    timezoneSelect.value = completed.timezone;
                    timezoneChangeButton.textContent = labels.change_timezone;
                } catch (error) {
                    timezoneChangeResult.textContent = error instanceof Error ? error.message : labels.timezone_change_error;
                    timezoneChangeButton.textContent = timezoneChangeInProgress
                        ? labels.resume_timezone_change
                        : labels.change_timezone;
                } finally {
                    timezoneChangeButton.disabled = false;
                }
            }
            timezoneChangeForm.addEventListener('submit', (event) => {
                event.preventDefault();
                if (!timezoneChangeInProgress && timezoneSelect.value === timezoneName.textContent) {
                    timezoneChangeResult.textContent = labels.timezone_change_error;
                    return;
                }
                if (!timezoneChangeInProgress && !window.confirm(labels.timezone_change_confirm)) return;
                runTimezoneChange(timezoneSelect.value);
            });
            widgetLanguageSelect.addEventListener('change', () => {
                const previousKey = clickMessageSelect.value;
                const messages = widgetCatalog[widgetLanguageSelect.value].success_messages;
                clickMessageSelect.replaceChildren();
                Object.entries(messages).forEach(([key, message]) => {
                    const option = document.createElement('option');
                    option.value = key;
                    option.textContent = message;
                    clickMessageSelect.appendChild(option);
                });
                clickMessageSelect.value = Object.prototype.hasOwnProperty.call(messages, previousKey)
                    ? previousKey
                    : Object.keys(messages)[0];
            });
            aggregateOnce();
        })();
    </script>
</body>
</html>
