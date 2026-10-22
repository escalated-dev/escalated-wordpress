<?php

namespace Escalated\Services;

/**
 * The placeholder an admin settings screen shows for a stored secret.
 *
 * The screen learns only that a secret is set. Posting the placeholder back
 * means "keep the stored value"; anything else replaces it.
 */
final class SecretMask
{
    public const MASK = '********';

    public static function mask(?string $value): string
    {
        return ($value === null || $value === '') ? '' : self::MASK;
    }

    /**
     * Also accepts the older "abc*****" form, so a settings screen opened
     * before an upgrade does not overwrite the secret with its placeholder.
     *
     * @param  mixed  $value
     */
    public static function is_masked($value): bool
    {
        return is_string($value) && $value !== '' && preg_match('/^.{0,3}\*{3,}$/', $value) === 1;
    }
}
