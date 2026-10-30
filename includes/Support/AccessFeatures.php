<?php

namespace Escalated\Support;

/**
 * The install-level access switches (escalated-developer-context ADR
 * 2026-10-29-configurable-access-features; escalated-laravel's
 * AccessFeatures).
 *
 * Each switch is read from configuration only: a constant in wp-config.php
 * (`define('ESCALATED_ROLES_PERMISSIONS', true);`), else the environment
 * variable of the same name, then the `escalated_access_{feature}` filter
 * (code, for a host plugin or a test). Never from the settings table: each
 * one changes who may do what, so no stored setting may flip it. All default
 * off.
 */
final class AccessFeatures
{
    public const ROLES_PERMISSIONS = 'roles_permissions';

    public const TWO_FACTOR = 'two_factor';

    public const SSO = 'sso';

    public static function roles_permissions(): bool
    {
        return self::read(self::ROLES_PERMISSIONS, 'ESCALATED_ROLES_PERMISSIONS');
    }

    public static function two_factor(): bool
    {
        return self::read(self::TWO_FACTOR, 'ESCALATED_TWO_FACTOR');
    }

    /**
     * ESCALATED_TWO_FACTOR_REQUIRED: whether staff must enrol, until an
     * admin saves the two_factor_required setting (which then wins).
     */
    public static function two_factor_required_default(): bool
    {
        return self::read('two_factor_required', 'ESCALATED_TWO_FACTOR_REQUIRED');
    }

    public static function sso(): bool
    {
        return self::read(self::SSO, 'ESCALATED_SSO');
    }

    /**
     * The `features` the screens are told about.
     *
     * @return array{roles_permissions: bool, two_factor: bool, sso: bool}
     */
    public static function shared(): array
    {
        return [
            'roles_permissions' => self::roles_permissions(),
            'two_factor' => self::two_factor(),
            'sso' => self::sso(),
        ];
    }

    private static function read(string $feature, string $name): bool
    {
        if (defined($name)) {
            $value = self::truthy(constant($name));
        } else {
            $env = getenv($name);
            $value = $env !== false && self::truthy($env);
        }

        return (bool) apply_filters('escalated_access_'.$feature, $value);
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }
}
