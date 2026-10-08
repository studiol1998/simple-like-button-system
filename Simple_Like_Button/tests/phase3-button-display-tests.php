<?php
declare(strict_types=1);

$configPath = dirname(__DIR__) . '/config/config.php';
$originalConfig = is_file($configPath) ? file_get_contents($configPath) : null;
if ($originalConfig === null) {
    file_put_contents($configPath, "<?php\ndeclare(strict_types=1);\nreturn ['database' => ['dsn' => 'mysql:host=localhost;dbname=test', 'username' => 'test', 'password' => 'test'], 'app_secret' => '" . str_repeat('a', 64) . "', 'widget_language' => 'ja', 'click_success_message' => 'like02', 'like_count_color' => '#767676'];\n", LOCK_EX);
}
require_once dirname(__DIR__) . '/src/bootstrap.php';

$settingsPath = dirname(__DIR__) . '/config/button_display_settings.json';
$original = is_file($settingsPath) ? file_get_contents($settingsPath) : null;
$count = 0;
$assert = static function (bool $condition, string $message) use (&$count): void {
    $count++;
    if (!$condition) throw new RuntimeException('FAILED: ' . $message);
};

try {
    $presets = pollyanna_button_symbol_presets();
    $assert(array_keys($presets) === ['heart', 'diamond', 'spade', 'club', 'star', 'music'], 'preset order');
    $assert($presets === [
        'heart' => ['unliked' => '♡', 'liked' => '♥'],
        'diamond' => ['unliked' => '♢', 'liked' => '♦'],
        'spade' => ['unliked' => '♤', 'liked' => '♠'],
        'club' => ['unliked' => '♧', 'liked' => '♣'],
        'star' => ['unliked' => '☆', 'liked' => '★'],
        'music' => ['unliked' => '♭', 'liked' => '♪'],
    ], 'preset symbols');

    file_put_contents($settingsPath, "{invalid\n", LOCK_EX);
    $assert(pollyanna_button_display_settings() === [
        'preset' => 'heart', 'unliked_color' => '#666666', 'liked_color' => '#e53955',
    ], 'invalid settings fall back safely');

    foreach ($presets as $preset => $symbols) {
        pollyanna_save_button_display_settings($preset, '#CCCCCC', '#A1B2C3');
        $saved = pollyanna_button_display_settings();
        $assert($saved === ['preset' => $preset, 'unliked_color' => '#cccccc', 'liked_color' => '#a1b2c3'], 'saved preset: ' . $preset);
        $runtime = pollyanna_widget_runtime_text();
        $assert($runtime['symbol_unliked'] === $symbols['unliked'] && $runtime['symbol_liked'] === $symbols['liked'], 'runtime symbols: ' . $preset);
        $assert($runtime['symbol_unliked_color'] === '#cccccc' && $runtime['symbol_liked_color'] === '#a1b2c3', 'runtime colors: ' . $preset);
    }

    foreach ([
        ['emoji', '#666666', '#e53955'],
        ['heart', '#ffffff', '#e53955'],
        ['heart', '#666666', 'red'],
    ] as [$preset, $unlikedColor, $likedColor]) {
        try {
            pollyanna_save_button_display_settings($preset, $unlikedColor, $likedColor);
            $assert(false, 'invalid settings rejected');
        } catch (InvalidArgumentException) {
            $assert(true, 'invalid settings rejected');
        }
    }

    echo "Phase 3 button display tests passed ({$count} assertions).\n";
} finally {
    if ($original === null) {
        if (is_file($settingsPath)) unlink($settingsPath);
    } else {
        file_put_contents($settingsPath, $original, LOCK_EX);
    }
    if ($originalConfig === null) {
        if (is_file($configPath)) unlink($configPath);
    } else {
        file_put_contents($configPath, $originalConfig, LOCK_EX);
    }
}
