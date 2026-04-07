<?php

namespace Escalated\Cli;

use Escalated\Services\AutomationRunner;
use WP_CLI;

/**
 * Manage Escalated automations.
 */
class AutomationCommand
{
    /**
     * Run all active automations against open tickets.
     *
     * ## EXAMPLES
     *
     *     wp escalated run-automations
     *
     * @when after_wp_load
     */
    public function run_automations($args, $assoc_args): void
    {
        WP_CLI::log('Running Escalated automations...');

        $runner = new AutomationRunner;
        $affected = $runner->run();

        if ($affected > 0) {
            WP_CLI::success(sprintf('%d ticket(s) affected by automations.', $affected));
        } else {
            WP_CLI::log('No tickets matched any automation conditions.');
        }
    }

    /**
     * Register WP-CLI commands.
     */
    public static function register(): void
    {
        if (! defined('WP_CLI') || ! WP_CLI) {
            return;
        }

        WP_CLI::add_command('escalated run-automations', [new self, 'run_automations']);
    }
}
