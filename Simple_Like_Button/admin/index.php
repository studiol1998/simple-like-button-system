<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/LikeService.php';
require_once dirname(__DIR__) . '/src/AggregationService.php';
require_once dirname(__DIR__) . '/src/TimezoneChangeService.php';
require_once dirname(__DIR__) . '/src/OriginPolicy.php';
require_once dirname(__DIR__) . '/src/InstallerState.php';
require_once dirname(__DIR__) . '/src/AccessProtectionService.php';

$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocalRequest = in_array($remoteAddress, ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true);

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
session_set_cookie_params(['httponly' => true, 'secure' => $isHttps, 'samesite' => 'Strict']);
session_start();

$catalog = pollyanna_admin_catalog();
$savedLanguage = pollyanna_admin_language();
$requestedLanguage = $_GET['language'] ?? $_SESSION['pollyanna_login_language'] ?? $savedLanguage;
$language = is_string($requestedLanguage) && isset($catalog[$requestedLanguage]) ? $requestedLanguage : $savedLanguage;
$languageSaveError = '';
$ui = $catalog[$language]['admin_ui'] ?? null;
if (!is_array($ui)) {
    throw new RuntimeException('Pollyanna admin interface translations are missing.');
}
$installerCompletionPath = dirname(__DIR__) . '/config/install_complete.json';
$installerConfigPath = dirname(__DIR__) . '/config/config.php';
$installerCredentialsPath = dirname(__DIR__) . '/config/admin_credentials.json';
$installerDirectory = dirname(__DIR__) . '/install';
$installerLockActive = PollyannaInstallerState::shouldLockAdmin(
    $installerDirectory,
    $installerConfigPath,
    $installerCredentialsPath,
    $installerCompletionPath
);
if ($installerLockActive) {
    http_response_code(503);
    $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ?>
    <!doctype html><html lang="<?= $escape($language) ?>" data-theme="green"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pollyanna — <?= $escape($ui['installer_removal_required']) ?></title><link rel="stylesheet" href="../assets/admin.css"></head><body class="auth-page"><main class="auth-main"><section class="panel auth-card"><div class="brand-line"><span class="heart" aria-hidden="true">♥</span><span class="brand">Pollyanna</span></div><h1><?= $escape($ui['installer_removal_required']) ?></h1><div class="notice settings-error" role="alert"><?= $escape($ui['installer_removal_required_help']) ?></div><p class="muted"><?= $escape($ui['installer_removal_path']) ?></p></section></main></body></html>
    <?php
    exit;
}
$savedTheme = pollyanna_admin_theme();
$requestedTheme = $_GET['theme'] ?? $_SESSION['pollyanna_login_theme'] ?? $savedTheme;
$theme = is_string($requestedTheme) && in_array($requestedTheme, pollyanna_admin_themes(), true) ? $requestedTheme : $savedTheme;
$themeSaveError = '';
header('Cache-Control: no-store');

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
$isAuthenticated = ($_SESSION['pollyanna_admin_authenticated'] ?? false) === true;
if ($isAuthenticated && ($adminPasswordHash === null || !is_string($_SESSION['pollyanna_admin_credential_stamp'] ?? null)
    || !hash_equals(hash('sha256', $adminPasswordHash), $_SESSION['pollyanna_admin_credential_stamp']))) {
    unset($_SESSION['pollyanna_admin_authenticated'], $_SESSION['pollyanna_admin_credential_stamp']);
    session_regenerate_id(true);
    $_SESSION['pollyanna_csrf_token'] = $csrfToken = bin2hex(random_bytes(32));
    $isAuthenticated = false;
}
if (!$isAuthenticated) {
    $_SESSION['pollyanna_login_language'] = $language;
    $_SESSION['pollyanna_login_theme'] = $theme;
}
$authAction = $_POST['action'] ?? '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && in_array($authAction, ['admin_setup', 'admin_login', 'admin_logout'], true)) {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($postedToken) || !hash_equals($csrfToken, $postedToken)) {
        http_response_code(403);
        $authenticationError = (string) ($catalog[$language]['csrf_error'] ?? $ui['authentication_error']);
    } elseif ($authAction === 'admin_logout') {
        unset($_SESSION['pollyanna_admin_authenticated'], $_SESSION['pollyanna_admin_credential_stamp']);
        session_regenerate_id(true);
        header('Location: ./');
        exit;
    } elseif ($authAction === 'admin_setup' && $adminPasswordHash === null && $isLocalRequest && !file_exists($installerCredentialsPath)) {
        $password = $_POST['password'] ?? '';
        $confirmation = $_POST['password_confirmation'] ?? '';
        if (!is_string($password) || !is_string($confirmation) || $password !== $confirmation) {
            $authenticationError = (string) $ui['password_mismatch'];
        } else {
            try {
                pollyanna_save_admin_password($password);
                session_regenerate_id(true);
                $_SESSION['pollyanna_admin_authenticated'] = true;
                $_SESSION['pollyanna_admin_credential_stamp'] = hash('sha256', (string) pollyanna_admin_password_hash());
                unset($_SESSION['pollyanna_login_language'], $_SESSION['pollyanna_login_theme']);
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
            if (is_string($password) && strlen($password) <= 1024 && password_verify($password, $adminPasswordHash)) {
                session_regenerate_id(true);
                $_SESSION['pollyanna_admin_authenticated'] = true;
                $_SESSION['pollyanna_admin_credential_stamp'] = hash('sha256', (string) pollyanna_admin_password_hash());
                unset($_SESSION['pollyanna_login_language'], $_SESSION['pollyanna_login_theme']);
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
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && !in_array($authAction, ['admin_setup', 'admin_login', 'admin_logout'], true)) {
        http_response_code(403);
        $authenticationError = $ui['authentication_required'];
    }
    if (($_SESSION['pollyanna_password_changed'] ?? false) === true) {
        $authenticationError = $ui['password_changed'];
        unset($_SESSION['pollyanna_password_changed']);
    }
    $setupAvailable = $adminPasswordHash === null && $isLocalRequest && !file_exists($installerCredentialsPath);
    if ($adminPasswordHash === null && !$isLocalRequest) http_response_code(403);
    $authTitle = $setupAvailable ? $ui['admin_setup'] : $ui['admin_login'];
    $authDescription = $setupAvailable ? $ui['password_requirement'] : ($adminPasswordHash === null ? $ui['setup_local_only'] : '');
    $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    ?>
    <!doctype html><html lang="<?= $escape($language) ?>" data-theme="<?= $escape($theme) ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Pollyanna — <?= $escape($authTitle) ?></title><link rel="stylesheet" href="../assets/admin.css"></head><body class="auth-page"><main class="auth-main"><section class="panel auth-card"><div class="brand-line"><span class="heart" aria-hidden="true">♥</span><span class="brand">Pollyanna</span></div><h1><?= $escape($authTitle) ?></h1><?php if ($authDescription !== ''): ?><p class="muted"><?= $escape($authDescription) ?></p><?php endif ?><?php if ($authenticationError !== ''): ?><div class="notice settings-error" role="alert"><?= $escape($authenticationError) ?></div><?php endif ?><?php if ($adminPasswordHash !== null || $setupAvailable): ?><form method="post" class="auth-form" data-password-rule="<?= $escape($ui['password_requirement']) ?>"><input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="<?= $setupAvailable ? 'admin_setup' : 'admin_login' ?>"><label for="admin-password"><?= $escape($ui['password']) ?><input id="admin-password" name="password" type="password" <?= $setupAvailable ? 'data-new-admin-password' : '' ?> maxlength="1024" required autocomplete="<?= $setupAvailable ? 'new-password' : 'current-password' ?>"></label><?php if ($setupAvailable): ?><label for="admin-password-confirmation"><?= $escape($ui['password_confirmation']) ?><input id="admin-password-confirmation" name="password_confirmation" type="password" data-new-admin-password maxlength="72" required autocomplete="new-password"></label><?php endif ?><label class="password-toggle"><input type="checkbox" data-password-toggle> <?= $escape($ui['show_password']) ?></label><button class="primary" type="submit"><?= $escape($setupAvailable ? $ui['create_password'] : $ui['login']) ?></button></form><?php endif ?></section></main><script src="../assets/admin.js"></script></body></html>
    <?php
    exit;
}
$settingsResult = isset($_SESSION['pollyanna_settings_result']) && is_string($_SESSION['pollyanna_settings_result'])
    ? $_SESSION['pollyanna_settings_result']
    : '';
unset($_SESSION['pollyanna_settings_result']);
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && ($_GET['view'] ?? '') === 'widget-test') {
    define('POLLYANNA_AUTHENTICATED_WIDGET_TEST', true);
    require __DIR__ . '/widget-test-view.php';
    exit;
}

$settingsError = '';
$accessSettings = AccessProtectionService::defaults();
$accessError = '';
try { $accessSettings = pollyanna_access_settings(); } catch (Throwable) {
    $accessError = $language === 'ja' ? '集中アクセス検知設定を読み込めません。' : 'Could not read access protection settings.';
}
$aggregationResult = isset($_SESSION['pollyanna_aggregation_result']) && is_string($_SESSION['pollyanna_aggregation_result'])
    ? $_SESSION['pollyanna_aggregation_result']
    : '';
$aggregationError = isset($_SESSION['pollyanna_aggregation_error']) && is_string($_SESSION['pollyanna_aggregation_error'])
    ? $_SESSION['pollyanna_aggregation_error']
    : '';
unset($_SESSION['pollyanna_aggregation_result'], $_SESSION['pollyanna_aggregation_error']);
$skipAccessAggregation = ($_SESSION['pollyanna_skip_access_aggregation'] ?? false) === true;
unset($_SESSION['pollyanna_skip_access_aggregation']);

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
$buttonSymbolPresets = pollyanna_button_symbol_presets();
$buttonPresetLabels = $language === 'ja'
    ? ['heart' => 'ハート', 'diamond' => 'ダイヤ', 'spade' => 'スペード', 'club' => 'クラブ', 'star' => '星', 'music' => '音符']
    : ['heart' => 'Heart', 'diamond' => 'Diamond', 'spade' => 'Spade', 'club' => 'Club', 'star' => 'Star', 'music' => 'Music'];
$buttonDisplaySettings = pollyanna_button_display_settings();
$formButtonSymbolPreset = $buttonDisplaySettings['preset'];
$formButtonUnlikedColor = $buttonDisplaySettings['unliked_color'];
$formButtonLikedColor = $buttonDisplaySettings['liked_color'];
$originConfig = pollyanna_config();
$formSiteOrigin = (string) ($originConfig['site_origin'] ?? '');
$formBaseDomain = (string) ($originConfig['site_base_domain'] ?? '');
$formBaseSubdomains = ($originConfig['site_base_domain_include_subdomains'] ?? false) === true;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    $action = $_POST['action'] ?? '';
    if (!is_string($postedToken) || !hash_equals($csrfToken, $postedToken)) {
        http_response_code(403);
        $settingsError = (string) ($catalog[$language]['csrf_error'] ?? $ui['settings_save_error']);
    } elseif (in_array($action, ['save_admin_language', 'save_admin_theme'], true)) {
        try {
            if ($action === 'save_admin_language') {
                $value = $_POST['language'] ?? null;
                if (!is_string($value)) throw new InvalidArgumentException('Invalid language.');
                pollyanna_save_admin_language($value);
            } else {
                $value = $_POST['theme'] ?? null;
                if (!is_string($value)) throw new InvalidArgumentException('Invalid theme.');
                pollyanna_save_admin_theme($value);
            }
            header('Location: ?tab=' . ($action === 'save_admin_language' ? 'dashboard' : 'admin-operation'), true, 303);
            exit;
        } catch (Throwable) {
            http_response_code(422);
            $settingsError = $ui['settings_save_error'];
        }
    } elseif ($action === 'change_admin_password') {
        $now = time();
        $window = $_SESSION['pollyanna_password_change_window'] ?? 0;
        if (!is_int($window) || $now - $window >= 60) {
            $_SESSION['pollyanna_password_change_window'] = $now;
            $_SESSION['pollyanna_password_change_attempts'] = 0;
        }
        if (($_SESSION['pollyanna_password_change_attempts'] ?? 0) >= 5) {
            http_response_code(429);
            $settingsError = $ui['password_change_limited'];
        } else {
            $_SESSION['pollyanna_password_change_attempts'] = (int) ($_SESSION['pollyanna_password_change_attempts'] ?? 0) + 1;
            $current = $_POST['current_password'] ?? null;
            $new = $_POST['new_password'] ?? null;
            $confirmation = $_POST['new_password_confirmation'] ?? null;
            if (!is_string($current) || strlen($current) > 1024 || !password_verify($current, (string) $adminPasswordHash)) {
                http_response_code(422);
                $settingsError = $ui['current_password_invalid'];
            } elseif (!is_string($new) || !AdminPasswordPolicy::isValid($new)) {
                http_response_code(422);
                $settingsError = $ui['password_requirement'];
            } elseif (!is_string($confirmation) || $new !== $confirmation) {
                http_response_code(422);
                $settingsError = $ui['password_mismatch'];
            } else {
                try {
                    pollyanna_save_admin_password($new, $current, $adminPasswordHash);
                    $_SESSION = [];
                    session_regenerate_id(true);
                    $_SESSION['pollyanna_password_changed'] = true;
                    $_SESSION['pollyanna_csrf_token'] = bin2hex(random_bytes(32));
                    header('Location: ./', true, 303);
                    exit;
                } catch (Throwable) {
                    http_response_code(422);
                    $settingsError = $ui['password_change_failed'];
                }
            }
        }
    } elseif (in_array($action, ['save_access_protection', 'release_access_block'], true)) {
        try {
            $accessService = new AccessProtectionService(pollyanna_pdo());
            if (!$accessService->available()) throw new RuntimeException('Migration required.');
            if ($action === 'save_access_protection') {
                $requestedAccess = ['enabled' => ($_POST['access_enabled'] ?? '') === 'on',
                    'auto_block' => ($_POST['access_auto_block'] ?? '') === 'on'];
                foreach (['state', 'like'] as $accessApi) {
                    $requestedAccess[$accessApi] = [
                        'window' => filter_var($_POST['access_' . $accessApi . '_window'] ?? null, FILTER_VALIDATE_INT),
                        'threshold' => filter_var($_POST['access_' . $accessApi . '_threshold'] ?? null, FILTER_VALIDATE_INT)];
                }
                foreach (['access_enabled', 'access_auto_block'] as $switch) {
                    if ($switch === 'access_auto_block' && !$requestedAccess['enabled'] && !isset($_POST[$switch])) continue;
                    if (!in_array($_POST[$switch] ?? null, ['on', 'off'], true)) throw new InvalidArgumentException('Invalid switch.');
                }
                $accessSettings = AccessProtectionService::validate($requestedAccess);
                $accessService->overview($accessSettings);
                pollyanna_save_access_settings($accessSettings);
                $_SESSION['pollyanna_settings_result'] = $language === 'ja' ? '集中アクセス検知設定を保存しました。' : 'Access protection settings saved.';
            } else {
                $blockId = filter_var($_POST['access_block_id'] ?? null, FILTER_VALIDATE_INT);
                if (!is_int($blockId) || $blockId < 1 || !$accessService->release($blockId, $accessSettings)) {
                    throw new RuntimeException('Block not found or expired.');
                }
                $_SESSION['pollyanna_settings_result'] = $language === 'ja' ? 'ブロックを解除しました。' : 'Block released.';
            }
            header('Location: ?tab=access-protection'); exit;
        } catch (Throwable) {
            http_response_code(422);
            $settingsError = $language === 'ja' ? '保存・解除に失敗しました。入力値、DB更新、ブロックの有効期限を確認してください。' : 'Save or release failed. Check values, migration and block expiry.';
        }
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
    } elseif ($action === 'manual_aggregate') {
        try {
            $aggregationSettings = pollyanna_aggregation_settings();
            $pdo = pollyanna_pdo();
            $processed = (new AggregationService($pdo))->aggregateBatch(
                $aggregationSettings['aggregation_batch_size'],
                $aggregationSettings['timezone']
            );
            $remaining = (new LikeService($pdo))->pendingCount();
            $_SESSION['pollyanna_aggregation_result'] = $processed > 0
                ? sprintf($ui['manual_aggregation_processed'], $processed, $remaining)
                : ($remaining > 0 ? $ui['manual_aggregation_busy'] : $ui['manual_aggregation_empty']);
        } catch (Throwable $error) {
            error_log('Pollyanna manual aggregation failed: ' . $error->getMessage());
            $_SESSION['pollyanna_aggregation_error'] = (string) $ui['manual_aggregation_error'];
        }
        $_SESSION['pollyanna_skip_access_aggregation'] = true;
        header('Location: ?tab=dashboard');
        exit;
    } elseif ($action === 'save_origin_settings') {
        try {
            $originInput = is_string($_POST['site_origin'] ?? null) ? trim($_POST['site_origin']) : '';
            $baseDomainInput = is_string($_POST['site_base_domain'] ?? null) ? trim($_POST['site_base_domain']) : '';
            $include = ($_POST['site_base_domain_include_subdomains'] ?? '') === '1';
            $siteSettings = PollyannaOrigin::normalizeSiteSettings($originInput, $baseDomainInput, $include);
            $origin = $siteSettings['origin'];
            $baseDomain = $siteSettings['base_domain'];
            $include = $siteSettings['include_subdomains'];
            pollyanna_save_config_values(['site_origin' => $origin->serialize(), 'site_base_domain' => $baseDomain, 'site_base_domain_include_subdomains' => $include]);
            $_SESSION['pollyanna_settings_result'] = $language === 'ja' ? '本体Origin設定を保存しました。' : 'Primary origin settings saved.';
            header('Location: ?tab=admin-operation'); exit;
        } catch (Throwable $error) {
            error_log('Pollyanna origin settings save failed: ' . $error->getMessage());
            $settingsError = $language === 'ja' ? 'Originと基準ドメインの形式、および両者の対応を確認してください。基準ドメインはOriginのhost自身か、その親ドメインにしてください。' : 'Check the origin, base domain, and their relationship. The base domain must be the origin host itself or one of its parent domains.';
        }
    } elseif ($action === 'add_allowed_origin') {
        try {
            $origin = PollyannaOrigin::parse(is_string($_POST['allowed_origin'] ?? null) ? trim($_POST['allowed_origin']) : '');
            $include = ($_POST['include_subdomains'] ?? '') === '1';
            if ($origin->isLocalOrIp()) $include = false;
            $pdo = pollyanna_pdo(); pollyanna_ensure_allowed_origins_table($pdo);
            $statement = $pdo->prepare('INSERT INTO pollyanna_allowed_origins (scheme, host, port, include_subdomains, created_at) VALUES (:scheme, :host, :port, :include_subdomains, UTC_TIMESTAMP(6))');
            $statement->execute(['scheme' => $origin->scheme, 'host' => $origin->host, 'port' => $origin->port, 'include_subdomains' => $include ? 1 : 0]);
            $_SESSION['pollyanna_settings_result'] = $language === 'ja' ? '外部Originを追加しました。' : 'External origin added.';
            header('Location: ?tab=admin-operation'); exit;
        } catch (Throwable $error) {
            error_log('Pollyanna allowed origin add failed: ' . $error->getMessage());
            $settingsError = $language === 'ja' ? '外部Originを追加できませんでした。形式または重複を確認してください。' : 'Could not add the external origin. Check its format and duplicates.';
        }
    } elseif ($action === 'delete_allowed_origin') {
        try {
            $id = filter_var($_POST['allowed_origin_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if (!is_int($id)) throw new InvalidArgumentException('Invalid ID.');
            $statement = pollyanna_pdo()->prepare('DELETE FROM pollyanna_allowed_origins WHERE id = :id');
            $statement->execute(['id' => $id]);
            $_SESSION['pollyanna_settings_result'] = $statement->rowCount() === 1
                ? ($language === 'ja' ? '外部Originを削除しました。' : 'External origin deleted.')
                : ($language === 'ja' ? '対象のOriginはすでに存在しないか、削除済みです。' : 'The origin no longer exists or has already been deleted.');
            header('Location: ?tab=admin-operation'); exit;
        } catch (Throwable $error) {
            error_log('Pollyanna allowed origin delete failed: ' . $error->getMessage());
            $settingsError = (string) $ui['settings_save_error'];
        }
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
                header('Location: ?tab=admin-operation');
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
        $requestedSymbolPreset = $_POST['button_symbol_preset'] ?? '';
        $requestedUnlikedColor = is_string($_POST['button_unliked_color'] ?? null) ? strtolower($_POST['button_unliked_color']) : '';
        $requestedLikedColor = is_string($_POST['button_liked_color'] ?? null) ? strtolower($_POST['button_liked_color']) : '';
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
            && preg_match('/\A#[0-9a-fA-F]{6}\z/', $requestedColor) === 1
            && is_string($requestedSymbolPreset)
            && array_key_exists($requestedSymbolPreset, $buttonSymbolPresets)
            && in_array($requestedUnlikedColor, ['#666666', '#cccccc'], true)
            && preg_match('/\A#[0-9a-f]{6}\z/', $requestedLikedColor) === 1;
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
            $formButtonSymbolPreset = $requestedSymbolPreset;
            $formButtonUnlikedColor = $requestedUnlikedColor;
            $formButtonLikedColor = $requestedLikedColor;
            try {
                pollyanna_save_settings_group(
                    ['widget_settings.json', 'rate_limit_settings.json', 'public_count_settings.json', 'count_refresh_settings.json', 'public_count_color_settings.json', 'button_display_settings.json'],
                    static function () use ($requestedLanguage, $requestedMessage, $requestedLimit, $requestedVisibility, $requestedInterval, $requestedColor, $requestedSymbolPreset, $requestedUnlikedColor, $requestedLikedColor): void {
                        pollyanna_save_widget_settings($requestedLanguage, $requestedMessage);
                        pollyanna_save_rate_limit($requestedLimit);
                        pollyanna_save_count_display($requestedVisibility === 'on');
                        pollyanna_save_count_refresh_interval($requestedInterval);
                        pollyanna_save_count_display_color($requestedColor);
                        pollyanna_save_button_display_settings($requestedSymbolPreset, $requestedUnlikedColor, $requestedLikedColor);
                    }
                );
                $_SESSION['pollyanna_settings_result'] = (string) $ui['public_display_saved'];
                header('Location: ?tab=public-display');
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

function admin_access_datetime(?string $utc, string $timezone): string
{
    if ($utc === null) return '—';
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone($timezone))->format('Y-m-d H:i:s T');
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
if ($selectedDetailId !== '' && !pollyanna_identifier_is_valid($selectedDetailId)) $selectedDetailId = '';
$detailTrendValues = $detailTrendLabels = [];
$detailSearch = is_string($_GET['q'] ?? null) ? trim((string) $_GET['q']) : '';
$detailSearchCharacters = preg_split('//u', $detailSearch, -1, PREG_SPLIT_NO_EMPTY);
if ($detailSearchCharacters === false) {
    $detailSearch = '';
} elseif (count($detailSearchCharacters) > 100) {
    $detailSearch = implode('', array_slice($detailSearchCharacters, 0, 100));
}
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
$settings = ['timezone' => 'Asia/Tokyo', 'aggregation_batch_size' => 500];
$today = new DateTimeImmutable('today', new DateTimeZone($settings['timezone']));
$dailyStart = $today->modify('-6 days');
$monthStart = $today->modify('first day of this month')->modify('-11 months');
try {
    $pdo = pollyanna_pdo();
    pollyanna_ensure_identifier_urls_table($pdo);
    $settings = pollyanna_aggregation_settings();
    if (!$skipAccessAggregation && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && pollyanna_timezone_change_state() === null) {
        try {
            $processedOnAccess = (new AggregationService($pdo))->aggregateBatch(
                $settings['aggregation_batch_size'],
                $settings['timezone']
            );
            $remainingAfterAccess = (new LikeService($pdo))->pendingCount();
            if ($processedOnAccess > 0) {
                $aggregationResult = sprintf($ui['automatic_aggregation_processed'], $processedOnAccess, $remainingAfterAccess);
            } elseif ($remainingAfterAccess > 0) {
                $aggregationError = (string) $ui['automatic_aggregation_busy'];
            }
        } catch (Throwable $error) {
            error_log('Pollyanna automatic aggregation on admin access failed: ' . $error->getMessage());
            $aggregationError = (string) $ui['automatic_aggregation_error'];
        }
    }
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
    $statement = $pdo->prepare("SELECT period_start, like_count FROM pollyanna_period_aggregates WHERE period_type IN ('day', 'month') AND period_start >= :start");
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
$allowedOrigins = [];
try { $allowedOrigins = pollyanna_allowed_origins(); } catch (Throwable $error) { error_log('Pollyanna allowed origin list failed: ' . $error->getMessage()); }
$activeTab = $_GET['tab'] ?? 'dashboard';
if ($activeTab === 'settings') $activeTab = 'admin-operation';
if (!is_string($activeTab) || !in_array($activeTab, ['dashboard', 'details', 'admin-operation', 'public-display', 'access-protection'], true)) $activeTab = 'dashboard';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $settingsError !== '') {
    $activeTab = ($action ?? '') === 'save_admin_language' ? 'dashboard' : (in_array($action ?? '', ['save_access_protection', 'release_access_block'], true) ? 'access-protection' : (($action ?? '') === 'save_public_display' ? 'public-display' : 'admin-operation'));
}
$retrievedAt = (new DateTimeImmutable('now', new DateTimeZone($settings['timezone'])))->format('Y-m-d H:i');
$dailyPeriod = $language === 'ja' ? $dailyStart->format('Y/n/j') . '〜' . $today->format('n/j') : $dailyStart->format('M j, Y') . ' – ' . $today->format('M j');
$monthlyPeriod = $language === 'ja' ? $monthStart->format('Y/n') . '〜' . $today->format('Y/n') : $monthStart->format('M Y') . ' – ' . $today->format('M Y');
$hourlyPeriod = $language === 'ja' ? $today->format('Y年n月') : $today->format('F Y');
$timezoneChangeState = pollyanna_timezone_change_state();
$accessPageSize = filter_var($_GET['access_size'] ?? $_COOKIE['pollyanna_access_history_size'] ?? 20, FILTER_VALIDATE_INT);
$accessPageSize = in_array($accessPageSize, [20, 50, 80, 100], true) ? $accessPageSize : 20;
$accessOverview = ['blocks' => [], 'history' => [], 'total' => 0, 'page' => 1, 'recent' => 0, 'page_size' => $accessPageSize, 'pages' => 1];
try {
    $accessService = new AccessProtectionService(pollyanna_pdo());
    if (!$accessService->available()) throw new RuntimeException('Migration required.');
    $historyPage = filter_var($_GET['access_page'] ?? 1, FILTER_VALIDATE_INT);
    $accessOverview = $accessService->overview($accessSettings, is_int($historyPage) ? $historyPage : 1, $accessPageSize);
} catch (Throwable) {
    $accessError = $language === 'ja' ? '集中アクセス検知を利用できません。DB接続と集中アクセス検知用テーブルの存在を確認してください。新規導入が途中の場合は、既存データを保持してインストーラーの復旧案内を確認してください。' : 'Access protection unavailable. Check database connectivity and the required access protection tables. If a new installation is incomplete, preserve existing data and consult the installer recovery instructions.';
}
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
    <p><a href="?view=widget-test"><?= admin_h($ui['widget_test_title']) ?></a></p>
    <nav role="tablist" aria-label="<?= admin_h($ui['page_title']) ?>">
        <?php foreach (['dashboard' => 'dashboard', 'details' => 'details', 'admin-operation' => 'admin_operation_settings', 'public-display' => 'public_display_settings', 'access-protection' => 'access_protection'] as $tab => $labelKey): ?><button id="tab-<?= $tab ?>" type="button" role="tab" data-admin-tab="<?= $tab ?>" aria-controls="panel-<?= $tab ?>" aria-selected="<?= $activeTab === $tab ? 'true' : 'false' ?>" tabindex="<?= $activeTab === $tab ? '0' : '-1' ?>"><?= admin_h($ui[$labelKey]) ?></button><?php endforeach ?>
    </nav>
</header>
<main>
    <section id="panel-dashboard" role="tabpanel" aria-labelledby="tab-dashboard" data-admin-panel="dashboard" <?= $activeTab === 'dashboard' ? '' : 'hidden' ?>>
        <div class="heading dashboard-heading"><div><h1><?= admin_h($ui['dashboard']) ?></h1><p class="muted"><?= admin_h($ui['dashboard_intro']) ?></p></div><aside class="system-info"><h2><?= admin_h($ui['system_information']) ?></h2><dl class="facts"><div><dt><?= admin_h($ui['version']) ?></dt><dd><?= admin_h($ui['development_version']) ?></dd></div><div><dt><?= admin_h($ui['system_status']) ?></dt><dd><?= admin_h($systemState === 'maintenance' ? $ui['maintenance'] : 'ON') ?></dd></div><div><dt><?= admin_h($ui['aggregation_timezone']) ?></dt><dd><?= admin_h($settings['timezone']) ?></dd></div><div><dt><?= admin_h($ui['retrieved_at']) ?></dt><dd><?= admin_h($retrievedAt) ?></dd></div><div><dt><?= admin_h($ui['admin_language']) ?></dt><dd><form class="language-form" method="post"><input type="hidden" name="action" value="save_admin_language"><input type="hidden" name="csrf_token" value="<?= admin_h($csrfToken) ?>"><input type="hidden" name="tab" value="dashboard"><select data-language-select name="language" aria-label="<?= admin_h($ui['admin_language']) ?>"><?php foreach ($catalog as $code => $locale): ?><option value="<?= admin_h($code) ?>" <?= $language === $code ? 'selected' : '' ?>><?= admin_h($locale['language_name']) ?></option><?php endforeach ?></select></form></dd></div></dl><?php if ($languageSaveError !== ''): ?><p class="result" role="status"><?= admin_h($languageSaveError) ?></p><?php endif ?></aside></div>
        <?php if ($dashboardError !== ''): ?><div class="notice"><?= admin_h($dashboardError) ?></div><?php endif ?>
        <div class="cards"><div class="card"><div class="label"><?= admin_h($ui['total_likes']) ?></div><div class="number"><?= number_format($total) ?></div><div class="muted"><?= admin_h($ui['aggregated_and_pending']) ?></div></div><div class="card"><div class="label"><?= admin_h($ui['aggregated']) ?></div><div class="number"><?= number_format($aggregated) ?></div><div class="muted"><?= admin_h($ui['lifetime_total']) ?></div></div><div class="card"><div class="label"><?= admin_h($ui['pending']) ?></div><div class="number"><?= number_format($pending) ?></div><div class="muted"><?= admin_h($ui['awaiting_aggregation']) ?></div></div><div class="card"><div class="label"><?= admin_h($ui['like_ids']) ?></div><div class="number"><?= number_format($likeIds) ?></div><div class="muted"><?= admin_h($ui['including_aggregated_pending']) ?></div></div></div>
        <section class="aggregation-actions" aria-labelledby="aggregation-actions-title"><div><h2 id="aggregation-actions-title"><?= admin_h($ui['aggregation_actions']) ?></h2><p class="muted"><?= admin_h(sprintf($ui['aggregation_access_help'], number_format((int) $settings['aggregation_batch_size']))) ?></p></div><form method="post"><input type="hidden" name="csrf_token" value="<?= admin_h($csrfToken) ?>"><input type="hidden" name="action" value="manual_aggregate"><button class="primary" type="submit"><?= admin_h($ui['manual_aggregation']) ?></button></form></section>
        <?php if ($aggregationResult !== ''): ?><div class="notice aggregation-notice" role="status"><?= admin_h($aggregationResult) ?></div><?php endif ?>
        <?php if ($aggregationError !== ''): ?><div class="notice settings-error" role="alert"><?= admin_h($aggregationError) ?></div><?php endif ?>
        <div class="trend-grid">
            <?php foreach ([[$ui['last_7_days'], $dailyPeriod, $ui['includes_aggregated_pending'], $dailyValues, $dailyLabels, '', 1], [$ui['last_12_months'], $monthlyPeriod, $ui['includes_aggregated_pending'], $monthlyValues, $monthlyLabels, 'monthly', 2], [$ui['current_month_hourly'], $hourlyPeriod, $ui['aggregated_data_only'], $hourlyValues, $hourlyLabels, 'hourly', 6]] as [$title, $period, $description, $values, $labels, $class, $labelEvery]): ?><section class="panel trend-panel"><h2><?= admin_h($title) ?></h2><div class="chart-meta"><div class="chart-period"><?= admin_h($period) ?></div><div class="chart-description"><?= admin_h($description) ?></div></div><?php $chart = admin_chart($values, $labels, $class, $labelEvery); echo $chart !== '' ? $chart : '<div class="empty">' . admin_h($ui['no_chart_data']) . '</div>'; ?></section><?php endforeach ?>
        </div>
    </section>
    <section id="panel-details" role="tabpanel" aria-labelledby="tab-details" data-admin-panel="details" <?= $activeTab === 'details' ? '' : 'hidden' ?>><div class="heading"><h1><?= admin_h($ui['details']) ?></h1><p class="muted"><?= admin_h($ui['details_intro']) ?></p></div><section class="panel trend-panel"><h2><?= admin_h($ui['hourly_by_month']) ?></h2><form class="period-filter" method="get"><input type="hidden" name="tab" value="details"><label for="details-month"><?= admin_h($ui['target_month']) ?></label><select id="details-month" name="month" data-detail-month><?php foreach ($availableDetailMonths as $monthOption): $monthDate = DateTimeImmutable::createFromFormat('!Y-m', $monthOption); ?><option value="<?= admin_h($monthOption) ?>" <?= $selectedDetailMonth === $monthOption ? 'selected' : '' ?>><?= admin_h($monthDate ? ($language === 'ja' ? $monthDate->format('Y年n月') : $monthDate->format('F Y')) : $monthOption) ?></option><?php endforeach ?></select><span class="muted"><?= admin_h(sprintf($ui['period_total'], number_format(array_sum($detailHourlyValues)))) ?></span></form><?php $detailsChart = admin_chart($detailHourlyValues, $hourlyLabels, 'hourly', 3); echo $detailsChart !== '' ? $detailsChart : '<div class="empty">' . admin_h($ui['no_chart_data']) . '</div>'; ?><?php if (array_sum($detailHourlyValues) > 0): ?><details class="data-details"><summary><?= admin_h($ui['hourly_breakdown']) ?></summary><div class="table-scroll"><table><thead><tr><th><?= admin_h($catalog[$language]['hour']) ?></th><th><?= admin_h($catalog[$language]['count']) ?></th><th><?= admin_h($ui['share']) ?></th></tr></thead><tbody><?php $detailTotal = array_sum($detailHourlyValues); foreach ($detailHourlyValues as $hour => $count): ?><tr><td><?= admin_h($language === 'ja' ? $hour . '時' : sprintf('%02d:00', $hour)) ?></td><td><?= number_format($count) ?></td><td><?= number_format(($count / $detailTotal) * 100, 1) ?>%</td></tr><?php endforeach ?></tbody></table></div></details><?php endif ?></section>
        <section class="panel id-details"><h2><?= admin_h($ui['details_by_id']) ?></h2><form class="detail-filters" method="get"><input type="hidden" name="tab" value="details"><input type="hidden" name="month" value="<?= admin_h($selectedDetailMonth) ?>"><label for="id-search"><?= admin_h($ui['id_search']) ?><input id="id-search" name="q" type="search" value="<?= admin_h($detailSearch) ?>" placeholder="<?= admin_h($ui['id_search_placeholder']) ?>"></label><label for="detail-period"><?= admin_h($ui['target_period']) ?><select id="detail-period" name="period"><?php foreach ($ui['periods'] as $periodCode => $periodLabel): ?><option value="<?= admin_h((string) $periodCode) ?>" <?= $detailPeriod === (string) $periodCode ? 'selected' : '' ?>><?= admin_h($periodLabel) ?></option><?php endforeach ?></select></label><label for="detail-unit"><?= admin_h($ui['display_unit']) ?><select id="detail-unit" name="unit"><?php foreach ($ui['units'] as $unitCode => $unitLabel): ?><option value="<?= admin_h($unitCode) ?>" <?= $detailUnit === $unitCode ? 'selected' : '' ?>><?= admin_h($unitLabel) ?></option><?php endforeach ?></select></label><button class="primary" type="submit"><?= admin_h($ui['apply_filter']) ?></button></form>
<?php if ($selectedDetailId !== ''): ?><section class="id-trend"><h3><?= admin_h(sprintf($ui['id_trend'], $selectedDetailId)) ?></h3><div class="trend-scroll"><?php $detailLabelEvery = max(1, (int) ceil(max(1, count($detailTrendValues)) / 12)); $idTrendChart = admin_chart($detailTrendValues, $detailTrendLabels, 'detail-series', $detailLabelEvery); echo $idTrendChart !== '' ? $idTrendChart : '<div class="empty">' . admin_h($ui['no_chart_data']) . '</div>'; ?></div><p class="field-help"><?= admin_h($ui['stored_granularity_note']) ?></p></section><?php else: ?><p class="muted"><?= admin_h($ui['select_id']) ?></p><?php endif ?>
<div class="table-scroll id-table"><table><thead><tr><th><?= admin_h($catalog[$language]['like_id']) ?></th><th><?= admin_h($catalog[$language]['lifetime_count']) ?></th><th><?= admin_h($ui['period_count']) ?></th><th><?= admin_h($ui['installation_url']) ?></th></tr></thead><tbody><?php $idLinkBase = ['tab' => 'details', 'month' => $selectedDetailMonth, 'q' => $detailSearch, 'period' => $detailPeriod, 'unit' => $detailUnit, 'page' => $detailPage]; foreach ($detailRows as $detailRow): $rowIdentifier = (string) $detailRow['identifier']; ?><tr class="<?= $selectedDetailId === $rowIdentifier ? 'is-selected' : '' ?>"><td><a href="?<?= admin_h(http_build_query($idLinkBase + ['id' => $rowIdentifier])) ?>"><?= admin_h($rowIdentifier) ?></a></td><td><?= number_format((int) $detailRow['total_likes']) ?></td><td><?= number_format((int) $detailRow['period_likes']) ?></td><td class="url-cell"><?php if ($detailRow['urls'] === []): ?><span class="muted"><?= admin_h($ui['url_unregistered']) ?></span><?php else: ?><?php foreach ($detailRow['urls'] as $installationUrl): ?><a href="<?= admin_h($installationUrl) ?>" target="_blank" rel="noopener noreferrer"><?= admin_h($installationUrl) ?></a><?php endforeach ?><?php endif ?></td></tr><?php endforeach ?></tbody></table></div><?php if ($detailRows === []): ?><p class="empty id-empty"><?= admin_h($ui['no_matching_ids']) ?></p><?php endif ?><?php if ($detailTotalPages > 1): ?><nav class="pagination" aria-label="<?= admin_h($ui['details_by_id']) ?>"><?php $baseDetailQuery = ['tab' => 'details', 'month' => $selectedDetailMonth, 'q' => $detailSearch, 'period' => $detailPeriod, 'unit' => $detailUnit]; if ($detailPage > 1): ?><a href="?<?= admin_h(http_build_query($baseDetailQuery + ['page' => $detailPage - 1])) ?>"><?= admin_h($ui['previous_page']) ?></a><?php endif ?><span><?= admin_h(sprintf($ui['page_status'], $detailPage, $detailTotalPages)) ?></span><?php if ($detailPage < $detailTotalPages): ?><a href="?<?= admin_h(http_build_query($baseDetailQuery + ['page' => $detailPage + 1])) ?>"><?= admin_h($ui['next_page']) ?></a><?php endif ?></nav><?php endif ?></section>
    </section>
    <section id="panel-admin-operation" class="settings-panel" role="tabpanel" aria-labelledby="tab-admin-operation" data-admin-panel="admin-operation" <?= $activeTab === 'admin-operation' ? '' : 'hidden' ?>>
        <div class="heading settings-heading"><h1><?= admin_h($ui['admin_operation_settings']) ?></h1></div>
        <?php if ($settingsResult !== ''): ?><div class="notice settings-notice" role="status"><?= admin_h($settingsResult) ?></div><?php endif ?>
        <?php if ($settingsError !== ''): ?><div class="notice settings-error" role="alert"><?= admin_h($settingsError) ?></div><?php endif ?>
        <div class="setting-grid">
            <div class="settings-left-column">
            <aside class="panel settings-card timezone-card">
                <div class="timezone-heading-row"><h2><?= admin_h($catalog[$language]['timezone']) ?></h2><p class="muted timezone-note"><?= admin_h($catalog[$language]['timezone_change_warning']) ?></p></div>
                <form class="timezone-controls" data-timezone-form data-confirm-text="<?= admin_h($catalog[$language]['timezone_change_confirm']) ?>"><input type="hidden" name="csrf_token" value="<?= admin_h($csrfToken) ?>"><label class="visually-hidden" for="settings-timezone"><?= admin_h($catalog[$language]['timezone']) ?></label><select id="settings-timezone" name="timezone"><?php $selectedTimezone = $timezoneChangeState['new_timezone'] ?? $settings['timezone']; foreach (DateTimeZone::listIdentifiers() as $timezoneOption): ?><option value="<?= admin_h($timezoneOption) ?>" <?= $selectedTimezone === $timezoneOption ? 'selected' : '' ?>><?= admin_h($timezoneOption) ?></option><?php endforeach ?></select><button class="primary" type="submit" data-timezone-button><?= admin_h($timezoneChangeState === null ? $catalog[$language]['change_timezone'] : $catalog[$language]['resume_timezone_change']) ?></button></form>
                <p class="result timezone-result" data-timezone-result role="status" aria-live="polite"><?= $timezoneChangeState === null ? '' : admin_h($catalog[$language]['timezone_change_interrupted']) ?></p>
            </aside>
            <section class="panel settings-card">
                <h2><?= admin_h($ui['admin_operation_settings']) ?></h2>
                <form class="theme-form" method="post"><input type="hidden" name="action" value="save_admin_theme"><input type="hidden" name="csrf_token" value="<?= admin_h($csrfToken) ?>"><input type="hidden" name="tab" value="admin-operation"><div class="field"><label for="admin-theme"><?= admin_h($ui['admin_theme']) ?></label><p class="field-help"><?= admin_h($language === 'ja' ? '色別テーマは白基調です。システムは端末のライト／ダーク設定に追従します。' : 'Color themes use a white base. System follows the device light/dark preference.') ?></p><select id="admin-theme" data-theme-select name="theme"><?php foreach (pollyanna_admin_themes() as $themeCode): ?><option value="<?= admin_h($themeCode) ?>" <?= $theme === $themeCode ? 'selected' : '' ?>><?= admin_h($ui['themes'][$themeCode]) ?></option><?php endforeach ?></select></div></form>
                <?php if ($themeSaveError !== ''): ?><p class="result" role="status"><?= admin_h($themeSaveError) ?></p><?php endif ?>
                <form method="post" data-settings-form="admin-operation" data-unsaved-text="<?= admin_h($ui['unsaved_changes']) ?>"><input type="hidden" name="csrf_token" value="<?= admin_h($csrfToken) ?>"><input type="hidden" name="action" value="save_admin_operation">
                    <div class="field"><label for="system-state"><?= admin_h($catalog[$language]['system_state']) ?></label><p class="field-help"><?= admin_h($ui['maintenance_help']) ?></p><select id="system-state" name="system_state"><?php foreach ($catalog[$language]['system_states'] as $stateCode => $stateLabel): ?><option value="<?= admin_h($stateCode) ?>" <?= $formSystemState === $stateCode ? 'selected' : '' ?>><?= admin_h($stateLabel) ?></option><?php endforeach ?></select></div>
                    <div class="field"><label for="aggregation-threshold"><?= admin_h($catalog[$language]['threshold']) ?></label><p class="field-help"><?= admin_h($ui['threshold_help']) ?></p><select id="aggregation-threshold" name="aggregation_start_threshold"><?php foreach ([1000, 2000, 4000, 6000, 8000, 10000] as $threshold): ?><option value="<?= $threshold ?>" <?= $formAggregationThreshold === $threshold ? 'selected' : '' ?>><?= number_format($threshold) ?></option><?php endforeach ?></select></div>
                    <div class="save-row"><button class="primary" type="submit"><?= admin_h($ui['save_admin_operation']) ?></button><span class="save-status" data-save-status><?= admin_h($ui['no_changes']) ?></span></div>
                </form>
            </section>
            </div>
        </div>
        <section class="panel settings-card origin-settings">
            <h2><?= admin_h($language === 'ja' ? '利用を許可するサイトの設定（Origin設定）' : 'Allowed site settings (Origin settings)') ?></h2>
            <section class="origin-section" aria-labelledby="primary-origin-heading">
                <h3 id="primary-origin-heading"><?= admin_h($language === 'ja' ? 'このサイトの設定' : 'This site') ?></h3>
                <p class="origin-description"><?= admin_h($language === 'ja' ? 'このサイト自身のアドレスを登録します。Pollyannaを設置しているサイトのアドレスです。' : 'Register the address of this site, where Pollyanna is installed.') ?></p>
                <form method="post"><input type="hidden" name="csrf_token" value="<?= admin_h($csrfToken) ?>"><input type="hidden" name="action" value="save_origin_settings">
                    <div class="field"><label for="site-origin"><?= admin_h($language === 'ja' ? 'このサイトのアドレス（Origin）' : 'This site address (Origin)') ?></label><input id="site-origin" name="site_origin" type="url" required value="<?= admin_h($formSiteOrigin) ?>" data-origin-host-source data-site-origin><p class="field-help"><?= admin_h($language === 'ja' ? 'http:// や https:// から始まるアドレス全体を入力します。（例：https://example.com）' : 'Enter the complete address beginning with http:// or https://. (Example: https://example.com)') ?></p></div>
                    <details class="origin-advanced"><summary><?= admin_h($language === 'ja' ? '詳細設定' : 'Advanced settings') ?></summary><div class="field"><label for="site-base-domain"><?= admin_h($language === 'ja' ? 'このサイトのドメイン' : 'This site domain') ?></label><input id="site-base-domain" name="site_base_domain" type="text" required value="<?= admin_h($formBaseDomain) ?>" data-base-domain><p class="field-help"><?= admin_h($language === 'ja' ? 'アドレスのホスト名をもとに、このサイトで利用する基本ドメインを指定します。（例：example.com）' : 'Specify the base domain used by this site from the address host name. (Example: example.com)') ?></p></div></details>
                    <label class="check-label"><input type="checkbox" name="site_base_domain_include_subdomains" value="1" <?= $formBaseSubdomains ? 'checked' : '' ?> data-subdomain-toggle> <span><?= admin_h($language === 'ja' ? 'このサイトのサブドメインからの利用も許可する' : 'Also allow use from subdomains of this site') ?><small><?= admin_h($language === 'ja' ? '（例：blog.example.com）' : ' (Example: blog.example.com)') ?></small></span></label>
                    <div class="save-row"><button class="primary" type="submit"><?= admin_h($language === 'ja' ? 'このサイトの設定を保存' : 'Save this site settings') ?></button></div>
                </form>
            </section>
            <section class="origin-section" aria-labelledby="external-origin-heading">
            <h3 id="external-origin-heading"><?= admin_h($language === 'ja' ? '他のサイトからの利用許可' : 'Allow use from other sites') ?></h3>
            <p class="origin-description"><?= admin_h($language === 'ja' ? '公開APIを、ブラウザー上の他のWebサイトから使えるようにするための設定です。登録したサイトのアドレスからだけ利用できます。' : 'Allow the public API to be used by other websites in a browser. Only registered site addresses can use it.') ?></p>
            <?php if ($allowedOrigins === []): ?><p class="muted"><?= admin_h($language === 'ja' ? '他のサイトはまだ登録されていません。' : 'No other sites are registered yet.') ?></p><?php else: ?>
            <div class="table-scroll"><table><thead><tr><th><?= admin_h($language === 'ja' ? 'サイトのアドレス（Origin）' : 'Site address (Origin)') ?></th><th><?= admin_h($language === 'ja' ? 'サブドメインも許可' : 'Allow subdomains') ?></th><th></th></tr></thead><tbody><?php foreach ($allowedOrigins as $allowed): $allowedObject = new PollyannaOrigin((string) $allowed['scheme'], (string) $allowed['host'], (int) $allowed['port']); $allowedOriginValue = $allowedObject->serialize(); $deleteConfirmation = $language === 'ja' ? sprintf("「%s」の利用許可を削除しますか？\n\n削除すると、このサイトからPollyannaの公開APIを利用できなくなります。", $allowedOriginValue) : sprintf("Remove permission for \"%s\"?\n\nAfter removal, this site will no longer be able to use the Pollyanna public API.", $allowedOriginValue); ?><tr><td><?= admin_h($allowedOriginValue) ?></td><td><?= ((int) $allowed['include_subdomains']) === 1 ? admin_h($language === 'ja' ? '許可する' : 'Allowed') : admin_h($language === 'ja' ? '許可しない' : 'Not allowed') ?></td><td><form method="post" data-origin-delete-form data-confirm-message="<?= admin_h($deleteConfirmation) ?>"><input type="hidden" name="csrf_token" value="<?= admin_h($csrfToken) ?>"><input type="hidden" name="action" value="delete_allowed_origin"><input type="hidden" name="allowed_origin_id" value="<?= (int) $allowed['id'] ?>"><button type="submit"><?= admin_h($language === 'ja' ? '削除' : 'Delete') ?></button></form></td></tr><?php endforeach ?></tbody></table></div>
            <?php endif ?>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= admin_h($csrfToken) ?>"><input type="hidden" name="action" value="add_allowed_origin">
                <div class="field"><label for="allowed-origin"><?= admin_h($language === 'ja' ? '許可するサイトのアドレス（Origin）' : 'Allowed site address (Origin)') ?></label><input id="allowed-origin" name="allowed_origin" type="url" required placeholder="https://example.com" data-origin-host-source><p class="field-help origin-note"><?= admin_h($language === 'ja' ? '「http」と「https」、またはポート番号が違うと別のアドレスとして扱われます。' : 'Different schemes (http or https) or port numbers are treated as different addresses.') ?></p></div>
                <label class="check-label"><input type="checkbox" name="include_subdomains" value="1" data-subdomain-toggle> <span><?= admin_h($language === 'ja' ? 'サブドメインも許可する' : 'Also allow subdomains') ?><small><?= admin_h($language === 'ja' ? '（例：blog.example.com）' : ' (Example: blog.example.com)') ?></small></span></label>
                <div class="save-row"><button class="primary" type="submit"><?= admin_h($language === 'ja' ? 'サイトを追加' : 'Add site') ?></button></div>
            </form>
            </section>
        </section>

        <section class="panel settings-card password-change-card">
            <h2><?= admin_h($ui['change_admin_password']) ?></h2>
            <p class="field-help"><?= admin_h($ui['password_requirement']) ?></p>
            <p class="field-help"><?= admin_h($ui['password_change_help']) ?></p>
            <form method="post" data-settings-form="admin-password" data-unsaved-text="<?= admin_h($ui['unsaved_changes']) ?>" data-password-rule="<?= admin_h($ui['password_requirement']) ?>">
                <input type="hidden" name="action" value="change_admin_password">
                <input type="hidden" name="csrf_token" value="<?= admin_h($csrfToken) ?>">
                <div class="field"><label for="current-password"><?= admin_h($ui['current_password']) ?></label><input id="current-password" name="current_password" type="password" maxlength="1024" autocomplete="current-password" required></div>
                <div class="field"><label for="new-password"><?= admin_h($ui['new_password']) ?></label><input id="new-password" name="new_password" type="password" maxlength="72" autocomplete="new-password" data-new-admin-password required></div>
                <div class="field"><label for="new-password-confirmation"><?= admin_h($ui['new_password_confirmation']) ?></label><input id="new-password-confirmation" name="new_password_confirmation" type="password" maxlength="72" autocomplete="new-password" data-new-admin-password required></div>
                <div class="save-row"><button class="primary" type="submit"><?= admin_h($ui['change_admin_password']) ?></button><span class="save-status" data-save-status><?= admin_h($ui['no_changes']) ?></span></div>
            </form>
        </section>
    </section>
    <section id="panel-public-display" class="settings-panel" role="tabpanel" aria-labelledby="tab-public-display" data-admin-panel="public-display" <?= $activeTab === 'public-display' ? '' : 'hidden' ?>>
        <div class="heading settings-heading"><h1><?= admin_h($ui['public_display_settings']) ?></h1></div>
        <?php if ($settingsResult !== ''): ?><div class="notice settings-notice" role="status"><?= admin_h($settingsResult) ?></div><?php endif ?>
        <?php if ($settingsError !== ''): ?><div class="notice settings-error" role="alert"><?= admin_h($settingsError) ?></div><?php endif ?>
            <section class="panel settings-card">
                <h2><?= admin_h($ui['public_display_settings']) ?></h2>
                <form method="post" data-settings-form="public-display" data-unsaved-text="<?= admin_h($ui['unsaved_changes']) ?>"><input type="hidden" name="csrf_token" value="<?= admin_h($csrfToken) ?>"><input type="hidden" name="action" value="save_public_display">
                    <div class="field"><label for="widget-language"><?= admin_h($catalog[$language]['widget_language']) ?><select id="widget-language" name="widget_language" data-widget-language><?php foreach ($widgetCatalog as $code => $locale): ?><option value="<?= admin_h($code) ?>" <?= $formWidgetLanguage === $code ? 'selected' : '' ?>><?= admin_h($locale['language_name']) ?></option><?php endforeach ?></select></label></div>
                    <div class="field"><label for="success-message"><?= admin_h($catalog[$language]['click_message']) ?><select id="success-message" name="success_message" data-success-message><?php foreach ($widgetCatalog[$formWidgetLanguage]['success_messages'] as $messageKey => $message): ?><option value="<?= admin_h($messageKey) ?>" <?= $formSuccessMessage === $messageKey ? 'selected' : '' ?>><?= admin_h($message) ?></option><?php endforeach ?></select></label></div>
                    <div class="field"><label for="rate-limit"><?= admin_h($catalog[$language]['rate_limit']) ?></label><p class="field-help"><?= admin_h($ui['limit_help']) ?></p><select id="rate-limit" name="likes_per_24_hours"><?php foreach ([1, 2, 3, 4, 5] as $limit): ?><option value="<?= $limit ?>" <?= $formRateLimit === $limit ? 'selected' : '' ?>><?= $limit ?></option><?php endforeach ?></select></div>
                    <div class="field"><label for="show-count"><?= admin_h($catalog[$language]['public_count']) ?></label><p class="field-help field-help-lines"><span><?= admin_h($ui['count_visibility_on_help']) ?></span><span><?= admin_h($ui['count_visibility_off_help']) ?></span></p><select id="show-count" name="show_like_count" data-count-visibility><option value="off" <?= !$formShowCount ? 'selected' : '' ?>><?= admin_h($catalog[$language]['off']) ?></option><option value="on" <?= $formShowCount ? 'selected' : '' ?>><?= admin_h($catalog[$language]['on']) ?></option></select></div>
                    <div class="field"><label for="refresh-interval"><?= admin_h($catalog[$language]['count_refresh_interval']) ?><select id="refresh-interval" name="count_refresh_interval" data-count-dependent <?= !$formShowCount ? 'disabled' : '' ?>><?php foreach ($catalog[$language]['refresh_intervals'] as $seconds => $label): ?><option value="<?= admin_h((string) $seconds) ?>" <?= $formRefreshInterval === (int) $seconds ? 'selected' : '' ?>><?= admin_h($label) ?></option><?php endforeach ?></select></label></div>
                    <div class="field"><label for="button-symbol-preset"><?= admin_h($language === 'ja' ? 'いいねボタンの文字記号' : 'Like button symbol') ?></label><select id="button-symbol-preset" name="button_symbol_preset" data-button-symbol-preset><?php foreach ($buttonSymbolPresets as $presetKey => $symbols): ?><option value="<?= admin_h($presetKey) ?>" <?= $formButtonSymbolPreset === $presetKey ? 'selected' : '' ?>><?= admin_h($buttonPresetLabels[$presetKey] . '（' . $symbols['unliked'] . ' / ' . $symbols['liked'] . '）') ?></option><?php endforeach ?></select></div>
                    <fieldset class="field symbol-color-field"><legend><?= admin_h($language === 'ja' ? '未押下時の記号色' : 'Unliked symbol color') ?></legend><div class="symbol-color-options"><?php foreach (['#666666' => ($language === 'ja' ? '濃い灰色（標準）' : 'Dark gray (standard)'), '#cccccc' => ($language === 'ja' ? '薄い灰色' : 'Light gray')] as $colorValue => $colorLabel): ?><label><input type="radio" name="button_unliked_color" value="<?= $colorValue ?>" <?= $formButtonUnlikedColor === $colorValue ? 'checked' : '' ?> data-button-unliked-color><span class="color-swatch" style="--swatch-color:<?= $colorValue ?>" aria-hidden="true"></span><span><?= admin_h($colorLabel) ?></span></label><?php endforeach ?></div></fieldset>
                    <div class="field count-color-field"><label for="button-liked-color"><?= admin_h($language === 'ja' ? '押下後の記号色' : 'Liked symbol color') ?></label><p id="button-liked-color-help" class="field-help"><?= admin_h($language === 'ja' ? '色見本をクリックするか、#＋6桁の16進数を入力してください。' : 'Choose a swatch or enter a # followed by 6 hexadecimal digits.') ?></p><div class="count-color-control"><input id="button-liked-color" name="button_liked_color" type="color" value="<?= admin_h($formButtonLikedColor) ?>" data-button-liked-color><label class="visually-hidden" for="button-liked-color-hex"><?= admin_h($language === 'ja' ? '押下後の記号色（16進数）' : 'Liked symbol color (hex)') ?></label><input id="button-liked-color-hex" class="count-color-hex" type="text" value="<?= admin_h(strtoupper($formButtonLikedColor)) ?>" pattern="#[0-9A-Fa-f]{6}" maxlength="7" inputmode="text" autocomplete="off" spellcheck="false" aria-describedby="button-liked-color-help" data-button-liked-color-value data-invalid-text="<?= admin_h($ui['count_color_hex_error']) ?>"></div></div>
                    <div class="field count-color-field"><label for="count-color"><?= admin_h($ui['count_color']) ?></label><p id="count-color-help" class="field-help"><?= admin_h($ui['count_color_help']) ?></p><div class="count-color-control"><input id="count-color" name="like_count_color" type="color" value="<?= admin_h($formCountColor) ?>" data-count-dependent <?= !$formShowCount ? 'disabled' : '' ?>><label class="visually-hidden" for="count-color-hex"><?= admin_h($ui['count_color_hex']) ?></label><input id="count-color-hex" class="count-color-hex" type="text" value="<?= admin_h(strtoupper($formCountColor)) ?>" pattern="#[0-9A-Fa-f]{6}" maxlength="7" inputmode="text" autocomplete="off" spellcheck="false" aria-describedby="count-color-help" data-count-color-value data-invalid-text="<?= admin_h($ui['count_color_hex_error']) ?>" data-count-dependent <?= !$formShowCount ? 'disabled' : '' ?>></div></div>
                    <section class="like-preview" aria-labelledby="like-preview-title"><div class="like-preview-heading"><h3 id="like-preview-title"><?= admin_h($language === 'ja' ? '表示プレビュー' : 'Display preview') ?></h3><div class="preview-background-options" role="group" aria-label="<?= admin_h($language === 'ja' ? 'プレビュー背景' : 'Preview background') ?>"><button type="button" class="preview-background-button is-active" data-preview-background="light"><?= admin_h($language === 'ja' ? '明るい背景' : 'Light') ?></button><button type="button" class="preview-background-button" data-preview-background="dark"><?= admin_h($language === 'ja' ? '暗い背景' : 'Dark') ?></button></div></div><div class="like-preview-stage" data-like-preview-stage data-background="light"><button type="button" class="like-preview-button" data-like-preview-button aria-pressed="false"><?= admin_h($buttonSymbolPresets[$formButtonSymbolPreset]['unliked']) ?></button><span class="like-preview-count" data-like-preview-count>128</span></div><p class="field-help"><?= admin_h($language === 'ja' ? '記号をクリックすると、未押下・押下後を切り替えられます。実際のいいね数には影響しません。' : 'Click the symbol to switch between unliked and liked. This does not affect real Like counts.') ?></p></section>
                    <div class="save-row"><button class="primary" type="submit"><?= admin_h($ui['save_public_display']) ?></button><span class="save-status" data-save-status><?= admin_h($ui['no_changes']) ?></span></div>
                </form>
            </section>
    </section>
    <section id="panel-access-protection" class="settings-panel" role="tabpanel" aria-labelledby="tab-access-protection" data-admin-panel="access-protection" <?= $activeTab === 'access-protection' ? '' : 'hidden' ?>>
        <div class="heading settings-heading"><h1><?= admin_h($ui['access_protection']) ?></h1></div>
        <?php if ($settingsResult !== ''): ?><div class="notice settings-notice" role="status"><?= admin_h($settingsResult) ?></div><?php endif ?>
        <?php if ($settingsError !== ''): ?><div class="notice settings-error" role="alert"><?= admin_h($settingsError) ?></div><?php endif ?>
        <section class="panel settings-card access-protection-card access-monitoring">
            <h2><?= admin_h($language === 'ja' ? '監視状況' : 'Monitoring') ?></h2>
            <p class="field-help"><?= admin_h(sprintf($ui['access_display_timezone'], $settings['timezone'], (new DateTimeImmutable('now', new DateTimeZone($settings['timezone'])))->format('T'))) ?></p>
            <?php if ($accessError !== ''): ?><p class="notice settings-error" role="alert"><?= admin_h($accessError) ?></p><?php endif ?>
            <?php if ($accessError === ''): ?>
            <?php if ($accessOverview['recent'] === 0 && $accessOverview['blocks'] === []): ?><p class="result"><?= admin_h($language === 'ja' ? '直近の判定期間：現在の集中アクセスはありません。' : 'Recent detection window: no concentrated access detected.') ?></p><?php endif ?>
            <h3><?= admin_h($language === 'ja' ? '現在のブロック件数：' : 'Current blocks: ') ?><?= count($accessOverview['blocks']) ?></h3>
            <p class="field-help"><?= admin_h($ui['access_existing_blocks_help']) ?></p>
            <?php foreach ($accessOverview['blocks'] as $block): ?>
            <div class="access-block">
                <p><?= admin_h(sprintf($language === 'ja' ? '管理ID %d／検知日時 %s／%s.php／%d回・%d秒／解除予定 %s' : 'ID %d / Detected %s / %s.php / %d requests in %d seconds / Expires %s', (int) $block['id'], admin_access_datetime($block['started_at'], $settings['timezone']), $block['api'], (int) $block['request_count'], (int) $block['window_seconds'], admin_access_datetime($block['expires_at'], $settings['timezone']))) ?></p>
                <form method="post" data-access-release-form data-confirm-message="<?= admin_h($language === 'ja' ? 'このブロックを解除しますか？判定カウンターもリセットします。' : 'Release this block and reset its detection counters?') ?>">
                    <input type="hidden" name="csrf_token" value="<?= admin_h($csrfToken) ?>"><input type="hidden" name="action" value="release_access_block"><input type="hidden" name="access_block_id" value="<?= (int) $block['id'] ?>">
                    <button class="primary" type="submit"><?= admin_h($language === 'ja' ? '手動解除' : 'Release block') ?></button>
                </form>
            </div>
            <?php endforeach ?>
            <div class="access-history-heading">
                <h3><?= admin_h($language === 'ja' ? '過去60日間の異常検知履歴' : 'Detection history (last 60 days)') ?></h3>
                <form method="get" class="access-history-size-form">
                    <input type="hidden" name="tab" value="access-protection"><input type="hidden" name="access_page" value="1">
                    <label for="access-history-size"><?= admin_h($language === 'ja' ? '表示件数' : 'Per page') ?></label>
                    <select id="access-history-size" name="access_size" data-access-history-size data-cookie-path="<?= admin_h(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/admin/index.php'))) . '/') ?>">
                    <?php foreach ([20, 50, 80, 100] as $size): ?><option value="<?= $size ?>" <?= $accessPageSize === $size ? 'selected' : '' ?>><?= $size ?></option><?php endforeach ?>
                    </select>
                    <noscript><button type="submit"><?= admin_h($language === 'ja' ? '表示' : 'Apply') ?></button></noscript>
                </form>
            </div>
            <?php if ($accessOverview['history'] === []): ?><p class="muted"><?= admin_h($language === 'ja' ? '履歴はありません。' : 'No history.') ?></p><?php endif ?>
            <?php foreach ($accessOverview['history'] as $entry): ?>
            <div class="access-history">
                <p><?= admin_h(sprintf('%s / %s.php / %d / %ds', admin_access_datetime($entry['detected_at'], $settings['timezone']), $entry['api'], (int) $entry['request_count'], (int) $entry['window_seconds'])) ?></p>
                <p class="muted"><?= admin_h(($language === 'ja' ? 'ブロック開始：' : 'Block started: ') . admin_access_datetime($entry['block_started_at'], $settings['timezone']) . ' / ' . ($language === 'ja' ? '解除：' : 'Released: ') . admin_access_datetime($entry['released_at'], $settings['timezone']) . ' / ' . ($entry['release_method'] === 'manual' ? ($language === 'ja' ? '手動' : 'Manual') : ($entry['release_method'] === 'automatic' ? ($language === 'ja' ? '自動' : 'Automatic') : ((int) $entry['blocked'] === 1 ? ($language === 'ja' ? '未解除' : 'Not released') : ($language === 'ja' ? '検知のみ' : 'Detection only'))))) ?></p>
            </div>
            <?php endforeach ?>
            <?php if ($accessOverview['pages'] > 1): ?><nav class="pagination access-history-pagination" aria-label="<?= admin_h($language === 'ja' ? '検知履歴' : 'Detection history') ?>">
                <?php if ($accessOverview['page'] > 1): ?><a href="?tab=access-protection&amp;access_size=<?= $accessPageSize ?>&amp;access_page=<?= $accessOverview['page'] - 1 ?>"><?= admin_h($ui['previous_page']) ?></a><?php endif ?>
                <span><?= admin_h(sprintf($ui['page_status'], $accessOverview['page'], $accessOverview['pages'])) ?></span>
                <?php if ($accessOverview['page'] < $accessOverview['pages']): ?><a href="?tab=access-protection&amp;access_size=<?= $accessPageSize ?>&amp;access_page=<?= $accessOverview['page'] + 1 ?>"><?= admin_h($ui['next_page']) ?></a><?php endif ?>
            </nav><?php endif ?>
            <?php endif ?>
        </section>
        <section class="panel settings-card access-protection-card access-settings">
            <h2><?= admin_h($language === 'ja' ? '集中アクセス検知の設定' : 'Access detection settings') ?></h2>
            <p class="field-help"><?= admin_h($ui['access_shared_connection_help']) ?></p>
            <?php if ($accessError !== ''): ?><p class="notice settings-error" role="alert"><?= admin_h($accessError) ?></p><?php endif ?>
            <form method="post" data-settings-form="access-protection" data-unsaved-text="<?= admin_h($ui['unsaved_changes']) ?>">
                <input type="hidden" name="csrf_token" value="<?= admin_h($csrfToken) ?>">
                <input type="hidden" name="action" value="save_access_protection">
                <?php foreach (['enabled' => ($language === 'ja' ? '集中アクセス検知' : 'Detection'), 'auto_block' => ($language === 'ja' ? '自動ブロック（48時間）' : 'Automatic blocking (48 hours)')] as $key => $label): ?>
                <div class="field"><label for="access-<?= $key ?>"><?= admin_h($label) ?></label><select id="access-<?= $key ?>" name="access_<?= $key ?>" <?= $key === 'auto_block' && !$accessSettings['enabled'] ? 'disabled' : '' ?>><option value="on" <?= $accessSettings[$key] ? 'selected' : '' ?>>ON</option><option value="off" <?= !$accessSettings[$key] ? 'selected' : '' ?>>OFF</option></select></div>
                <?php endforeach ?>
                <?php foreach (['state', 'like'] as $accessApi): ?>
                <fieldset class="field access-rule"><legend><?= admin_h($ui['access_' . $accessApi . '_heading']) ?></legend>
                    <p class="field-help"><?= admin_h($ui['access_' . $accessApi . '_help']) ?></p>
                    <label for="access-<?= $accessApi ?>-window"><?= admin_h($ui['access_window_label']) ?></label>
                    <input id="access-<?= $accessApi ?>-window" name="access_<?= $accessApi ?>_window" aria-describedby="access-<?= $accessApi ?>-window-help" type="number" min="10" max="3600" required value="<?= (int) $accessSettings[$accessApi]['window'] ?>">
                    <p id="access-<?= $accessApi ?>-window-help" class="field-help"><?= admin_h($ui['access_window_range']) ?></p>
                    <label for="access-<?= $accessApi ?>-threshold"><?= admin_h($ui['access_threshold_label']) ?></label>
                    <input id="access-<?= $accessApi ?>-threshold" name="access_<?= $accessApi ?>_threshold" aria-describedby="access-<?= $accessApi ?>-threshold-help" type="number" min="1" max="1000000" required value="<?= (int) $accessSettings[$accessApi]['threshold'] ?>">
                    <p id="access-<?= $accessApi ?>-threshold-help" class="field-help"><?= admin_h($ui['access_threshold_range']) ?></p>
                </fieldset>
                <?php endforeach ?>
                <p class="field-help"><?= admin_h($ui['access_rule_help']) ?></p>
                <p class="field-help"><?= admin_h($ui['access_review_help']) ?></p>
                <div class="save-row"><button class="primary" type="submit" <?= $accessError !== '' ? 'disabled' : '' ?>><?= admin_h($language === 'ja' ? '集中アクセス検知設定を保存' : 'Save access protection') ?></button><span class="save-status" data-save-status><?= admin_h($ui['no_changes']) ?></span></div>
            </form>
        </section>
    </section>
</main>
<footer class="site-footer"><div>Pollyanna — An Easy-to-Install Like Button System</div><div>Developed by Nikukiu-SYSTEM</div></footer>
<script id="widget-message-catalog" type="application/json"><?= json_encode(array_map(static fn (array $locale): array => $locale['success_messages'], $widgetCatalog), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script id="timezone-change-data" type="application/json"><?= json_encode(['inProgress' => $timezoneChangeState !== null, 'currentTimezone' => $settings['timezone'], 'labels' => ['aggregating' => $catalog[$language]['timezone_aggregating'], 'archiving' => $catalog[$language]['timezone_archiving'], 'done' => $catalog[$language]['timezone_change_done'], 'storageNote' => $catalog[$language]['timezone_archive_storage_note'], 'error' => $catalog[$language]['timezone_change_error']]], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="../assets/admin.js"></script>
</body>
</html>
