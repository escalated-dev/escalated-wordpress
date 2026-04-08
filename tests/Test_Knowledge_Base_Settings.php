<?php

/**
 * Tests for the KnowledgeBaseService toggle settings.
 *
 * Covers enabled/disabled checks, public/private access,
 * feedback toggle, settings CRUD, and permission callbacks.
 */

use Escalated\Models\Setting;
use Escalated\Services\KnowledgeBaseService;

class Test_Knowledge_Base_Settings extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();
    }

    // =========================================================================
    // Default Settings Tests
    // =========================================================================

    public function test_kb_enabled_by_default(): void
    {
        $this->assertTrue(KnowledgeBaseService::is_enabled());
    }

    public function test_kb_public_by_default(): void
    {
        $this->assertTrue(KnowledgeBaseService::is_public());
    }

    public function test_kb_feedback_enabled_by_default(): void
    {
        $this->assertTrue(KnowledgeBaseService::is_feedback_enabled());
    }

    // =========================================================================
    // Toggle Tests
    // =========================================================================

    public function test_disable_knowledge_base(): void
    {
        Setting::set('knowledge_base_enabled', '0');

        $this->assertFalse(KnowledgeBaseService::is_enabled());
    }

    public function test_enable_knowledge_base(): void
    {
        Setting::set('knowledge_base_enabled', '0');
        Setting::set('knowledge_base_enabled', '1');

        $this->assertTrue(KnowledgeBaseService::is_enabled());
    }

    public function test_make_kb_private(): void
    {
        Setting::set('knowledge_base_public', '0');

        $this->assertFalse(KnowledgeBaseService::is_public());
    }

    public function test_disable_feedback(): void
    {
        Setting::set('knowledge_base_feedback_enabled', '0');

        $this->assertFalse(KnowledgeBaseService::is_feedback_enabled());
    }

    // =========================================================================
    // Get Settings Tests
    // =========================================================================

    public function test_get_settings_returns_all_keys(): void
    {
        $settings = KnowledgeBaseService::get_settings();

        $this->assertArrayHasKey('knowledge_base_enabled', $settings);
        $this->assertArrayHasKey('knowledge_base_public', $settings);
        $this->assertArrayHasKey('knowledge_base_feedback_enabled', $settings);
    }

    public function test_get_settings_reflects_changes(): void
    {
        Setting::set('knowledge_base_enabled', '0');
        Setting::set('knowledge_base_public', '0');
        Setting::set('knowledge_base_feedback_enabled', '0');

        $settings = KnowledgeBaseService::get_settings();

        $this->assertFalse($settings['knowledge_base_enabled']);
        $this->assertFalse($settings['knowledge_base_public']);
        $this->assertFalse($settings['knowledge_base_feedback_enabled']);
    }

    // =========================================================================
    // Update Settings Tests
    // =========================================================================

    public function test_update_settings(): void
    {
        KnowledgeBaseService::update_settings([
            'knowledge_base_enabled' => false,
            'knowledge_base_public' => false,
            'knowledge_base_feedback_enabled' => false,
        ]);

        $this->assertFalse(KnowledgeBaseService::is_enabled());
        $this->assertFalse(KnowledgeBaseService::is_public());
        $this->assertFalse(KnowledgeBaseService::is_feedback_enabled());
    }

    public function test_update_settings_ignores_unknown_keys(): void
    {
        KnowledgeBaseService::update_settings([
            'unknown_setting' => 'value',
        ]);

        // Should not throw or affect anything.
        $this->assertTrue(KnowledgeBaseService::is_enabled());
    }

    // =========================================================================
    // Access Guard Tests
    // =========================================================================

    public function test_can_access_when_enabled_and_public(): void
    {
        Setting::set('knowledge_base_enabled', '1');
        Setting::set('knowledge_base_public', '1');

        $this->assertTrue(KnowledgeBaseService::can_access());
    }

    public function test_cannot_access_when_disabled(): void
    {
        Setting::set('knowledge_base_enabled', '0');

        $this->assertFalse(KnowledgeBaseService::can_access());
    }

    public function test_cannot_access_private_kb_without_auth(): void
    {
        Setting::set('knowledge_base_enabled', '1');
        Setting::set('knowledge_base_public', '0');

        // Not logged in.
        wp_set_current_user(0);

        $this->assertFalse(KnowledgeBaseService::can_access());
    }

    public function test_can_access_private_kb_with_auth(): void
    {
        Setting::set('knowledge_base_enabled', '1');
        Setting::set('knowledge_base_public', '0');

        $user_id = $this->factory->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $this->assertTrue(KnowledgeBaseService::can_access());
    }

    // =========================================================================
    // Permission Callback Tests
    // =========================================================================

    public function test_permission_check_returns_error_when_disabled(): void
    {
        Setting::set('knowledge_base_enabled', '0');

        $result = KnowledgeBaseService::permission_check();

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('escalated_kb_disabled', $result->get_error_code());
    }

    public function test_permission_check_returns_error_when_private_and_not_logged_in(): void
    {
        Setting::set('knowledge_base_enabled', '1');
        Setting::set('knowledge_base_public', '0');
        wp_set_current_user(0);

        $result = KnowledgeBaseService::permission_check();

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('escalated_kb_private', $result->get_error_code());
    }

    public function test_permission_check_returns_true_when_enabled_and_public(): void
    {
        Setting::set('knowledge_base_enabled', '1');
        Setting::set('knowledge_base_public', '1');

        $result = KnowledgeBaseService::permission_check();

        $this->assertTrue($result);
    }

    public function test_permission_check_returns_true_when_private_and_logged_in(): void
    {
        Setting::set('knowledge_base_enabled', '1');
        Setting::set('knowledge_base_public', '0');

        $user_id = $this->factory->user->create(['role' => 'subscriber']);
        wp_set_current_user($user_id);

        $result = KnowledgeBaseService::permission_check();

        $this->assertTrue($result);
    }
}
