<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Every selected suite runs in a separate CLI process. Warnings/deprecations fail it.
error_reporting(E_ALL);
set_exception_handler(static function (Throwable $error): void {
    fwrite(STDERR, "Regression test failed; exception details suppressed.\n");
    exit(1);
});
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) return false;
    // Do not echo arbitrary exception text or configuration values in the runner.
    throw new ErrorException('Regression diagnostic (severity ' . $severity . ').', 0, $severity, $file, $line);
});
