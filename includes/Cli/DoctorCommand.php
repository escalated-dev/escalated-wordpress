<?php

namespace Escalated\Cli;

use Escalated\Support\SetupCheck;
use WP_CLI;

/**
 * `wp escalated doctor`: report setup problems (escalated-laravel's
 * install-time checks). See Support\SetupCheck.
 */
class DoctorCommand
{
    /**
     * Check Escalated's tables, roles, capabilities and recurring jobs.
     *
     * Exits non-zero when anything is wrong.
     *
     * ## EXAMPLES
     *
     *     wp escalated doctor
     *
     * @when after_wp_load
     */
    public function __invoke($args): void
    {
        $issues = SetupCheck::issues();

        if ($issues === []) {
            WP_CLI::success('Escalated is set up correctly.');

            return;
        }

        foreach ($issues as $issue) {
            WP_CLI::warning($issue['message']);
        }

        WP_CLI::error(sprintf('%d setup problem(s) found.', count($issues)));
    }

    public static function register(): void
    {
        if (! defined('WP_CLI') || ! WP_CLI) {
            return;
        }

        WP_CLI::add_command('escalated doctor', new self);
    }
}
