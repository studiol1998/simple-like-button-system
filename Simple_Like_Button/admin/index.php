<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/LikeService.php';
require_once dirname(__DIR__) . '/src/AggregationService.php';
require_once dirname(__DIR__) . '/src/TimezoneChangeService.php';

$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocalRequest = in_array($remoteAddress, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true);

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params(['httponly' => true, 'secure' => $isHttps, 'samesite' => 'Strict']);
session_start();

$catalog = pollyanna_admin_catalog();
$savedLanguage = pollyanna_admin_language();
$requestedLanguage = $_GET['language'] ?? $savedLanguage;
$language = is_string($requestedLanguage) && isset($catalog[$requestedLanguage]) ? $requestedLanguage : $savedLanguage;
$languageSaveFailed = false;
if (isset($_GET['language']) && $language !== $savedLanguage) {
    try {
        pollyanna_save_admin_language($language);
    } catch (Throwable $error) {
        error_log('Pollyanna admin language save failed: ' . $error->getMessage());
        $language = $savedLanguage;
        $languageSaveFailed = true;
    }
}
$_SESSION['pollyanna_admin_language'] = $language;
$ui = $catalog[$language]['admin_ui'] ?? null;
if (!is_array($ui)) {
    throw new RuntimeException('Pollyanna admin interface translations are missing.');
}
$languageSaveError = $languageSaveFailed ? (string) $ui['language_save_error'] : '';
$savedTheme = pollyanna_admin_theme();
$requestedTheme = $_GET['theme'] ?? $savedTheme;
$theme = is_string($requestedTheme) && in_array($requestedTheme, pollyanna_admin_themes(), true)
    ? $requestedTheme
    : $savedTheme;
$themeSaveFailed = false;
if (isset($_GET['theme']) && $theme !== $savedTheme) {
    try {
        pollyanna_save_admin_theme($theme);
    } catch (Throwable $error) {
        error_log('Pollyanna admin theme save failed: ' . $error->getMessage());
        $theme = $savedTheme;
        $themeSaveFailed = true;
    }
}
$themeSaveError = $themeSaveFailed ? (string) $ui['theme_save_error'] : '';

if (!isset($_SESSION['pollyanna_csrf_token']) || !is_string($_SESSION['pollyanna_csrf_token'])) {
    $_SESSION['pollyanna_csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['pollyanna_csrf_token'];
$authenticationError = '';
try {
    $adminPasswordHash = pollyanna_admin_password_hash();
} catch (Throwable $error) {
    error_log('Pollyanna admin credentials load failed: ' . $error->getMessage());
    $adminPasswordHash = null;
    $authenticationError = (string) $ui['authentication_error'];
}
$authAction = $_POST['action'] ?? '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && in_array($authAction, ['admin_setup', 'admin_login', 'admin_logout'], true)) {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($postedToken) || !hash_equals($csrfToken, $postedToken)) {
        http_response_code(403);
        $authenticationError = (string) ($catalog[$language]['csrf_error'] ?? $ui['authentication_error']);
    } elseif ($authAction === 'admin_logout') {
        unset($_SESSION['pollyanna_admin_authenticated']);
        session_regenerate_id(true);
        header('Location: ./');
        exit;
    } elseif ($authAction === 'admin_setup' && $adminPasswordHash === null && $isLocalRequest) {
        $password = $_POST['password'] ?? '';
        $confirmation = $_POST['password_confirmation'] ?? '';
        if (!is_string($password) || !is_string($confirmation) || $password !== $confirmation) {
            $authenticationError = (string) $ui['password_mismatch'];
        } else {
            try {
                pollyanna_save_admin_password($password);
                session_regenerate_id(true);
                $_SESSION['pollyanna_admin_authenticated'] = true;
                $_SESSION['pollyanna_csrf_token'] = bin2hex(random_bytes(32));
                header('Location: ./');
                exit;
            } catch (InvalidArgumentException $error) {
                $authenticationError = (string) $ui['password_requirement'];
            } catch (Throwable $error) {
                error_log('Pollyanna admin password setup failed: ' . $error->getMessage());
                $authenticationError = (string) $ui['authentication_error'];
            }
        }
    } elseif ($authAction === 'admin_login' && $adminPasswordHash !== null) {
        $now = time();
        $windowStarted = is_int($_SESSION['pollyanna_login_window'] ?? null) ? $_SESSION['pollyanna_login_window'] : $now;
        $attempts = is_int($_SESSION['pollyanna_login_attempts'] ?? null) ? $_SESSION['pollyanna_login_attempts'] : 0;
        if ($now - $windowStarted >= 60) {
            $windowStarted = $now;
            $attempts = 0;
        }
        if ($attempts >= 5) {
            http_response_code(429);
            $authenticationError = (string) $ui['login_limited'];
        } else {
            $password = $_POST['password'] ?? '';
            if (is_string($password) && password_verify($password, $adminPasswordHash)) {
                session_regenerate_id(true);
                $_SESSION['pollyanna_admin_authenticated'] = true;
                $_SESSION['pollyanna_csrf_token'] = bin2hex(random_bytes(32));
                unset($_SESSION['pollyanna_login_window'], $_SESSION['pollyanna_login_attempts']);
                header('Location: ./');
                exit;
            }
            $_SESSION['pollyanna_login_window'] = $windowStarted;
            $_SESSION['pollyanna_login_attempts'] = $attempts + 1;
            $authenticationError = (string) $ui['login_failed'];
        }
    }
}
$isAuthenticated = ($_SESSION['pollyanna_admin_authenticated'] ?? false) === true;
if (!$isAuthenticated) {
    $setupAvailable = $adminPasswordHash === null && $isLocalRequest;
    if ($adminPasswordHash === null && !$isLocalRequest) http_response_code(403);
    $authTitle = $setupAvailable ? $ui['admin_setup'] : $ui['admin_login'];
    $authDescription = $setupAvailable ? $ui['password_requirement'] : ($adminPasswordHash === null ? $ui['setup_local_only'] : '');
    $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ?>
    <!doctype html><html lang="<?= $escape($language) ?>" data-theme="<?= $escape($theme) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pollyanna — <?= $escape($authTitle) ?></title><link rel="stylesheet" href="../assets/admin.css"></head><body class="auth-page"><main class="auth-main"><section class="panel auth-card"><div class="brand-line"><span class="heart" aria-hidden="true">♥</span><span class="brand">Pollyanna</span></div><h1><?= $escape($authTitle) ?></h1><?php if ($authDescription !== ''): ?><p class="muted"><?= $escape($authDescription) ?></p><?php endif ?><?php if ($authenticationError !== ''): ?><div class="notice settings-error" role="alert"><?= $escape($authenticationError) ?></div><?php endif ?><?php if ($adminPasswordHash !== null || $setupAvailable): ?><form method="post" class="auth-form"><input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="<?= $setupAvailable ? 'admin_setup' : 'admin_login' ?>"><label for="admin-password"><?= $escape($ui['password']) ?><input id="admin-password" name="password" type="password" minlength="12" maxlength="1024" required autocomplete="<?= $setupAvailable ? 'new-password' : 'current-password' ?>"></label><?php if ($setupAvailable): ?><label for="admin-password-confirmation"><?= $escape($ui['password_confirmation']) ?><input id="admin-password-confirmation" name="password_confirmation" type="password" minlength="12" maxlength="1024" required autocomplete="new-password"></label><?php endif ?><label class="password-toggle"><input type="checkbox" data-password-toggle> <?= $escape($ui['show_password']) ?></label><button class="primary" type="submit"><?= $escape($setupAvailable ? $ui['create_password'] : $ui['login']) ?></button></form><?php endif ?></section></main><script src="../assets/admin.js"></script></body></html>
    <?php
    exit;
}
$settingsResult = isset($_SESSION['pollyanna_settings_result']) && is_string($_SESSION['pollyanna_settings_result'])
    ? $_SESSION['pollyanna_settings_result']
    : '';
