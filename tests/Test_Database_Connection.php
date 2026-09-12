<?php

/**
 * Tests for the database connection Escalated's own tables live on.
 *
 * Every query in the plugin used the global $wpdb with no way to change it,
 * which made the plugin unusable on any site that partitions its data. Those
 * queries now resolve through Escalated::db(), which returns the global $wpdb
 * unless the site defines its own connection.
 *
 * The behaviour worth pinning is the unconfigured case: it must be the same
 * instance, not merely an equivalent one, or 289 call sites have quietly
 * changed which database they talk to.
 */

use Escalated\Escalated;

class Test_Database_Connection extends WP_UnitTestCase
{
    public function tear_down()
    {
        Escalated::flush_db();
        parent::tear_down();
    }

    public function test_returns_the_global_wpdb_when_no_connection_is_defined()
    {
        global $wpdb;

        Escalated::flush_db();

        // Identity, not equality. A second wpdb pointing at the same database
        // would pass an equality check and still be a behaviour change --
        // separate connection, separate transaction scope, separate insert_id.
        $this->assertSame($wpdb, Escalated::db());
    }

    public function test_table_names_come_from_the_resolved_connection()
    {
        global $wpdb;

        $this->assertSame($wpdb->prefix.'escalated_tickets', Escalated::table('tickets'));
        $this->assertSame($wpdb->prefix.'escalated_replies', Escalated::table('replies'));
    }

    public function test_the_resolved_connection_is_reused()
    {
        // wpdb connects in its constructor, so resolving per query would open a
        // connection per query.
        $this->assertSame(Escalated::db(), Escalated::db());
    }

    public function test_queries_run_against_the_resolved_connection()
    {
        $table = Escalated::table('settings');

        $exists = Escalated::db()->get_var(
            Escalated::db()->prepare('SHOW TABLES LIKE %s', $table)
        );

        $this->assertSame($table, $exists, 'Escalated::db() must reach the database its tables were created in.');
    }

    /**
     * The plugin must not reach for the global directly any more, or a site
     * with a second database would have some queries on one connection and
     * some on the other -- which reads as data appearing and disappearing.
     */
    public function test_no_plugin_code_binds_the_global_wpdb_directly()
    {
        $offenders = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__).'/includes', FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            // Escalated::db() itself needs the real global to fall back to.
            if ($file->getFilename() === 'class-escalated.php') {
                continue;
            }

            if (str_contains((string) file_get_contents($file->getPathname()), 'global $wpdb;')) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'these files bind the global $wpdb instead of Escalated::db(): '.implode(', ', $offenders)
        );
    }

    /**
     * WordPress core tables belong to WordPress. The plugin reaches them
     * through get_userdata()/WP_User_Query, which use the global $wpdb
     * internally -- it must never issue raw SQL against them, because those
     * tables do not move with Escalated.
     */
    public function test_no_plugin_code_queries_wordpress_core_tables_directly()
    {
        $offenders = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__).'/includes', FilesystemIterator::SKIP_DOTS)
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            foreach (['->users', '->usermeta', '->posts', '->postmeta', '->options'] as $core) {
                if (str_contains($source, '$wpdb'.$core)) {
                    $offenders[] = $file->getFilename().' ('.$core.')';
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'these files query WordPress core tables through $wpdb: '.implode(', ', $offenders)
        );
    }
}
