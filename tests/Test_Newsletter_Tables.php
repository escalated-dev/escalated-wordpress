<?php

/**
 * Newsletter schema smoke tests.
 */

use Escalated\Activator;
use Escalated\Escalated;

class Test_Newsletter_Tables extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        Activator::activate();
    }

    public function test_five_newsletter_tables_exist(): void
    {
        global $wpdb;
        $names = [
            'newsletter_lists',
            'newsletter_list_members',
            'newsletter_templates',
            'newsletters',
            'newsletter_deliveries',
        ];
        foreach ($names as $name) {
            $table = Escalated::table($name);
            $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
            $this->assertSame($table, $found, "Missing table {$name}");
        }
    }

    public function test_contacts_has_marketing_opt_out_column(): void
    {
        global $wpdb;
        $table = Escalated::table('contacts');
        $col = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s",
            'marketing_opt_out_at'
        ));
        $this->assertSame('marketing_opt_out_at', $col);
    }

    public function test_deliveries_has_next_attempt_at(): void
    {
        global $wpdb;
        $table = Escalated::table('newsletter_deliveries');
        $col = $wpdb->get_var($wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s",
            'next_attempt_at'
        ));
        $this->assertSame('next_attempt_at', $col);
    }
}
