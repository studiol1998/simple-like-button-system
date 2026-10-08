<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$count = 0;
$assert = static function (bool $condition, string $message) use (&$count): void {
    $count++;
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
};

$installer = (string) file_get_contents($root . '/install/index.php');
$readmeViewer = (string) file_get_contents($root . '/install/readme.php');
$admin = (string) file_get_contents($root . '/admin/index.php');
$adminCss = (string) file_get_contents($root . '/assets/admin.css');
$adminJs = (string) file_get_contents($root . '/assets/admin.js');
$likeJs = (string) file_get_contents($root . '/assets/like.js');
$likeCss = (string) file_get_contents($root . '/assets/like.css');
$configExample = (string) file_get_contents($root . '/config/config.example.php');

foreach (['README.md', 'README.en.md'] as $readmeName) {
    $readme = (string) file_get_contents($root . '/' . $readmeName);
    $assert($readme !== '' && preg_match('//u', $readme) === 1, $readmeName . ' is non-empty UTF-8');
}
$assert(str_contains($readmeViewer, "'ja' => dirname(__DIR__) . '/README.md'"), 'Japanese README is allowlisted');
$assert(str_contains($readmeViewer, "'en' => dirname(__DIR__) . '/README.en.md'"), 'English README is allowlisted');
$assert(str_contains($readmeViewer, "Content-Type: text/plain; charset=UTF-8"), 'README viewer sends an explicit UTF-8 content type');
$assert(str_contains($readmeViewer, "X-Content-Type-Options: nosniff"), 'README viewer disables content sniffing');
$assert(str_contains($installer, 'href="readme.php?language='), 'Completion screen uses the README viewer');
$assert(str_contains($installer, '<a class="primary-link" href="../admin/">'), 'Completion screen links to Admin');
$assert(str_contains($installer, "'admin_link' => '管理画面へ'"), 'Japanese Admin link label is present');

$hostPosition = strpos($installer, 'id="db-host"');
$portPosition = strpos($installer, 'id="db-port"');
$assert($hostPosition !== false && $portPosition !== false && $hostPosition < $portPosition, 'Database host precedes port');
$databaseSlice = substr($installer, max(0, (int) $hostPosition - 300), ((int) $portPosition - (int) $hostPosition) + 700);
$assert(!str_contains($databaseSlice, 'class="form-grid"'), 'Database host and port are not in a horizontal grid');

