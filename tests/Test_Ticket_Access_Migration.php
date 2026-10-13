<?php

use Escalated\Activator;
use Escalated\Escalated;
use Escalated\Models\Department;
use Escalated\Models\Setting;
use Escalated\Models\Ticket;

class Test_Ticket_Access_Migration extends WP_UnitTestCase
{
    /** @dataProvider connections */
    public function test_populated_upgrade_and_visibility_with_custom_prefix(bool $separate): void
    {
        $database = $separate ? getenv('ESCALATED_ACCESS_TEST_DB') : DB_NAME;
        if (! $database) {
            $this->markTestSkipped('Set ESCALATED_ACCESS_TEST_DB to exercise a separate database.');
        }
        wp_set_current_user(0);
        $original = Escalated::db();
        $schema_version = get_option('escalated_schema_version');
        $connection = new wpdb(DB_USER, DB_PASSWORD, $database, DB_HOST);
        $prefix = 'Access_'.bin2hex(random_bytes(4)).'_';
        $connection->set_prefix($prefix);
        $property = new ReflectionProperty(Escalated::class, 'db');
        $property->setAccessible(true);
        $property->setValue(null, $connection);
        try {
            $table = Ticket::table();
            $this->assertNotFalse($connection->query("CREATE TABLE `{$table}` (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, reference VARCHAR(20) NOT NULL, subject VARCHAR(255), PRIMARY KEY (id), UNIQUE KEY reference (reference))"));
            $connection->insert($table, ['reference' => 'LEG-00001', 'subject' => 'Preserved']);
            $legacy = (int) $connection->insert_id;
            // Simulate an upgrade even when the plugin version has not changed.
            delete_option('escalated_schema_version');
            Activator::maybe_upgrade();
            global $wpdb;
            $this->assertSame($original, $wpdb, 'Schema installation must restore the WordPress connection.');
            $ticket = Ticket::find($legacy);
            $this->assertSame('Preserved', $ticket->subject);
            $this->assertNull($ticket->account_type);
            $this->assertNull($ticket->account_id);
            $this->assertSame('all', Setting::get('visibility', 'all'));
            $indexes = $connection->get_results("SHOW INDEX FROM `{$table}` WHERE Key_name = 'account'");
            $this->assertCount(2, $indexes);
            $this->assertSame('account_type', $indexes[0]->Column_name);
            $this->assertSame('account_id', $indexes[1]->Column_name);
            Activator::maybe_upgrade();
            $this->assertSame('Preserved', Ticket::find($legacy)->subject);
            $agent = self::factory()->user->create(['role' => 'escalated_agent']);
            $department = Department::create(['name' => 'Separate data', 'slug' => 'separate']);
            Department::add_agent($department, $agent);
            Ticket::update($legacy, ['department_id' => $department]);
            Setting::set('visibility', 'department');
            wp_set_current_user($agent);
            $this->assertNotNull(Ticket::find($legacy));
            Department::remove_agent($department, $agent);
            $this->assertNull(Ticket::find($legacy));
            $this->assertNotNull(get_userdata($agent));
            $this->assertNull($connection->get_var($connection->prepare('SHOW TABLES LIKE %s', $connection->esc_like($prefix.'users'))));
        } finally {
            wp_set_current_user(0);
            foreach ($connection->get_col($connection->prepare('SHOW TABLES LIKE %s', $connection->esc_like($prefix).'%')) as $table) {
                if (str_starts_with($table, $prefix) && preg_match('/^[A-Za-z0-9_]+$/D', $table)) {
                    $connection->query("DROP TABLE `{$table}`");
                }
            }
            $property->setValue(null, $original);
            update_option('escalated_schema_version', $schema_version);
            $connection->close();
        }
    }

    public static function connections(): array
    {
        return ['custom prefix' => [false], 'separate database' => [true]];
    }
}
