<?php
declare(strict_types=1);
require_once __DIR__ . '/AdminSessionStore.php';

/** Admin-only lifecycle; foreign session records are discarded without rewriting. */
final class AdminSession
{
    private bool $healthy = true;
    private bool $loggedOut = false;
    private string $instance = '';
    public function __construct(private ?AdminSessionStore $storage = null) {}
    public const IDLE_SECONDS = 1800;
    public const ABSOLUTE_SECONDS = 28800;

    public function start(array $settings, bool $secure): void
    {
        if (session_status() !== PHP_SESSION_NONE || headers_sent()) $this->fail();
        // Do not change deprecated session options on PHP 8.4+. Require safe settings.
        if (ini_get('session.use_cookies') !== '1' || ini_get('session.use_only_cookies') !== '1'
            || ini_get('session.use_trans_sid') === '1') $this->fail();
        if (@ini_set('session.use_strict_mode', '1') === false || ini_get('session.use_strict_mode') !== '1') $this->fail();
        $name = 'pollyanna_admin_' . $settings['instance_id'];
        $this->instance = $settings['instance_id'];
        if (array_key_exists($name, $_GET) || array_key_exists($name, $_POST)) $this->fail();
        if (session_name($name) === false || !session_set_cookie_params([
            'lifetime' => 0, 'path' => $settings['admin_path'], 'domain' => '',
            'secure' => $secure, 'httponly' => true, 'samesite' => 'Strict',
        ])) $this->fail();
        $this->storage ??= new AdminFileSessionStore();
        if (!$this->checked(fn (): bool => session_set_save_handler($this->storage, true))
            || !$this->checked(static fn (): bool => session_start())) $this->fail();
        // Cookie names do not namespace PHP's underlying session store. If a SID
        // from the installer/another install is supplied, preserve that record
        // and start a new empty admin session instead of rewriting foreign data.
        if ($_SESSION !== [] && ($_SESSION['pollyanna_admin_instance'] ?? null) !== $this->instance) {
            if (!$this->checked(static fn (): bool => session_abort())) $this->fail();
            session_id('');
            if (!$this->checked(static fn (): bool => session_start()) || $_SESSION !== []) $this->fail();
        }
        $_SESSION['pollyanna_admin_instance'] = $this->instance;
        unset($_SESSION['pollyanna_storage_probe']);
    }

    /** Pure authentication expiry/stamp check, with an injectable clock for tests. */
    public static function valid(array $data, ?string $hash, int $now): bool
    {
        $started = $data['pollyanna_authenticated_at'] ?? null;
        $last = $data['pollyanna_last_activity_at'] ?? null;
        $stamp = $data['pollyanna_admin_credential_stamp'] ?? null;
        return ($data['pollyanna_admin_authenticated'] ?? false) === true && $hash !== null
            && is_string($stamp) && hash_equals(hash('sha256', $hash), $stamp)
            && is_int($started) && is_int($last) && $started <= $last && $last <= $now
            && $now - $last < self::IDLE_SECONDS && $now - $started < self::ABSOLUTE_SECONDS;
    }

    public function validate(?string $hash, ?int $now = null): bool
    {
        $now ??= time();
        if (!self::valid($_SESSION, $hash, $now)) {
            if (($_SESSION['pollyanna_admin_authenticated'] ?? false) === true) {
                unset($_SESSION['pollyanna_admin_authenticated'], $_SESSION['pollyanna_admin_credential_stamp'],
                    $_SESSION['pollyanna_authenticated_at'], $_SESSION['pollyanna_last_activity_at']);
                $this->rotate();
                $_SESSION['pollyanna_csrf_token'] = bin2hex(random_bytes(32));
            }
            return false;
        }
        $_SESSION['pollyanna_last_activity_at'] = $now;
        return true;
    }

    public function authenticate(string $hash, ?int $now = null): void
    {
        $this->rotate();
        $_SESSION['pollyanna_admin_authenticated'] = true;
        $_SESSION['pollyanna_admin_credential_stamp'] = hash('sha256', $hash);
        $_SESSION['pollyanna_authenticated_at'] = $_SESSION['pollyanna_last_activity_at'] = $now ?? time();
        $_SESSION['pollyanna_csrf_token'] = bin2hex(random_bytes(32));
        // Keep the native session lock through authorization and admin mutations.
        // The buffered response is released only after observed write/close success.
    }

    public function rotate(): void
    {
        if (!$this->healthy || session_status() !== PHP_SESSION_ACTIVE
            || !$this->checked(static fn (): bool => session_regenerate_id(true))) $this->fail();
    }

    public function logout(): void
    {
        if (!$this->healthy || session_status() !== PHP_SESSION_ACTIVE) $this->fail();
        $_SESSION = [];
        if (!$this->checked(static fn (): bool => session_destroy())) $this->fail();
        $cookie = session_get_cookie_params();
        if (!setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $cookie['path'],
            'domain' => '', 'secure' => $cookie['secure'], 'httponly' => true, 'samesite' => 'Strict'])) $this->fail();
        $this->loggedOut = true;
    }

    /** Called before buffered output/redirects are released, including early exits. */
    public function finish(): void
    {
        if (!$this->healthy) $this->fail();
        if ($this->loggedOut) return;
        if (session_status() !== PHP_SESSION_ACTIVE) $this->fail();
        $_SESSION['pollyanna_admin_instance'] = $this->instance;
        if (!$this->checked(static fn (): bool => session_write_close())) $this->fail();
    }

    private function checked(callable $operation): bool
    {
        $diagnostic = false;
        set_error_handler(static function () use (&$diagnostic): bool { $diagnostic = true; return true; });
        try { $ok = $operation(); }
        catch (Throwable) { $ok = false; }
        finally { restore_error_handler(); }
        return $ok === true && !$diagnostic && !($this->storage?->failed() ?? false);
    }

    private function fail(): never
    {
        $this->healthy = false;
        if (session_status() === PHP_SESSION_ACTIVE) @session_abort();
        throw new RuntimeException('Administrator session storage is unavailable.');
    }
}
