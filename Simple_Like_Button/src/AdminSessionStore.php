<?php
declare(strict_types=1);

/** Trusted code dependency, not a configuration/HTTP escape hatch. */
interface AdminSessionStore extends SessionHandlerInterface, SessionUpdateTimestampHandlerInterface
{
    public function failed(): bool;
}

/** Observe PHP's native files callbacks without releasing their exclusive lock. */
final class AdminFileSessionStore extends SessionHandler implements AdminSessionStore
{
    private bool $failure = false;
    private string $directory;

    public function __construct()
    {
        if (session_module_name() !== 'files') throw new RuntimeException('Unsupported admin session storage.');
        $path = (string) ini_get('session.save_path');
        if (str_contains($path, ';')) {
            $parts = explode(';', $path);
            if (count($parts) !== 3 || $parts[0] !== '0' || preg_match('/\A[0-7]{3,4}\z/D', $parts[1]) !== 1) {
                throw new RuntimeException('Unsupported admin session directory layout.');
            }
            $path = $parts[2];
        }
        $resolved = realpath($path === '' ? sys_get_temp_dir() : $path);
        if ($resolved === false || !is_dir($resolved)) throw new RuntimeException('Administrator session directory is unavailable.');
        $this->directory = $resolved;
    }

    public function failed(): bool { return $this->failure; }
    public function open(string $path, string $name): bool
    {
        $ok = parent::open($path, $name);
        if (!$ok) $this->failure = true;
        return $ok;
    }
    public function read(string $id): string|false
    {
        $data = parent::read($id); // Native files read acquires and retains flock.
        if ($data === false) $this->failure = true;
        return $data;
    }
    public function write(string $id, string $data): bool
    {
        $ok = parent::write($id, $data);
        if (!$ok) $this->failure = true;
        return $ok;
    }
    public function close(): bool
    {
        $ok = parent::close(); // Release only after the final write/destroy.
        if (!$ok) $this->failure = true;
        return $ok;
    }
    public function destroy(string $id): bool
    {
        // A waiting reader may already have opened the soon-to-be-unlinked inode.
        // Clear its bytes while holding the native lock before unlinking it, so
        // that reader cannot recover the old authentication after logout/rotation.
        if (!$this->write($id, '')) return false;
        $ok = parent::destroy($id);
        if (!$ok) $this->failure = true;
        return $ok;
    }
    public function validateId(string $id): bool
    {
        if (preg_match('/\A[A-Za-z0-9,-]{1,256}\z/D', $id) !== 1) return false;
        $path = $this->directory . DIRECTORY_SEPARATOR . 'sess_' . $id;
        clearstatcache(true, $path);
        return is_file($path) && !is_link($path);
    }
    public function updateTimestamp(string $id, string $data): bool
    {
        // Use the observed native write rather than an unobserved lazy timestamp update.
        return $this->write($id, $data);
    }
}
