<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
if (!in_array($remoteAddress, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('The Pollyanna admin interface is currently available only from localhost.');
}

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
$dailyLabels = $monthlyLabels = $hourlyLabels = [];
$settings = ['timezone' => 'Asia/Tokyo'];
$today = new DateTimeImmutable('today', new DateTimeZone($settings['timezone']));
$dailyStart = $today->modify('-6 days');
$monthStart = $today->modify('first day of this month')->modify('-11 months');
try {
    $pdo = pollyanna_pdo();
    $settings = pollyanna_aggregation_settings();
    $timezone = new DateTimeZone($settings['timezone']);
    $utc = new DateTimeZone('UTC');
    $today = new DateTimeImmutable('today', $timezone);
    $dailyStart = $today->modify('-6 days');
    $monthStart = $today->modify('first day of this month')->modify('-11 months');
    $currentMonth = $today->format('Y-m');

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
        <div class="header-tools"><span class="badge">● <?= admin_h($systemState === 'maintenance' ? $ui['maintenance'] : $ui['online']) ?></span></div>
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
    <section id="panel-details" role="tabpanel" aria-labelledby="tab-details" data-admin-panel="details" <?= $activeTab === 'details' ? '' : 'hidden' ?>><div class="heading"><h1><?= admin_h($ui['details']) ?></h1><p class="muted"><?= admin_h($ui['details_intro']) ?></p></div><section class="panel trend-panel"><h2><?= admin_h($ui['current_month_hourly']) ?></h2><div class="period-filter"><label for="details-month"><?= admin_h($ui['target_month']) ?></label><select id="details-month" disabled><option><?= admin_h($language === 'ja' ? $today->format('Y年n月') : $today->format('F Y')) ?></option></select><span class="muted"><?= admin_h(sprintf($ui['period_total'], number_format(array_sum($hourlyValues)))) ?></span></div><?php $detailsChart = admin_chart($hourlyValues, $hourlyLabels, 'hourly', 3); echo $detailsChart !== '' ? $detailsChart : '<div class="empty">' . admin_h($ui['no_chart_data']) . '</div>'; ?></section><div class="panel"><p><?= admin_h($ui['details_pending']) ?></p></div></section>
    <section id="panel-settings" role="tabpanel" aria-labelledby="tab-settings" data-admin-panel="settings" <?= $activeTab === 'settings' ? '' : 'hidden' ?>><div class="heading settings-heading"><div><h1><?= admin_h($ui['settings']) ?></h1><p class="muted"><?= admin_h($ui['settings_intro']) ?></p></div><aside class="timezone-quick"><div class="timezone-heading-row"><h2><?= admin_h($catalog[$language]['timezone']) ?></h2><p class="muted timezone-note"><?= admin_h($catalog[$language]['timezone_change_warning']) ?></p></div><div class="timezone-controls"><label class="visually-hidden" for="settings-timezone"><?= admin_h($catalog[$language]['timezone']) ?></label><select id="settings-timezone" disabled><option><?= admin_h($settings['timezone']) ?></option></select><button class="primary" type="button" disabled><?= admin_h($catalog[$language]['change_timezone']) ?></button></div></aside></div><div class="setting-grid"><div class="panel"><h2><?= admin_h($ui['admin_theme']) ?></h2><form class="theme-form" method="get"><input type="hidden" name="tab" value="settings"><div class="field"><label for="admin-theme"><?= admin_h($ui['admin_theme']) ?><select id="admin-theme" data-theme-select name="theme"><?php foreach (pollyanna_admin_themes() as $themeCode): ?><option value="<?= admin_h($themeCode) ?>" <?= $theme === $themeCode ? 'selected' : '' ?>><?= admin_h($ui['themes'][$themeCode]) ?></option><?php endforeach ?></select></label></div></form><?php if ($themeSaveError !== ''): ?><p class="result" role="status"><?= admin_h($themeSaveError) ?></p><?php endif ?></div><div class="panel"><p><?= admin_h($ui['settings_pending']) ?></p></div></div></section>
</main>
<footer class="site-footer"><div>Pollyanna — An Easy-to-Install Like Button System</div><div>Developed by Nikukiu-SYSTEM</div></footer>
<script src="../assets/admin.js"></script>
</body>
</html>
