<?php

namespace Escalated\Cli;

use Escalated\Models\AuditLog;
use Escalated\Models\TwoFactor;
use WP_CLI;

/**
 * Clear a user's two-factor enrolment after a lost device
 * (escalated-laravel's escalated:two-factor:reset).
 */
class TwoFactorCommand
{
    /**
     * Remove a user's two-factor enrolment.
     *
     * ## OPTIONS
     *
     * <user>
     * : The user's ID, login or email.
     *
     * ## EXAMPLES
     *
     *     wp escalated two-factor-reset jane@example.com
     *
     * @when after_wp_load
     */
    public function reset($args): void
    {
        $user = self::find_user((string) ($args[0] ?? ''));
        if (! $user) {
            WP_CLI::error('No such user.');

            return;
        }
        self::reset_user((int) $user->ID);
        WP_CLI::success(sprintf('Two-factor reset for %s.', $user->user_login));
    }

    public static function reset_user(int $user_id): void
    {
        TwoFactor::delete_for_user($user_id);
        AuditLog::record('two_factor.reset', 'User', $user_id);
    }

    private static function find_user(string $key): ?\WP_User
    {
        foreach (['id', 'email', 'login'] as $field) {
            if ($field === 'id' && ! ctype_digit($key)) {
                continue;
            }
            $user = get_user_by($field, $key);
            if ($user) {
                return $user;
            }
        }

        return null;
    }

    public static function register(): void
    {
        if (! defined('WP_CLI') || ! WP_CLI) {
            return;
        }

        WP_CLI::add_command('escalated two-factor-reset', [new self, 'reset']);
    }
}