unset($_SESSION['pollyanna_settings_result']);
$settingsError = '';

$widgetCatalog = pollyanna_widget_catalog();
$widgetSettings = pollyanna_widget_settings();
$formSystemState = pollyanna_system_state();
$formAggregationThreshold = pollyanna_aggregation_settings()['aggregation_start_threshold'];
$formWidgetLanguage = $widgetSettings['language'];
$formSuccessMessage = $widgetSettings['success_message'];
$formRateLimit = pollyanna_rate_limit();
$formShowCount = pollyanna_count_display_enabled();
$formRefreshInterval = pollyanna_count_refresh_interval();
$formCountColor = pollyanna_count_display_color();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    if (!is_string($postedToken) || !hash_equals($csrfToken, $postedToken)) {
        http_response_code(403);
        $settingsError = (string) ($catalog[$language]['csrf_error'] ?? $ui['settings_save_error']);
    } elseif (in_array($action, ['begin_timezone_change', 'timezone_change_batch', 'finish_timezone_change'], true)) {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $service = new TimezoneChangeService(pollyanna_pdo());
            if ($action === 'begin_timezone_change') {
                $requestedTimezone = $_POST['timezone'] ?? '';
                if (!is_string($requestedTimezone)) {
                    throw new InvalidArgumentException('The selected timezone is invalid.');
                }
                $result = $service->begin($requestedTimezone);
            } elseif ($action === 'timezone_change_batch') {
                $result = $service->aggregateNextBatch();
            } else {
                $result = $service->archiveAndReset();
            }
            echo json_encode(['success' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            error_log('Pollyanna admin timezone change failed: ' . $error->getMessage());
            http_response_code(422);
            echo json_encode([
                'success' => false,
                'message' => (string) $catalog[$language]['timezone_change_error'],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }
        exit;
    } elseif ($action === 'save_admin_operation') {
        $requestedState = $_POST['system_state'] ?? '';
        $requestedThreshold = filter_var($_POST['aggregation_start_threshold'] ?? null, FILTER_VALIDATE_INT);
        if (
            !is_string($requestedState)
            || !in_array($requestedState, ['enabled', 'maintenance'], true)
            || !is_int($requestedThreshold)
            || !in_array($requestedThreshold, [1000, 2000, 4000, 6000, 8000, 10000], true)
        ) {
            http_response_code(422);
            $settingsError = (string) $ui['settings_save_error'];
        } else {
            $formSystemState = $requestedState;
            $formAggregationThreshold = $requestedThreshold;
            try {
                pollyanna_save_settings_group(
                    ['system_state.json', 'aggregation_threshold_settings.json'],
                    static function () use ($requestedState, $requestedThreshold): void {
                        pollyanna_save_system_state($requestedState);
                        pollyanna_save_aggregation_threshold($requestedThreshold);
                    }
                );
                $_SESSION['pollyanna_settings_result'] = (string) $ui['admin_operation_saved'];
                header('Location: ?tab=settings');
                exit;
            } catch (Throwable $error) {
                error_log('Pollyanna admin operation settings save failed: ' . $error->getMessage());
                $settingsError = (string) $ui['settings_save_error'];
            }
        }
    } elseif ($action === 'save_public_display') {
        $requestedLanguage = $_POST['widget_language'] ?? '';
        $requestedMessage = $_POST['success_message'] ?? '';
        $requestedLimit = filter_var($_POST['likes_per_24_hours'] ?? null, FILTER_VALIDATE_INT);
        $requestedVisibility = $_POST['show_like_count'] ?? '';
        $requestedInterval = filter_var($_POST['count_refresh_interval'] ?? $formRefreshInterval, FILTER_VALIDATE_INT);
        $requestedColor = $_POST['like_count_color'] ?? $formCountColor;
        $valid =
            is_string($requestedLanguage)
            && isset($widgetCatalog[$requestedLanguage])
            && is_string($requestedMessage)
            && isset($widgetCatalog[$requestedLanguage]['success_messages'][$requestedMessage])
            && is_int($requestedLimit)
            && in_array($requestedLimit, [1, 2, 3, 4, 5], true)
            && is_string($requestedVisibility)
            && in_array($requestedVisibility, ['on', 'off'], true)
            && is_int($requestedInterval)
            && in_array($requestedInterval, [0, 5, 10, 30, 60], true)
            && is_string($requestedColor)
            && preg_match('/\A#[0-9a-fA-F]{6}\z/', $requestedColor) === 1;
        if (!$valid) {
            http_response_code(422);
            $settingsError = (string) $ui['settings_save_error'];
        } else {
            $formWidgetLanguage = $requestedLanguage;
            $formSuccessMessage = $requestedMessage;
            $formRateLimit = $requestedLimit;
            $formShowCount = $requestedVisibility === 'on';
            $formRefreshInterval = $requestedInterval;
            $formCountColor = strtolower($requestedColor);
            try {
                pollyanna_save_settings_group(
                    ['widget_settings.json', 'rate_limit_settings.json', 'public_count_settings.json', 'count_refresh_settings.json', 'public_count_color_settings.json'],
                    static function () use ($requestedLanguage, $requestedMessage, $requestedLimit, $requestedVisibility, $requestedInterval, $requestedColor): void {
                        pollyanna_save_widget_settings($requestedLanguage, $requestedMessage);
                        pollyanna_save_rate_limit($requestedLimit);
                        pollyanna_save_count_display($requestedVisibility === 'on');
                        pollyanna_save_count_refresh_interval($requestedInterval);
                        pollyanna_save_count_display_color($requestedColor);
                    }
                );
                $_SESSION['pollyanna_settings_result'] = (string) $ui['public_display_saved'];
                header('Location: ?tab=settings');
                exit;
            } catch (Throwable $error) {
                error_log('Pollyanna public display settings save failed: ' . $error->getMessage());
                $settingsError = (string) $ui['settings_save_error'];
            }
        }
    } else {
        http_response_code(400);
        $settingsError = (string) $ui['settings_save_error'];
    }
}

function admin_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function admin_chart(array $values, array $labels, string $class = '', int $labelEvery = 1): string
{
    if ($values === [] || array_sum($values) === 0) {
        return '';
    }
    $maximum = max(1, max($values));
    $html = '<div class="chart ' . admin_h($class) . '">';
    foreach ($values as $index => $value) {
        $height = max(1.5, ($value / $maximum) * 75);
        $label = ($index % $labelEvery === 0 || $index === count($values) - 1) ? (string) $labels[$index] : '';
        $html .= '<div class="bar-col"><span class="bar-value">' . number_format((int) $value) . '</span>';
        $html .= '<div class="bar" style="height:' . number_format($height, 2, '.', '') . '%"></div>';
        $html .= '<span class="bar-label">' . admin_h($label) . '</span></div>';
    }
    return $html . '</div>';
}

$dashboardError = '';
$aggregated = $pending = $total = $likeIds = 0;
$dailyValues = $monthlyValues = $hourlyValues = [];
$detailHourlyValues = array_fill(0, 24, 0);
$dailyLabels = $monthlyLabels = $hourlyLabels = [];
$availableDetailMonths = [];
$detailRows = [];
$selectedDetailId = is_string($_GET['id'] ?? null) ? trim((string) $_GET['id']) : '';
if (strlen($selectedDetailId) > 200) $selectedDetailId = '';
$detailTrendValues = $detailTrendLabels = [];
$detailSearch = is_string($_GET['q'] ?? null) ? trim((string) $_GET['q']) : '';
if (strlen($detailSearch) > 200) $detailSearch = substr($detailSearch, 0, 200);
$detailPeriod = is_string($_GET['period'] ?? null) && in_array($_GET['period'], ['7', '30', 'year', 'all'], true) ? $_GET['period'] : '7';
$detailUnit = is_string($_GET['unit'] ?? null) && in_array($_GET['unit'], ['day', 'month', 'year'], true) ? $_GET['unit'] : 'day';
$detailPage = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
$detailPageSize = 20;
$detailTotalRows = 0;
$detailTotalPages = 1;
$requestedDetailMonth = $_GET['month'] ?? '';
$selectedDetailMonth = is_string($requestedDetailMonth) && preg_match('/\A\d{4}-(?:0[1-9]|1[0-2])\z/', $requestedDetailMonth) === 1
    ? $requestedDetailMonth
    : '';
$settings = ['timezone' => 'Asia/Tokyo'];
$today = new DateTimeImmutable('today', new DateTimeZone($settings['timezone']));
$dailyStart = $today->modify('-6 days');
$monthStart = $today->modify('first day of this month')->modify('-11 months');
try {
    $pdo = pollyanna_pdo();
    pollyanna_ensure_identifier_urls_table($pdo);
    $settings = pollyanna_aggregation_settings();
    $timezone = new DateTimeZone($settings['timezone']);
    $utc = new DateTimeZone('UTC');
    $today = new DateTimeImmutable('today', $timezone);
    $dailyStart = $today->modify('-6 days');
    $monthStart = $today->modify('first day of this month')->modify('-11 months');
    $currentMonth = $today->format('Y-m');

    $availableDetailMonths = $pdo->query('SELECT DISTINCT DATE_FORMAT(month_start, \'%Y-%m\') AS month_key FROM pollyanna_hourly_aggregates ORDER BY month_start DESC')->fetchAll(PDO::FETCH_COLUMN);
    $availableDetailMonths = array_values(array_filter($availableDetailMonths, 'is_string'));
    if (!in_array($currentMonth, $availableDetailMonths, true)) {
        array_unshift($availableDetailMonths, $currentMonth);
    }
    if ($selectedDetailMonth === '' || !in_array($selectedDetailMonth, $availableDetailMonths, true)) {
        $selectedDetailMonth = $availableDetailMonths[0];
    }

    $identifierTotalsSql = 'SELECT identifier, SUM(item_total) AS total_likes FROM ('
        . 'SELECT identifier, total_likes AS item_total FROM pollyanna_lifetime_totals '
        . 'UNION ALL SELECT identifier, COUNT(*) AS item_total FROM pollyanna_likes GROUP BY identifier'
        . ') AS identifier_totals GROUP BY identifier';
    $detailParameters = [];
    $detailWhere = '';
    if ($detailSearch !== '') {
        $detailWhere = " WHERE identifier LIKE :identifier_search ESCAPE '\\\\'";
        $escapedSearch = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $detailSearch);
        $detailParameters[':identifier_search'] = '%' . $escapedSearch . '%';
    }
    $countStatement = $pdo->prepare('SELECT COUNT(*) FROM (' . $identifierTotalsSql . ') AS listed_identifiers' . $detailWhere);
    $countStatement->execute($detailParameters);
    $detailTotalRows = (int) $countStatement->fetchColumn();
    $detailTotalPages = max(1, (int) ceil($detailTotalRows / $detailPageSize));
    $detailPage = min($detailPage, $detailTotalPages);
    $detailOffset = ($detailPage - 1) * $detailPageSize;
    $detailStatement = $pdo->prepare('SELECT identifier, total_likes FROM (' . $identifierTotalsSql . ') AS listed_identifiers'
        . $detailWhere . ' ORDER BY total_likes DESC, identifier ASC LIMIT ' . $detailPageSize . ' OFFSET ' . $detailOffset);
    $detailStatement->execute($detailParameters);
    $detailRows = $detailStatement->fetchAll();
    foreach ($detailRows as &$detailRow) {
        $detailRow['total_likes'] = (int) $detailRow['total_likes'];
        $detailRow['period_likes'] = $detailPeriod === 'all' ? $detailRow['total_likes'] : 0;
        $detailRow['urls'] = [];
    }
    unset($detailRow);
    $detailIdentifiers = array_column($detailRows, 'identifier');
    if ($detailIdentifiers !== []) {
        $detailPlaceholders = implode(',', array_fill(0, count($detailIdentifiers), '?'));
        $urlStatement = $pdo->prepare('SELECT identifier, canonical_url FROM pollyanna_identifier_urls WHERE identifier IN (' . $detailPlaceholders . ') ORDER BY canonical_url');
        $urlStatement->execute($detailIdentifiers);
        $identifierUrls = [];
        foreach ($urlStatement->fetchAll() as $row) $identifierUrls[(string) $row['identifier']][] = (string) $row['canonical_url'];
        foreach ($detailRows as &$detailRow) $detailRow['urls'] = $identifierUrls[(string) $detailRow['identifier']] ?? [];
        unset($detailRow);
    }
    $detailRangeStart = null;
    if ($detailPeriod !== 'all') {
        $detailRangeStart = $detailPeriod === 'year'
            ? $today->modify('first day of January')
            : $today->modify('-' . ((int) $detailPeriod - 1) . ' days');
    }
    if ($detailRows !== [] && $detailRangeStart instanceof DateTimeImmutable) {
        $detailPlaceholders = implode(',', array_fill(0, count($detailIdentifiers), '?'));
        $periodStatement = $pdo->prepare('SELECT identifier, SUM(like_count) AS period_likes FROM pollyanna_period_aggregates '
            . 'WHERE period_start >= ? AND identifier IN (' . $detailPlaceholders . ') GROUP BY identifier');
        $periodStatement->execute(array_merge([$detailRangeStart->format('Y-m-d')], $detailIdentifiers));
        $periodCounts = [];
        foreach ($periodStatement->fetchAll() as $row) $periodCounts[(string) $row['identifier']] = (int) $row['period_likes'];
        $pendingStatementForIds = $pdo->prepare('SELECT identifier, COUNT(*) AS period_likes FROM pollyanna_likes '
            . 'WHERE created_at >= ? AND identifier IN (' . $detailPlaceholders . ') GROUP BY identifier');
        $pendingStatementForIds->execute(array_merge([$detailRangeStart->setTimezone($utc)->format('Y-m-d H:i:s.u')], $detailIdentifiers));
        foreach ($pendingStatementForIds->fetchAll() as $row) $periodCounts[(string) $row['identifier']] = ($periodCounts[(string) $row['identifier']] ?? 0) + (int) $row['period_likes'];
        foreach ($detailRows as &$detailRow) $detailRow['period_likes'] = $periodCounts[(string) $detailRow['identifier']] ?? 0;
        unset($detailRow);
    }

    if ($selectedDetailId !== '') {
        $selectedStatement = $pdo->prepare('SELECT COUNT(*) FROM (' . $identifierTotalsSql . ') AS selectable_identifiers WHERE identifier = ?');
        $selectedStatement->execute([$selectedDetailId]);
        if ((int) $selectedStatement->fetchColumn() === 0) $selectedDetailId = '';
    }
    if ($selectedDetailId === '' && $detailRows !== []) $selectedDetailId = (string) $detailRows[0]['identifier'];
    if ($selectedDetailId !== '') {
        $trendBuckets = [];
        if ($detailRangeStart instanceof DateTimeImmutable) {
            if ($detailUnit === 'day') {
                for ($cursor = $detailRangeStart; $cursor <= $today; $cursor = $cursor->modify('+1 day')) $trendBuckets[$cursor->format('Y-m-d')] = 0;
            } elseif ($detailUnit === 'month') {
                for ($cursor = $detailRangeStart->modify('first day of this month'); $cursor <= $today; $cursor = $cursor->modify('+1 month')) $trendBuckets[$cursor->format('Y-m')] = 0;
            } else {
                for ($year = (int) $detailRangeStart->format('Y'); $year <= (int) $today->format('Y'); $year++) $trendBuckets[(string) $year] = 0;
            }
        }
        $trendSql = 'SELECT period_type, period_start, like_count FROM pollyanna_period_aggregates WHERE identifier = ?';
        $trendParameters = [$selectedDetailId];
        if ($detailRangeStart instanceof DateTimeImmutable) {
            $trendSql .= ' AND period_start >= ?';
            $trendParameters[] = $detailRangeStart->format('Y-m-d');
        }
        $trendStatement = $pdo->prepare($trendSql . ' ORDER BY period_start');
        $trendStatement->execute($trendParameters);
        foreach ($trendStatement->fetchAll() as $row) {
            $periodType = (string) $row['period_type'];
            if ($detailUnit === 'day' && $periodType !== 'day') continue;
            if ($detailUnit === 'month' && $periodType === 'year') continue;
            $periodStart = (string) $row['period_start'];
            $bucket = $detailUnit === 'day' ? $periodStart : ($detailUnit === 'month' ? substr($periodStart, 0, 7) : substr($periodStart, 0, 4));
            $trendBuckets[$bucket] = ($trendBuckets[$bucket] ?? 0) + (int) $row['like_count'];
        }
        $pendingTrendSql = 'SELECT created_at FROM pollyanna_likes WHERE identifier = ?';
        $pendingTrendParameters = [$selectedDetailId];
        if ($detailRangeStart instanceof DateTimeImmutable) {
            $pendingTrendSql .= ' AND created_at >= ?';
            $pendingTrendParameters[] = $detailRangeStart->setTimezone($utc)->format('Y-m-d H:i:s.u');
        }
        $pendingTrendStatement = $pdo->prepare($pendingTrendSql);
        $pendingTrendStatement->execute($pendingTrendParameters);
        foreach ($pendingTrendStatement->fetchAll() as $row) {
            $localTime = (new DateTimeImmutable((string) $row['created_at'], $utc))->setTimezone($timezone);
            $bucket = $detailUnit === 'day' ? $localTime->format('Y-m-d') : ($detailUnit === 'month' ? $localTime->format('Y-m') : $localTime->format('Y'));
            $trendBuckets[$bucket] = ($trendBuckets[$bucket] ?? 0) + 1;
        }
        ksort($trendBuckets);
        $detailTrendValues = array_values($trendBuckets);
        foreach (array_keys($trendBuckets) as $bucket) {
            if ($detailUnit === 'day') {
                $date = new DateTimeImmutable($bucket);
                $detailTrendLabels[] = $date->format('n/j');
            } elseif ($detailUnit === 'month') {
                $date = DateTimeImmutable::createFromFormat('!Y-m', $bucket);
                $detailTrendLabels[] = $date ? ($language === 'ja' ? $date->format('Y/n') : $date->format('M Y')) : $bucket;
            } else {
                $detailTrendLabels[] = $bucket;
            }
        }
    }

    $aggregated = (int) $pdo->query('SELECT COALESCE(SUM(total_likes), 0) FROM pollyanna_lifetime_totals')->fetchColumn();
    $pending = (int) $pdo->query('SELECT COUNT(*) FROM pollyanna_likes')->fetchColumn();
    $total = $aggregated + $pending;
    $likeIds = (int) $pdo->query('SELECT COUNT(*) FROM (SELECT identifier FROM pollyanna_lifetime_totals UNION SELECT identifier FROM pollyanna_likes) AS ids')->fetchColumn();

    $daily = [];
    for ($day = $dailyStart; $day <= $today; $day = $day->modify('+1 day')) {
        $key = $day->format('Y-m-d');
        $daily[$key] = 0;
        $dailyLabels[] = $day->format('n/j');
    }
    $statement = $pdo->prepare("SELECT period_start, SUM(like_count) AS total FROM pollyanna_period_aggregates WHERE period_type = 'day' AND period_start >= :start GROUP BY period_start");
    $statement->execute([':start' => $dailyStart->format('Y-m-d')]);
    foreach ($statement->fetchAll() as $row) {
        if (array_key_exists($row['period_start'], $daily)) $daily[$row['period_start']] += (int) $row['total'];
    }

    $monthly = [];
    for ($month = $monthStart; $month <= $today; $month = $month->modify('+1 month')) {
        $key = $month->format('Y-m');
        $monthly[$key] = 0;
        $monthlyLabels[] = $language === 'ja' ? $month->format('n') . '月' : $month->format('M');
    }
    $statement = $pdo->prepare("SELECT period_start, like_count FROM pollyanna_period_aggregates WHERE period_type = 'day' AND period_start >= :start");
    $statement->execute([':start' => $monthStart->format('Y-m-d')]);
    foreach ($statement->fetchAll() as $row) {
        $key = substr((string) $row['period_start'], 0, 7);
        if (array_key_exists($key, $monthly)) $monthly[$key] += (int) $row['like_count'];
    }

    $hourly = array_fill(0, 24, 0);
    $statement = $pdo->prepare('SELECT hour_of_day, like_count FROM pollyanna_hourly_aggregates WHERE month_start = :month_start');
    $statement->execute([':month_start' => $today->format('Y-m-01')]);
    foreach ($statement->fetchAll() as $row) $hourly[(int) $row['hour_of_day']] += (int) $row['like_count'];

    $statement->execute([':month_start' => $selectedDetailMonth . '-01']);
    foreach ($statement->fetchAll() as $row) $detailHourlyValues[(int) $row['hour_of_day']] += (int) $row['like_count'];

    $pendingStatement = $pdo->prepare('SELECT created_at FROM pollyanna_likes WHERE created_at >= :start');
    $pendingStatement->execute([':start' => $monthStart->setTimezone($utc)->format('Y-m-d H:i:s.u')]);
    foreach ($pendingStatement->fetchAll() as $row) {
        $localTime = (new DateTimeImmutable((string) $row['created_at'], $utc))->setTimezone($timezone);
        $dateKey = $localTime->format('Y-m-d');
        $monthKey = $localTime->format('Y-m');
        if (array_key_exists($dateKey, $daily)) $daily[$dateKey]++;
        if (array_key_exists($monthKey, $monthly)) $monthly[$monthKey]++;
    }
    $dailyValues = array_values($daily);
    $monthlyValues = array_values($monthly);
    $hourlyValues = array_values($hourly);
    for ($hour = 0; $hour < 24; $hour++) $hourlyLabels[] = $language === 'ja' ? $hour . '時' : sprintf('%02d:00', $hour);
} catch (Throwable $error) {
    error_log('Pollyanna admin dashboard failed: ' . $error->getMessage());
    $dashboardError = (string) $ui['database_unavailable'];
}

$systemState = pollyanna_system_state();
$activeTab = $_GET['tab'] ?? 'dashboard';
if (!is_string($activeTab) || !in_array($activeTab, ['dashboard', 'details', 'settings'], true)) $activeTab = 'dashboard';
$retrievedAt = (new DateTimeImmutable('now', new DateTimeZone($settings['timezone'])))->format('Y-m-d H:i');
$dailyPeriod = $language === 'ja' ? $dailyStart->format('Y/n/j') . '〜' . $today->format('n/j') : $dailyStart->format('M j, Y') . ' – ' . $today->format('M j');
$monthlyPeriod = $language === 'ja' ? $monthStart->format('Y/n') . '〜' . $today->format('Y/n') : $monthStart->format('M Y') . ' – ' . $today->format('M Y');
$hourlyPeriod = $language === 'ja' ? $today->format('Y年n月') : $today->format('F Y');
$timezoneChangeState = pollyanna_timezone_change_state();
?>
<!doctype html>
<html lang="<?= admin_h($language) ?>" data-theme="<?= admin_h($theme) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Pollyanna — An Easy-to-Install Like Button System</title>
    <link rel="stylesheet" href="../assets/admin.css">
</head>
<body>
<header>
    <div class="top">
        <div class="brand-block"><div class="brand-line"><span class="heart" aria-hidden="true">♥</span><span class="brand">Pollyanna</span><span class="brand-separator">—</span><span class="product-description">An Easy-to-Install Like Button System</span></div></div>
        <div class="header-tools"><span class="badge">● <?= admin_h($systemState === 'maintenance' ? $ui['maintenance'] : $ui['online']) ?></span><form method="post"><input type="hidden" name="csrf_token" value="<?= admin_h($csrfToken) ?>"><input type="hidden" name="action" value="admin_logout"><button class="logout-button" type="submit"><?= admin_h($ui['logout']) ?></button></form></div>
    </div>
    <div class="notice"><?= admin_h($ui['local_only']) ?></div>
    <nav role="tablist" aria-label="<?= admin_h($ui['page_title']) ?>">
        <?php foreach (['dashboard', 'details', 'settings'] as $tab): ?><button id="tab-<?= $tab ?>" type="button" role="tab" data-admin-tab="<?= $tab ?>" aria-controls="panel-<?= $tab ?>" aria-selected="<?= $activeTab === $tab ? 'true' : 'false' ?>" tabindex="<?= $activeTab === $tab ? '0' : '-1' ?>"><?= admin_h($ui[$tab]) ?></button><?php endforeach ?>
    </nav>
</header>
<main>
    <section id="panel-dashboard" role="tabpanel" aria-labelledby="tab-dashboard" data-admin-panel="dashboard" <?= $activeTab === 'dashboard' ? '' : 'hidden' ?>>
        <div class="heading dashboard-heading"><div><h1><?= admin_h($ui['dashboard']) ?></h1><p class="muted"><?= admin_h($ui['dashboard_intro']) ?></p></div><aside class="system-info"><h2><?= admin_h($ui['system_information']) ?></h2><dl class="facts"><div><dt><?= admin_h($ui['version']) ?></dt><dd><?= admin_h($ui['development_version']) ?></dd></div><div><dt><?= admin_h($ui['system_status']) ?></dt><dd><?= admin_h($systemState === 'maintenance' ? $ui['maintenance'] : 'ON') ?></dd></div><div><dt><?= admin_h($ui['aggregation_timezone']) ?></dt><dd><?= admin_h($settings['timezone']) ?></dd></div><div><dt><?= admin_h($ui['retrieved_at']) ?></dt><dd><?= admin_h($retrievedAt) ?></dd></div><div><dt><?= admin_h($ui['admin_language']) ?></dt><dd><form class="language-form" method="get"><input type="hidden" name="tab" value="dashboard"><select data-language-select name="language" aria-label="<?= admin_h($ui['admin_language']) ?>"><?php foreach ($catalog as $code => $locale): ?><option value="<?= admin_h($code) ?>" <?= $language === $code ? 'selected' : '' ?>><?= admin_h($locale['language_name']) ?></option><?php endforeach ?></select></form></dd></div></dl><?php if ($languageSaveError !== ''): ?><p class="result" role="status"><?= admin_h($languageSaveError) ?></p><?php endif ?></aside></div>
        <?php if ($dashboardError !== ''): ?><div class="notice"><?= admin_h($dashboardError) ?></div><?php endif ?>
        <div class="cards"><div class="card"><div class="label"><?= admin_h($ui['total_likes']) ?></div><div class="number"><?= number_format($total) ?></div><div class="muted"><?= admin_h($ui['aggregated_and_pending']) ?></div></div><div class="card"><div class="label"><?= admin_h($ui['aggregated']) ?></div><div class="number"><?= number_format($aggregated) ?></div><div class="muted"><?= admin_h($ui['lifetime_total']) ?></div></div><div class="card"><div class="label"><?= admin_h($ui['pending']) ?></div><div class="number"><?= number_format($pending) ?></div><div class="muted"><?= admin_h($ui['awaiting_aggregation']) ?></div></div><div class="card"><div class="label"><?= admin_h($ui['like_ids']) ?></div><div class="number"><?= number_format($likeIds) ?></div><div class="muted"><?= admin_h($ui['including_aggregated_pending']) ?></div></div></div>
        <div class="trend-grid">
            <?php foreach ([[$ui['last_7_days'], $dailyPeriod, $ui['includes_aggregated_pending'], $dailyValues, $dailyLabels, '', 1], [$ui['last_12_months'], $monthlyPeriod, $ui['includes_aggregated_pending'], $monthlyValues, $monthlyLabels, 'monthly', 2], [$ui['current_month_hourly'], $hourlyPeriod, $ui['aggregated_data_only'], $hourlyValues, $hourlyLabels, 'hourly', 6]] as [$title, $period, $description, $values, $labels, $class, $labelEvery]): ?><section class="panel trend-panel"><h2><?= admin_h($title) ?></h2><div class="chart-meta"><div class="chart-period"><?= admin_h($period) ?></div><div class="chart-description"><?= admin_h($description) ?></div></div><?php $chart = admin_chart($values, $labels, $class, $labelEvery); echo $chart !== '' ? $chart : '<div class="empty">' . admin_h($ui['no_chart_data']) . '</div>'; ?></section><?php endforeach ?>
        </div>
    </section>
    <section id="panel-details" role="tabpanel" aria-labelledby="tab-details" data-admin-panel="details" <?= $activeTab === 'details' ? '' : 'hidden' ?>><div class="heading"><h1><?= admin_h($ui['details']) ?></h1><p class="muted"><?= admin_h($ui['details_intro']) ?></p></div><section class="panel trend-panel"><h2><?= admin_h($ui['hourly_by_month']) ?></h2><form class="period-filter" method="get"><input type="hidden" name="tab" value="details"><label for="details-month"><?= admin_h($ui['target_month']) ?></label><select id="details-month" name="month" data-detail-month><?php foreach ($availableDetailMonths as $monthOption): $monthDate = DateTimeImmutable::createFromFormat('!Y-m', $monthOption); ?><option value="<?= admin_h($monthOption) ?>" <?= $selectedDetailMonth === $monthOption ? 'selected' : '' ?>><?= admin_h($monthDate ? ($language === 'ja' ? $monthDate->format('Y年n月') : $monthDate->format('F Y')) : $monthOption) ?></option><?php endforeach ?></select><span class="muted"><?= admin_h(sprintf($ui['period_total'], number_format(array_sum($detailHourlyValues)))) ?></span></form><?php $detailsChart = admin_chart($detailHourlyValues, $hourlyLabels, 'hourly', 3); echo $detailsChart !== '' ? $detailsChart : '<div class="empty">' . admin_h($ui['no_chart_data']) . '</div>'; ?><?php if (array_sum($detailHourlyValues) > 0): ?><details class="data-details"><summary><?= admin_h($ui['hourly_breakdown']) ?></summary><div class="table-scroll"><table><thead><tr><th><?= admin_h($catalog[$language]['hour']) ?></th><th><?= admin_h($catalog[$language]['count']) ?></th><th><?= admin_h($ui['share']) ?></th></tr></thead><tbody><?php $detailTotal = array_sum($detailHourlyValues); foreach ($detailHourlyValues as $hour => $count): ?><tr><td><?= admin_h($language === 'ja' ? $hour . '時' : sprintf('%02d:00', $hour)) ?></td><td><?= number_format($count) ?></td><td><?= number_format(($count / $detailTotal) * 100, 1) ?>%</td></tr><?php endforeach ?></tbody></table></div></details><?php endif ?></section>
        <section class="panel id-details"><h2><?= admin_h($ui['details_by_id']) ?></h2><form class="detail-filters" method="get"><input type="hidden" name="tab" value="details"><input type="hidden" name="month" value="<?= admin_h($selectedDetailMonth) ?>"><label for="id-search"><?= admin_h($ui['id_search']) ?><input id="id-search" name="q" type="search" value="<?= admin_h($detailSearch) ?>" placeholder="<?= admin_h($ui['id_search_placeholder']) ?>"></label><label for="detail-period"><?= admin_h($ui['target_period']) ?><select id="detail-period" name="period"><?php foreach ($ui['periods'] as $periodCode => $periodLabel): ?><option value="<?= admin_h((string) $periodCode) ?>" <?= $detailPeriod === (string) $periodCode ? 'selected' : '' ?>><?= admin_h($periodLabel) ?></option><?php endforeach ?></select></label><label for="detail-unit"><?= admin_h($ui['display_unit']) ?><select id="detail-unit" name="unit"><?php foreach ($ui['units'] as $unitCode => $unitLabel): ?><option value="<?= admin_h($unitCode) ?>" <?= $detailUnit === $unitCode ? 'selected' : '' ?>><?= admin_h($unitLabel) ?></option><?php endforeach ?></select></label><button class="primary" type="submit"><?= admin_h($ui['apply_filter']) ?></button></form>
<?php if ($selectedDetailId !== ''): ?><section class="id-trend"><h3><?= admin_h(sprintf($ui['id_trend'], $selectedDetailId)) ?></h3><div class="trend-scroll"><?php $detailLabelEvery = max(1, (int) ceil(max(1, count($detailTrendValues)) / 12)); $idTrendChart = admin_chart($detailTrendValues, $detailTrendLabels, 'detail-series', $detailLabelEvery); echo $idTrendChart !== '' ? $idTrendChart : '<div class="empty">' . admin_h($ui['no_chart_data']) . '</div>'; ?></div><p class="field-help"><?= admin_h($ui['stored_granularity_note']) ?></p></section><?php else: ?><p class="muted"><?= admin_h($ui['select_id']) ?></p><?php endif ?>
<div class="table-scroll id-table"><table><thead><tr><th><?= admin_h($catalog[$language]['like_id']) ?></th><th><?= admin_h($catalog[$language]['lifetime_count']) ?></th><th><?= admin_h($ui['period_count']) ?></th><th><?= admin_h($ui['installation_url']) ?></th></tr></thead><tbody><?php $idLinkBase = ['tab' => 'details', 'month' => $selectedDetailMonth, 'q' => $detailSearch, 'period' => $detailPeriod, 'unit' => $detailUnit, 'page' => $detailPage]; foreach ($detailRows as $detailRow): $rowIdentifier = (string) $detailRow['identifier']; ?><tr class="<?= $selectedDetailId === $rowIdentifier ? 'is-selected' : '' ?>"><td><a href="?<?= admin_h(http_build_query($idLinkBase + ['id' => $rowIdentifier])) ?>"><?= admin_h($rowIdentifier) ?></a></td><td><?= number_format((int) $detailRow['total_likes']) ?></td><td><?= number_format((int) $detailRow['period_likes']) ?></td><td class="url-cell"><?php if ($detailRow['urls'] === []): ?><span class="muted"><?= admin_h($ui['url_unregistered']) ?></span><?php else: ?><?php foreach ($detailRow['urls'] as $installationUrl): ?><a href="<?= admin_h($installationUrl) ?>" target="_blank" rel="noopener noreferrer"><?= admin_h($installationUrl) ?></a><?php endforeach ?><?php endif ?></td></tr><?php endforeach ?></tbody></table></div><?php if ($detailRows === []): ?><p class="empty id-empty"><?= admin_h($ui['no_matching_ids']) ?></p><?php endif ?><?php if ($detailTotalPages > 1): ?><nav class="pagination" aria-label="<?= admin_h($ui['details_by_id']) ?>"><?php $baseDetailQuery = ['tab' => 'details', 'month' => $selectedDetailMonth, 'q' => $detailSearch, 'period' => $detailPeriod, 'unit' => $detailUnit]; if ($detailPage > 1): ?><a href="?<?= admin_h(http_build_query($baseDetailQuery + ['page' => $detailPage - 1])) ?>"><?= admin_h($ui['previous_page']) ?></a><?php endif ?><span><?= admin_h(sprintf($ui['page_status'], $detailPage, $detailTotalPages)) ?></span><?php if ($detailPage < $detailTotalPages): ?><a href="?<?= admin_h(http_build_query($baseDetailQuery + ['page' => $detailPage + 1])) ?>"><?= admin_h($ui['next_page']) ?></a><?php endif ?></nav><?php endif ?></section>
    </section>
    <section id="panel-settings" role="tabpanel" aria-labelledby="tab-settings" data-admin-panel="settings" <?= $activeTab === 'settings' ? '' : 'hidden' ?>>
        <div class="heading settings-heading"><div><h1><?= admin_h($ui['settings']) ?></h1><p class="muted"><?= admin_h($ui['settings_intro']) ?></p></div><aside class="timezone-quick"><div class="timezone-heading-row"><h2><?= admin_h($catalog[$language]['timezone']) ?></h2><p class="muted timezone-note"><?= admin_h($catalog[$language]['timezone_change_warning']) ?></p></div><form class="timezone-controls" data-timezone-form data-confirm-text="<?= admin_h($catalog[$language]['timezone_change_confirm']) ?>"><input type="hidden" name="csrf_token" value="<?= admin_h($csrfToken) ?>"><label class="visually-hidden" for="settings-timezone"><?= admin_h($catalog[$language]['timezone']) ?></label><select id="settings-timezone" name="timezone"><?php $selectedTimezone = $timezoneChangeState['new_timezone'] ?? $settings['timezone']; foreach (DateTimeZone::listIdentifiers() as $timezoneOption): ?><option value="<?= admin_h($timezoneOption) ?>" <?= $selectedTimezone === $timezoneOption ? 'selected' : '' ?>><?= admin_h($timezoneOption) ?></option><?php endforeach ?></select><button class="primary" type="submit" data-timezone-button><?= admin_h($timezoneChangeState === null ? $catalog[$language]['change_timezone'] : $catalog[$language]['resume_timezone_change']) ?></button></form><p class="result timezone-result" data-timezone-result role="status" aria-live="polite"><?= $timezoneChangeState === null ? '' : admin_h($catalog[$language]['timezone_change_interrupted']) ?></p></aside></div>
        <?php if ($settingsResult !== ''): ?><div class="notice settings-notice" role="status"><?= admin_h($settingsResult) ?></div><?php endif ?>
        <?php if ($settingsError !== ''): ?><div class="notice settings-error" role="alert"><?= admin_h($settingsError) ?></div><?php endif ?>
        <div class="setting-grid">
            <section class="panel settings-card">
                <h2><?= admin_h($ui['admin_operation_settings']) ?></h2>
                <form class="theme-form" method="get"><input type="hidden" name="tab" value="settings"><div class="field"><label for="admin-theme"><?= admin_h($ui['admin_theme']) ?><select id="admin-theme" data-theme-select name="theme"><?php foreach (pollyanna_admin_themes() as $themeCode): ?><option value="<?= admin_h($themeCode) ?>" <?= $theme === $themeCode ? 'selected' : '' ?>><?= admin_h($ui['themes'][$themeCode]) ?></option><?php endforeach ?></select></label><p class="field-help"><?= admin_h($language === 'ja' ? '色別テーマは白基調です。システムは端末のライト／ダーク設定に追従します。' : 'Color themes use a white base. System follows the device light/dark preference.') ?></p></div></form>
                <?php if ($themeSaveError !== ''): ?><p class="result" role="status"><?= admin_h($themeSaveError) ?></p><?php endif ?>
                <form method="post" data-settings-form="admin-operation" data-unsaved-text="<?= admin_h($ui['unsaved_changes']) ?>"><input type="hidden" name="csrf_token" value="<?= admin_h($csrfToken) ?>"><input type="hidden" name="action" value="save_admin_operation">
                    <div class="field"><label for="system-state"><?= admin_h($catalog[$language]['system_state']) ?><select id="system-state" name="system_state"><?php foreach ($catalog[$language]['system_states'] as $stateCode => $stateLabel): ?><option value="<?= admin_h($stateCode) ?>" <?= $formSystemState === $stateCode ? 'selected' : '' ?>><?= admin_h($stateLabel) ?></option><?php endforeach ?></select></label><p class="field-help"><?= admin_h($ui['maintenance_help']) ?></p></div>
                    <div class="field"><label for="aggregation-threshold"><?= admin_h($catalog[$language]['threshold']) ?><select id="aggregation-threshold" name="aggregation_start_threshold"><?php foreach ([1000, 2000, 4000, 6000, 8000, 10000] as $threshold): ?><option value="<?= $threshold ?>" <?= $formAggregationThreshold === $threshold ? 'selected' : '' ?>><?= number_format($threshold) ?></option><?php endforeach ?></select></label><p class="field-help"><?= admin_h($ui['threshold_help']) ?></p></div>
                    <div class="save-row"><button class="primary" type="submit"><?= admin_h($ui['save_admin_operation']) ?></button><span class="save-status" data-save-status><?= admin_h($ui['no_changes']) ?></span></div>
                </form>
            </section>
            <section class="panel settings-card">
                <h2><?= admin_h($ui['public_display_settings']) ?></h2>
                <form method="post" data-settings-form="public-display" data-unsaved-text="<?= admin_h($ui['unsaved_changes']) ?>"><input type="hidden" name="csrf_token" value="<?= admin_h($csrfToken) ?>"><input type="hidden" name="action" value="save_public_display">
                    <div class="field"><label for="widget-language"><?= admin_h($catalog[$language]['widget_language']) ?><select id="widget-language" name="widget_language" data-widget-language><?php foreach ($widgetCatalog as $code => $locale): ?><option value="<?= admin_h($code) ?>" <?= $formWidgetLanguage === $code ? 'selected' : '' ?>><?= admin_h($locale['language_name']) ?></option><?php endforeach ?></select></label></div>
                    <div class="field"><label for="success-message"><?= admin_h($catalog[$language]['click_message']) ?><select id="success-message" name="success_message" data-success-message><?php foreach ($widgetCatalog[$formWidgetLanguage]['success_messages'] as $messageKey => $message): ?><option value="<?= admin_h($messageKey) ?>" <?= $formSuccessMessage === $messageKey ? 'selected' : '' ?>><?= admin_h($message) ?></option><?php endforeach ?></select></label></div>
                    <div class="field"><label for="rate-limit"><?= admin_h($catalog[$language]['rate_limit']) ?><select id="rate-limit" name="likes_per_24_hours"><?php foreach ([1, 2, 3, 4, 5] as $limit): ?><option value="<?= $limit ?>" <?= $formRateLimit === $limit ? 'selected' : '' ?>><?= $limit ?></option><?php endforeach ?></select></label><p class="field-help"><?= admin_h($ui['limit_help']) ?></p></div>
                    <div class="field"><label for="show-count"><?= admin_h($catalog[$language]['public_count']) ?><select id="show-count" name="show_like_count" data-count-visibility><option value="off" <?= !$formShowCount ? 'selected' : '' ?>><?= admin_h($catalog[$language]['off']) ?></option><option value="on" <?= $formShowCount ? 'selected' : '' ?>><?= admin_h($catalog[$language]['on']) ?></option></select></label><p class="field-help"><?= admin_h($ui['count_visibility_help']) ?></p></div>
                    <div class="field"><label for="refresh-interval"><?= admin_h($catalog[$language]['count_refresh_interval']) ?><select id="refresh-interval" name="count_refresh_interval" data-count-dependent <?= !$formShowCount ? 'disabled' : '' ?>><?php foreach ($catalog[$language]['refresh_intervals'] as $seconds => $label): ?><option value="<?= admin_h((string) $seconds) ?>" <?= $formRefreshInterval === (int) $seconds ? 'selected' : '' ?>><?= admin_h($label) ?></option><?php endforeach ?></select></label></div>
                    <div class="field"><label for="count-color"><?= admin_h($ui['count_color']) ?><input id="count-color" name="like_count_color" type="color" value="<?= admin_h($formCountColor) ?>" data-count-dependent <?= !$formShowCount ? 'disabled' : '' ?>></label></div>
                    <div class="save-row"><button class="primary" type="submit"><?= admin_h($ui['save_public_display']) ?></button><span class="save-status" data-save-status><?= admin_h($ui['no_changes']) ?></span></div>
                </form>
            </section>
        </div>
    </section>
</main>
<footer class="site-footer"><div>Pollyanna — An Easy-to-Install Like Button System</div><div>Developed by Nikukiu-SYSTEM</div></footer>
<script id="widget-message-catalog" type="application/json"><?= json_encode(array_map(static fn (array $locale): array => $locale['success_messages'], $widgetCatalog), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script id="timezone-change-data" type="application/json"><?= json_encode(['inProgress' => $timezoneChangeState !== null, 'currentTimezone' => $settings['timezone'], 'labels' => ['aggregating' => $catalog[$language]['timezone_aggregating'], 'archiving' => $catalog[$language]['timezone_archiving'], 'done' => $catalog[$language]['timezone_change_done'], 'storageNote' => $catalog[$language]['timezone_archive_storage_note'], 'error' => $catalog[$language]['timezone_change_error']]], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="../assets/admin.js"></script>
</body>
</html>