foreach ([
    '利用を許可するサイトの設定（Origin設定）',
    'このサイトの設定',
    'このサイトのアドレス（Origin）',
    'このサイトのドメイン',
    'このサイトの設定を保存',
    '他のサイトからの利用許可',
    '他のサイトはまだ登録されていません。',
    '許可するサイトのアドレス（Origin）',
    'サイトを追加',
    'サイトのアドレス（Origin）',
    'サブドメインも許可',
    '許可する',
    '許可しない',
    '詳細設定',
] as $label) {
    $assert(str_contains($admin, $label), 'Admin Origin UI contains: ' . $label);
}
$assert(str_contains($adminCss, '.settings-card{align-self:stretch}'), 'Settings cards stretch to equal row height');
$assert(str_contains($adminCss, '.origin-settings .field input[type="url"],.origin-settings .field input[type="text"]{display:block;width:100%'), 'Origin inputs use available width');
$timezonePosition = strpos($admin, 'class="panel settings-card timezone-card"');
$operationPosition = strpos($admin, "admin_h(\$ui['admin_operation_settings'])");
$displayPosition = strpos($admin, "admin_h(\$ui['public_display_settings'])");
$assert($timezonePosition !== false && $operationPosition !== false && $displayPosition !== false && $timezonePosition < $operationPosition && $operationPosition < $displayPosition, 'Settings cards use the requested mobile order');
$assert(str_contains($admin, 'class="settings-left-column"'), 'Timezone and operation cards have a shared left column');
$assert(str_contains($adminCss, '#panel-settings .setting-grid{grid-template-columns:minmax(0,1fr) minmax(0,1fr);align-items:stretch}'), 'Desktop settings layout uses two equal stretching columns');
$assert(str_contains($adminCss, '#panel-settings .settings-left-column>.settings-card:last-child{flex:1}'), 'Left card group fills the right card height');
$assert(str_contains($adminCss, '@media(max-width:760px)') && str_contains($adminCss, '#panel-settings .setting-grid{grid-template-columns:1fr}'), 'Mobile settings layout uses one column');
$assert(str_contains($adminCss, '#panel-settings .field-help,') && str_contains($adminCss, '#panel-settings .timezone-note{font-size:14px}'), 'Settings helper and warning text use at least 14px');
$assert(str_contains($admin, 'data-site-origin') && str_contains($admin, 'data-base-domain'), 'Site Origin and base domain fields are connected for automatic suggestions');
$assert(str_contains($admin, '<details class="origin-advanced">'), 'Base domain is inside collapsible advanced settings');
$assert(str_contains($admin, 'http:// や https:// から始まるアドレス全体を入力します。'), 'Origin helper text is displayed');
$assert(str_contains($admin, 'アドレスのホスト名をもとに、このサイトで利用する基本ドメインを指定します。'), 'Base domain helper text is displayed');
$assert(str_contains($adminJs, "host.startsWith('www.')") && str_contains($adminJs, 'host.slice(4)'), 'Only a leading www label is removed for the automatic candidate');
$assert(!str_contains($adminJs, "host.split('.')"), 'Base domain suggestion does not shorten multi-level suffixes by splitting labels');
$assert(str_contains($adminJs, "baseDomainField.value.trim() !== ''"), 'An existing base domain disables automatic replacement');
$assert(str_contains($adminJs, 'baseDomainWasEdited = true'), 'Manual base domain edits disable automatic replacement');
$assert(str_contains($admin, 'data-origin-delete-form') && str_contains($admin, 'data-confirm-message='), 'Delete forms carry a safe confirmation message');
$assert(str_contains($adminJs, "if (!window.confirm(form.dataset.confirmMessage || '')) event.preventDefault();"), 'Canceling the delete confirmation prevents submission');
$assert(substr_count($admin, 'name="csrf_token"') >= 5, 'Origin forms retain CSRF tokens');
$assert(str_contains($admin, "['heart' => 'ハート', 'diamond' => 'ダイヤ', 'spade' => 'スペード', 'club' => 'クラブ', 'star' => '星', 'music' => '音符']"), 'Six symbol preset labels use the required order');
$assert(str_contains($admin, "['#666666' =>") && str_contains($admin, "'#cccccc' =>") && str_contains($admin, 'name="button_unliked_color"'), 'Only two unliked color choices are rendered');
$assert(str_contains($admin, 'data-button-liked-color') && str_contains($admin, 'data-button-liked-color-value'), 'Liked color picker and hexadecimal input are rendered');
$assert(str_contains($admin, 'data-like-preview-button') && str_contains($admin, 'data-preview-background="light"') && str_contains($admin, 'data-preview-background="dark"'), 'Interactive light/dark preview is rendered');
$previewStart = strpos($adminJs, 'const symbolPreset =');
$previewEnd = strpos($adminJs, "document.querySelectorAll('[data-settings-form]')");
$previewSource = $previewStart === false || $previewEnd === false ? '' : substr($adminJs, $previewStart, $previewEnd - $previewStart);
$assert($previewSource !== '' && !str_contains($previewSource, 'fetch('), 'Admin preview does not call an API');
$assert(str_contains($likeJs, "symbol_unliked: '♡'") && str_contains($likeJs, "symbol_liked: '♥'"), 'Public widget retains heart fallback symbols');
$assert(str_contains($likeCss, 'var(--pollyanna-like-unliked-color, #666666)') && str_contains($likeCss, 'var(--pollyanna-like-liked-color, #e53955)'), 'Public widget CSS retains default symbol colors');
$assert(str_contains($installer, "'button_symbol_preset' => 'heart'") && str_contains($configExample, "'button_liked_color' => '#e53955'"), 'Installer and manual config contain button display defaults');
$assert(str_contains($admin, 'このサイトのサブドメインからの利用も許可する') && !str_contains($admin, 'このドメインのサブドメインからの利用も許可する'), 'Primary subdomain label changed without duplicate old wording');
$assert(str_contains($admin, 'PollyannaOrigin::normalizeSiteSettings($originInput, $baseDomainInput, $include)'), 'Admin uses shared site Origin validation');
$assert(str_contains($admin, 'if ($origin->isLocalOrIp()) $include = false;'), 'External local/IP subdomain setting is normalized off server-side');
$assert(str_contains($installer, 'PollyannaOrigin::normalizeSiteSettings($siteOriginInput, $baseDomainInput, $includeSubdomains)'), 'Installer uses shared site Origin validation');

echo "Phase 3 admin/installer UI tests passed ({$count} assertions).\n";
