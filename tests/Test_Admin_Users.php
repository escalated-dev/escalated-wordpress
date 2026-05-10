<?php

/**
 * Tests for the Admin_Users role-management surface.
 *
 * Mirrors escalated-laravel's UserControllerTest (PR #94) one-to-one,
 * with the WordPress deviation that "admin" and "agent" map to WP roles
 * (escalated_admin, escalated_agent) instead of host `is_admin` /
 * `is_agent` columns. See Admin_Users::update_role for the cascade rules.
 */

use Escalated\Admin\Admin_Users;

class Test_Admin_Users extends WP_UnitTestCase
{
    private int $admin_id;

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        $this->admin_id = $this->factory->user->create([
            'role' => 'escalated_admin',
            'user_email' => 'admin@example.com',
        ]);
    }

    /**
     * Helper: refresh a WP_User instance so role changes from update_role()
     * are reflected on the in-memory object.
     */
    private function refresh(int $user_id): \WP_User
    {
        clean_user_cache($user_id);

        return new \WP_User($user_id);
    }

    // =========================================================================
    // 1. Lists users with their admin/agent flags for an admin
    // =========================================================================

    public function test_render_lists_users_with_admin_and_agent_flags(): void
    {
        $this->factory->user->create([
            'role' => 'subscriber',
            'user_email' => 'customer@example.com',
        ]);
        $this->factory->user->create([
            'role' => 'escalated_agent',
            'user_email' => 'agent@example.com',
        ]);

        wp_set_current_user($this->admin_id);

        ob_start();
        (new Admin_Users)->render();
        $html = ob_get_clean();

        $this->assertStringContainsString('admin@example.com', $html);
        $this->assertStringContainsString('customer@example.com', $html);
        $this->assertStringContainsString('agent@example.com', $html);
    }

    // =========================================================================
    // 2. Blocks non-admins from the user list — caller's gate
    // =========================================================================

    public function test_non_admin_lacks_user_manage_capability(): void
    {
        // The admin menu registers this page with the `escalated_user_manage`
        // capability. WordPress refuses to render submenu pages the user
        // does not have permission for. Verify both the escalated_agent and
        // escalated_light_agent roles lack that cap — they're the only
        // non-admin Escalated roles a real user is likely to hold.
        $agent_id = $this->factory->user->create(['role' => 'escalated_agent']);
        $light_id = $this->factory->user->create(['role' => 'escalated_light_agent']);

        wp_set_current_user($agent_id);
        $this->assertFalse(current_user_can('escalated_user_manage'));

        wp_set_current_user($light_id);
        $this->assertFalse(current_user_can('escalated_user_manage'));

        wp_set_current_user($this->admin_id);
        $this->assertTrue(current_user_can('escalated_user_manage'));
    }

    // =========================================================================
    // 3. Promotes a user to admin via the panel (admin → also agent)
    // =========================================================================

    public function test_promotes_user_to_admin_also_grants_agent(): void
    {
        $target_id = $this->factory->user->create([
            'role' => 'subscriber',
            'user_email' => 'someone@example.com',
        ]);

        $result = Admin_Users::update_role($target_id, 'admin', true, $this->admin_id);

        $this->assertTrue($result['ok']);

        $target = $this->refresh($target_id);
        $this->assertTrue(Admin_Users::user_is_admin($target));
        $this->assertTrue(Admin_Users::user_is_agent($target));
        $this->assertContains(Admin_Users::ADMIN_ROLE, (array) $target->roles);
        $this->assertContains(Admin_Users::AGENT_ROLE, (array) $target->roles);
    }

    // =========================================================================
    // 4. Promotes a user to agent only (does not also grant admin)
    // =========================================================================

    public function test_promotes_user_to_agent_only(): void
    {
        $target_id = $this->factory->user->create([
            'role' => 'subscriber',
            'user_email' => 'someone@example.com',
        ]);

        $result = Admin_Users::update_role($target_id, 'agent', true, $this->admin_id);

        $this->assertTrue($result['ok']);

        $target = $this->refresh($target_id);
        $this->assertTrue(Admin_Users::user_is_agent($target));
        $this->assertFalse(Admin_Users::user_is_admin($target));
        $this->assertContains(Admin_Users::AGENT_ROLE, (array) $target->roles);
        $this->assertNotContains(Admin_Users::ADMIN_ROLE, (array) $target->roles);
    }

    // =========================================================================
    // 5. Prevents admins from demoting themselves
    // =========================================================================

    public function test_prevents_admin_from_demoting_themselves(): void
    {
        $result = Admin_Users::update_role($this->admin_id, 'admin', false, $this->admin_id);

        $this->assertFalse($result['ok']);
        $this->assertSame('self_demote', $result['error']);

        $admin = $this->refresh($this->admin_id);
        $this->assertTrue(Admin_Users::user_is_admin($admin));
        $this->assertContains(Admin_Users::ADMIN_ROLE, (array) $admin->roles);
    }

    // =========================================================================
    // 6. Demotes an admin and turns off agent in one step
    // =========================================================================

    public function test_revoking_agent_from_admin_also_revokes_admin(): void
    {
        // A second admin, so the operator can demote them without tripping
        // the self-demote guard.
        $target_id = $this->factory->user->create([
            'role' => 'escalated_admin',
            'user_email' => 'someone@example.com',
        ]);
        // Real admins are agents too — mirror that here so the test starts
        // from the same baseline as the Laravel reference.
        (new \WP_User($target_id))->add_role(Admin_Users::AGENT_ROLE);

        $result = Admin_Users::update_role($target_id, 'agent', false, $this->admin_id);

        $this->assertTrue($result['ok']);

        $target = $this->refresh($target_id);
        $this->assertFalse(Admin_Users::user_is_agent($target));
        $this->assertFalse(Admin_Users::user_is_admin($target));
        $this->assertNotContains(Admin_Users::ADMIN_ROLE, (array) $target->roles);
        $this->assertNotContains(Admin_Users::AGENT_ROLE, (array) $target->roles);
    }

    // =========================================================================
    // 7. Filters users by search term
    // =========================================================================

    public function test_filters_users_by_search_term(): void
    {
        $this->factory->user->create([
            'role' => 'subscriber',
            'user_email' => 'jane@acme.test',
            'user_login' => 'jane_acme',
        ]);
        $this->factory->user->create([
            'role' => 'subscriber',
            'user_email' => 'bob@globex.test',
            'user_login' => 'bob_globex',
        ]);

        wp_set_current_user($this->admin_id);

        // Simulate the GET search query the form would submit.
        $_GET = ['s' => 'acme'];

        ob_start();
        (new Admin_Users)->render();
        $html = ob_get_clean();

        $_GET = [];

        $this->assertStringContainsString('jane@acme.test', $html);
        $this->assertStringNotContainsString('bob@globex.test', $html);
    }
}
