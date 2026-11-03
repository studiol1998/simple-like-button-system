<?php
declare(strict_types=1);

/**
 * Informational policy only: loading this class never stops an installation or API.
 * Upstream version floors cover PHP2Shell, not all present or future vulnerabilities.
 * No configuration flag or vendor claim can turn an unverified backport into a pass.
 */
final class RuntimePolicy
{
    public const SUPPORTED = 'supported';
    public const BACKPORT_VERIFICATION_REQUIRED = 'backport_verification_required';
    public const UNSUPPORTED = 'unsupported';

    private const MINIMUMS = [
        '8.3' => '8.3.36',
        '8.4' => '8.4.27',
        '8.5' => '8.5.12',
    ];

    /** Inspect this process only. A CLI result says nothing about the Web worker. */
    public static function current(): array
    {
        return self::evaluate(PHP_VERSION, PHP_SAPI);
    }

    /**
     * Pure, injectable version/SAPI classification; does not read app config or DB.
     * Numeric package revisions (e.g. -0ubuntu0.24.04.13) are recognized, not trusted.
     * Unknown suffixes and prereleases fail closed rather than guessing their meaning.
     *
     * @return array{status:string, reason_code:string, reason:string, version:?string,
     * branch:?string, minimum_fixed_version:?string, supported_branch:bool,
     * meets_upstream_minimum:bool, package_revision:bool, execution_context:string,
     * scope_notice:string, security_notice:string}
     */
    public static function evaluate(string $version, string $sapi): array
    {
        $result = [
            'status' => self::UNSUPPORTED,
            'reason_code' => 'invalid_version',
            'reason' => 'The version is not a recognized stable PHP release or numeric package revision.',
            'version' => null,
            'branch' => null,
            'minimum_fixed_version' => null,
            'supported_branch' => false,
            'meets_upstream_minimum' => false,
            'package_revision' => false,
            'execution_context' => match ($sapi) {
                'cli', 'phpdbg' => 'cli',
                'apache2handler', 'cgi-fcgi', 'fpm-fcgi', 'litespeed', 'cli-server', 'frankenphp' => 'web',
                default => 'unknown',
            },
            'scope_notice' => 'This result describes only the inspected PHP process. Verify Web PHP separately from CLI PHP.',
            'security_notice' => 'Use the latest security release in a supported branch. Version strings alone cannot prove vendor backports, dependency fixes, or overall runtime safety.',
        ];

        // Bounded canonical numbers avoid ambiguous forms, coercion and oversized input.
        if (strlen($version) > 100 || preg_match(
            '/\A(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,4})(-[0-9][A-Za-z0-9.+~_-]{0,63})?\z/D',
            $version,
            $parts
        ) !== 1) {
            return $result;
        }
        $core = $parts[1] . '.' . $parts[2] . '.' . $parts[3];
        if (isset($parts[4]) && preg_match('/alpha|beta|rc|dev|snapshot/i', $parts[4]) === 1) {
            return $result;
        }
        $branch = $parts[1] . '.' . $parts[2];
        $result['version'] = $core;
        $result['branch'] = $branch;
        $result['package_revision'] = isset($parts[4]) && $parts[4] !== '';
        if (!isset(self::MINIMUMS[$branch])) {
            $result['reason_code'] = 'unsupported_branch';
            $result['reason'] = 'This PHP branch is outside the supported 8.3, 8.4 and 8.5 policy. Existing beta data is not inspected or modified.';
            return $result;
        }

        $result['supported_branch'] = true;
        $result['minimum_fixed_version'] = self::MINIMUMS[$branch];
        $result['meets_upstream_minimum'] = version_compare($core, self::MINIMUMS[$branch], '>=');
        if ($result['meets_upstream_minimum']) {
            $result['status'] = self::SUPPORTED;
            $result['reason_code'] = 'upstream_minimum_met';
            $result['reason'] = 'The supported branch meets the upstream PHP2Shell fix floor; this is not a full security or compatibility certification.';
        } elseif ($result['package_revision']) {
            $result['status'] = self::BACKPORT_VERIFICATION_REQUIRED;
            $result['reason_code'] = 'vendor_backport_unverified';
            $result['reason'] = 'The upstream version is below the fix floor. Verify the exact vendor package against an official advisory; the revision does not prove a backport.';
        } else {
            $result['reason_code'] = 'below_upstream_minimum';
            $result['reason'] = 'The upstream version is below the PHP2Shell fix floor. A vendor may backport without changing this string, but separate official evidence is required.';
        }
        return $result;
    }
}
