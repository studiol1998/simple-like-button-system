<?php
declare(strict_types=1);

final class PollyannaOrigin
{
    public function __construct(
        public readonly string $scheme,
        public readonly string $host,
        public readonly int $port
    ) {}

    public static function parse(string $value): self
    {
        if ($value === '' || strcasecmp($value, 'null') === 0 || preg_match('/[\x00-\x20]/', $value)) {
            throw new InvalidArgumentException('Invalid origin.');
        }
        $parts = parse_url($value);
        if (!is_array($parts) || array_key_exists('user', $parts) || array_key_exists('pass', $parts)
            || array_key_exists('path', $parts) || array_key_exists('query', $parts) || array_key_exists('fragment', $parts)) {
            throw new InvalidArgumentException('Invalid origin.');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || !self::validHost($host)) {
            throw new InvalidArgumentException('Invalid origin.');
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if ($port < 1 || $port > 65535) throw new InvalidArgumentException('Invalid origin.');
        return new self($scheme, trim($host, '[]'), $port);
    }

    public static function parseHost(string $host): string
    {
        $host = strtolower(trim($host));
        if (!self::validHost($host)) throw new InvalidArgumentException('Invalid host.');
        return trim($host, '[]');
    }

    private static function validHost(string $host): bool
    {
        $plain = trim($host, '[]');
        if ($plain === 'localhost' || filter_var($plain, FILTER_VALIDATE_IP) !== false) return true;
        if (strlen($plain) > 253 || !preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*\z/i', $plain)) return false;
        return true;
    }

    public function isLocalOrIp(): bool
    {
        return self::isLocalOrIpHost($this->host);
    }

    public static function isLocalOrIpHost(string $host): bool
    {
        $host = trim(strtolower($host), '[]');
        return $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    public static function normalizeSiteSettings(string $originValue, string $baseDomainValue, bool $includeSubdomains): array
    {
        $origin = self::parse($originValue);
        $baseDomain = self::parseHost($baseDomainValue);
        if (!PollyannaOriginPolicy::hostMatches($origin->host, $baseDomain, true)) {
            throw new InvalidArgumentException('The origin host does not belong to the base domain.');
        }
        if (self::isLocalOrIpHost($origin->host) || self::isLocalOrIpHost($baseDomain)) {
            $includeSubdomains = false;
        }
        return [
            'origin' => $origin,
            'base_domain' => $baseDomain,
            'include_subdomains' => $includeSubdomains,
        ];
    }

    public function serialize(): string
    {
        $host = str_contains($this->host, ':') ? '[' . $this->host . ']' : $this->host;
        $default = ($this->scheme === 'https' && $this->port === 443) || ($this->scheme === 'http' && $this->port === 80);
        return $this->scheme . '://' . $host . ($default ? '' : ':' . $this->port);
    }
}

final class PollyannaOriginPolicy
{
    public function __construct(private readonly array $config, private readonly array $externalOrigins) {}

    public function allows(PollyannaOrigin $candidate): bool
    {
        try {
            $base = PollyannaOrigin::parse((string) ($this->config['site_origin'] ?? ''));
            if ($this->matches($candidate, $base, false)) return true;
            $settings = PollyannaOrigin::normalizeSiteSettings(
                (string) ($this->config['site_origin'] ?? ''),
                (string) ($this->config['site_base_domain'] ?? ''),
                ($this->config['site_base_domain_include_subdomains'] ?? false) === true
            );
            $baseHost = $settings['base_domain'];
            $include = $settings['include_subdomains'];
            if ($include && $candidate->scheme === $base->scheme && $candidate->port === $base->port && self::hostMatches($candidate->host, $baseHost, true)) return true;
        } catch (InvalidArgumentException) {
            return false;
        }
        foreach ($this->externalOrigins as $allowed) {
            if (!is_array($allowed)) continue;
            $reference = new PollyannaOrigin((string) $allowed['scheme'], (string) $allowed['host'], (int) $allowed['port']);
            if ($this->matches($candidate, $reference, (bool) $allowed['include_subdomains'])) return true;
        }
        return false;
    }

    private function matches(PollyannaOrigin $candidate, PollyannaOrigin $allowed, bool $subdomains): bool
    {
        return $candidate->scheme === $allowed->scheme && $candidate->port === $allowed->port
            && self::hostMatches($candidate->host, $allowed->host, $subdomains && !$allowed->isLocalOrIp());
    }

    public static function hostMatches(string $candidate, string $allowed, bool $subdomains): bool
    {
        return $candidate === $allowed || ($subdomains && str_ends_with($candidate, '.' . $allowed));
    }

    public function allowsUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) return false;
        $host = str_contains((string) $parts['host'], ':') ? '[' . $parts['host'] . ']' : $parts['host'];
        $origin = $parts['scheme'] . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');
        try { return $this->allows(PollyannaOrigin::parse($origin)); } catch (InvalidArgumentException) { return false; }
    }
}
