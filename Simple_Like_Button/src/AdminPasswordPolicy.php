<?php
declare(strict_types=1);

/** Rules for newly set passwords only. Legacy login remains password_verify(). */
final class AdminPasswordPolicy
{
    public static function isValid(string $password): bool
    {
        return strlen($password) <= 72 && !str_contains($password, "\0")
            && preg_match('//u', $password) === 1
            && preg_match_all('/./us', $password) >= 12;
    }

    public static function hash(string $password): string
    {
        if (!self::isValid($password)) throw new InvalidArgumentException('Invalid new administrator password.');
        return password_hash($password, PASSWORD_BCRYPT);
    }
}
