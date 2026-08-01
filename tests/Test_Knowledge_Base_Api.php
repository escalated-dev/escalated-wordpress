<?php

/**
 * Tests for the functional knowledge base: admin CRUD REST routes and the
 * public widget read endpoints, both backed by the escalated_articles /
 * escalated_article_categories tables.
 *
 * Covers: table creation, admin create -> public list/show, unpublished
 * articles excluded from the public surface, category assignment, capability
 * gating, view-count increment, and helpful/not-helpful feedback.
 */

use Escalated\Models\Article;
use Escalated\Models\ArticleCategory;
use Escalated\Models\Setting;

class Test_Knowledge_Base_Api extends WP_UnitTestCase
{
    private int $admin_id;

    private WP_REST_Server $server;

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        // Public widget endpoints are gated behind the widget_enabled flag.
        Setting::set('widget_enabled', '1');

        $this->admin_id = $this->factory->user->create(['role' => 'escalated_admin']);

        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        global $wp_rest_server;
        $wp_rest_server = null;
        parent::tear_down();
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function json_request(string $method, string $route, ?array $body = null): WP_REST_Request
    {
        $request = new WP_REST_Request($method, $route);
        if ($body !== null) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body(wp_json_encode($body));
        }

        return $request;
    }

    /**
     * @return array<int, string>
     */
    private function public_article_slugs(): array
    {
        $request = new WP_REST_Request('GET', '/escalated/v1/widget/articles');
        $response = $this->server->dispatch($request);
        $this->assertEquals(200, $response->get_status());

        return array_map(static fn ($a) => $a['slug'], $response->get_data());
    }

    private function create_article_as_admin(array $body): array
    {
        wp_set_current_user($this->admin_id);
        $response = $this->server->dispatch($this->json_request('POST', '/escalated/v1/admin/kb/articles', $body));
        $this->assertEquals(201, $response->get_status());

        return $response->get_data();
    }

    // =====================================================================
    // Schema
    // =====================================================================

    public function test_kb_tables_created(): void
    {
        global $wpdb;
        $existing = $wpdb->get_col('SHOW TABLES');

        $this->assertContains($wpdb->prefix.'escalated_articles', $existing);
        $this->assertContains($wpdb->prefix.'escalated_article_categories', $existing);
    }

    // =====================================================================
    // Capability gating
    // =====================================================================

    public function test_admin_articles_index_requires_auth(): void
    {
        wp_set_current_user(0);
        $response = $this->server->dispatch(new WP_REST_Request('GET', '/escalated/v1/admin/kb/articles'));
        $this->assertEquals(401, $response->get_status());
    }

    public function test_agent_can_view_but_not_create_articles(): void
    {
        $agent_id = $this->factory->user->create(['role' => 'escalated_agent']);
        wp_set_current_user($agent_id);

        // kb.view is granted to agents.
        $response = $this->server->dispatch(new WP_REST_Request('GET', '/escalated/v1/admin/kb/articles'));
        $this->assertEquals(200, $response->get_status());

        // kb.create is not.
        $response = $this->server->dispatch($this->json_request('POST', '/escalated/v1/admin/kb/articles', [
            'title' => 'Nope',
            'status' => 'draft',
        ]));
        $this->assertEquals(403, $response->get_status());
    }

    // =====================================================================
    // Admin create -> public list / show
    // =====================================================================

    public function test_admin_create_then_public_list_and_show(): void
    {
        $created = $this->create_article_as_admin([
            'title' => 'Reset your password',
            'body' => '<p>Click the reset link.</p>',
            'status' => 'published',
        ]);

        $this->assertGreaterThan(0, $created['id']);
        $this->assertSame('reset-your-password', $created['article']['slug']);
        $this->assertSame($this->admin_id, $created['article']['author_id']);
        $this->assertNotEmpty($created['article']['published_at']);

        // Admin index lists it.
        wp_set_current_user($this->admin_id);
        $response = $this->server->dispatch(new WP_REST_Request('GET', '/escalated/v1/admin/kb/articles'));
        $this->assertEquals(200, $response->get_status());
        $titles = array_map(static fn ($a) => $a['title'], $response->get_data()['articles']);
        $this->assertContains('Reset your password', $titles);

        // Public list (logged out) includes it.
        wp_set_current_user(0);
        $this->assertContains('reset-your-password', $this->public_article_slugs());

        // Public show returns the sanitised body.
        $response = $this->server->dispatch(new WP_REST_Request('GET', '/escalated/v1/widget/articles/reset-your-password'));
        $this->assertEquals(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame($created['id'], $data['id']);
        $this->assertStringContainsString('Click the reset link.', $data['content']);
    }

    public function test_draft_article_excluded_from_public(): void
    {
        $this->create_article_as_admin([
            'title' => 'Secret draft',
            'body' => 'Not for the public yet.',
            'status' => 'draft',
        ]);

        // Absent from the public list.
        wp_set_current_user(0);
        $this->assertNotContains('secret-draft', $this->public_article_slugs());

        // Public show 404s.
        $response = $this->server->dispatch(new WP_REST_Request('GET', '/escalated/v1/widget/articles/secret-draft'));
        $this->assertEquals(404, $response->get_status());

        // But the admin draft filter still surfaces it.
        wp_set_current_user($this->admin_id);
        $request = new WP_REST_Request('GET', '/escalated/v1/admin/kb/articles');
        $request->set_param('status', 'draft');
        $response = $this->server->dispatch($request);
        $titles = array_map(static fn ($a) => $a['title'], $response->get_data()['articles']);
        $this->assertContains('Secret draft', $titles);
    }

    public function test_update_publishes_draft_and_stamps_published_at(): void
    {
        $created = $this->create_article_as_admin([
            'title' => 'Draft to publish',
            'status' => 'draft',
        ]);
        $id = $created['id'];
        $this->assertEmpty(Article::find($id)->published_at);

        wp_set_current_user($this->admin_id);
        $response = $this->server->dispatch($this->json_request('PATCH', '/escalated/v1/admin/kb/articles/'.$id, [
            'title' => 'Draft to publish',
            'status' => 'published',
        ]));
        $this->assertEquals(200, $response->get_status());
        $this->assertNotEmpty(Article::find($id)->published_at);

        // Now publicly visible.
        wp_set_current_user(0);
        $response = $this->server->dispatch(new WP_REST_Request('GET', '/escalated/v1/widget/articles/draft-to-publish'));
        $this->assertEquals(200, $response->get_status());
    }

    public function test_delete_article_removes_it(): void
    {
        $created = $this->create_article_as_admin([
            'title' => 'Temporary',
            'status' => 'published',
        ]);
        $id = $created['id'];

        wp_set_current_user($this->admin_id);
        $response = $this->server->dispatch(new WP_REST_Request('DELETE', '/escalated/v1/admin/kb/articles/'.$id));
        $this->assertEquals(204, $response->get_status());
        $this->assertNull(Article::find($id));
    }

    // =====================================================================
    // Category assignment + CRUD
    // =====================================================================

    public function test_article_category_assignment(): void
    {
        wp_set_current_user($this->admin_id);

        $cat = $this->server->dispatch($this->json_request('POST', '/escalated/v1/admin/kb/categories', [
            'name' => 'Billing',
        ]));
        $this->assertEquals(201, $cat->get_status());
        $cat_id = $cat->get_data()['id'];
        $this->assertSame('billing', $cat->get_data()['category']['slug']);

        $article = $this->create_article_as_admin([
            'title' => 'Refunds',
            'status' => 'published',
            'category_id' => $cat_id,
        ]);
        $art_id = $article['id'];

        // Admin show reflects the category.
        wp_set_current_user($this->admin_id);
        $response = $this->server->dispatch(new WP_REST_Request('GET', '/escalated/v1/admin/kb/articles/'.$art_id));
        $data = $response->get_data();
        $this->assertSame($cat_id, $data['article']['category_id']);
        $this->assertSame('Billing', $data['article']['category']['name']);

        // Public show exposes the category too.
        wp_set_current_user(0);
        $response = $this->server->dispatch(new WP_REST_Request('GET', '/escalated/v1/widget/articles/refunds'));
        $this->assertEquals(200, $response->get_status());
        $this->assertSame('Billing', $response->get_data()['category']['name']);
    }

    public function test_invalid_category_rejected(): void
    {
        wp_set_current_user($this->admin_id);
        $response = $this->server->dispatch($this->json_request('POST', '/escalated/v1/admin/kb/articles', [
            'title' => 'Bad category',
            'status' => 'draft',
            'category_id' => 999999,
        ]));
        $this->assertEquals(422, $response->get_status());
    }

    public function test_category_index_counts_and_update_delete(): void
    {
        wp_set_current_user($this->admin_id);

        $cat = $this->server->dispatch($this->json_request('POST', '/escalated/v1/admin/kb/categories', [
            'name' => 'Getting Started',
        ]));
        $cat_id = $cat->get_data()['id'];

        $this->create_article_as_admin([
            'title' => 'First steps',
            'status' => 'published',
            'category_id' => $cat_id,
        ]);

        // Index reports the article count for the category.
        wp_set_current_user($this->admin_id);
        $response = $this->server->dispatch(new WP_REST_Request('GET', '/escalated/v1/admin/kb/categories'));
        $this->assertEquals(200, $response->get_status());
        $row = null;
        foreach ($response->get_data()['categories'] as $c) {
            if ($c['id'] === $cat_id) {
                $row = $c;
            }
        }
        $this->assertNotNull($row);
        $this->assertSame(1, $row['articles_count']);

        // Update.
        $response = $this->server->dispatch($this->json_request('PATCH', '/escalated/v1/admin/kb/categories/'.$cat_id, [
            'name' => 'Getting Started Guide',
        ]));
        $this->assertEquals(200, $response->get_status());
        $this->assertSame('Getting Started Guide', ArticleCategory::find($cat_id)->name);

        // Delete.
        $response = $this->server->dispatch(new WP_REST_Request('DELETE', '/escalated/v1/admin/kb/categories/'.$cat_id));
        $this->assertEquals(204, $response->get_status());
        $this->assertNull(ArticleCategory::find($cat_id));
    }

    // =====================================================================
    // View counter + feedback
    // =====================================================================

    public function test_show_increments_view_count(): void
    {
        $created = $this->create_article_as_admin([
            'title' => 'Track a shipment',
            'status' => 'published',
        ]);
        $id = $created['id'];

        wp_set_current_user(0);
        $this->server->dispatch(new WP_REST_Request('GET', '/escalated/v1/widget/articles/track-a-shipment'));
        $this->server->dispatch(new WP_REST_Request('GET', '/escalated/v1/widget/articles/track-a-shipment'));

        $this->assertSame(2, (int) Article::find($id)->view_count);
    }

    public function test_feedback_records_helpful_and_not_helpful(): void
    {
        $created = $this->create_article_as_admin([
            'title' => 'Was this useful',
            'status' => 'published',
        ]);
        $id = $created['id'];

        wp_set_current_user(0);

        $helpful = new WP_REST_Request('POST', '/escalated/v1/widget/articles/was-this-useful/feedback');
        $helpful->set_param('helpful', true);
        $this->assertEquals(200, $this->server->dispatch($helpful)->get_status());

        $not_helpful = new WP_REST_Request('POST', '/escalated/v1/widget/articles/was-this-useful/feedback');
        $not_helpful->set_param('helpful', false);
        $this->assertEquals(200, $this->server->dispatch($not_helpful)->get_status());

        $article = Article::find($id);
        $this->assertSame(1, (int) $article->helpful_count);
        $this->assertSame(1, (int) $article->not_helpful_count);
    }

    public function test_feedback_disabled_returns_404(): void
    {
        Setting::set('knowledge_base_feedback_enabled', '0');

        $this->create_article_as_admin([
            'title' => 'No feedback here',
            'status' => 'published',
        ]);

        wp_set_current_user(0);
        $request = new WP_REST_Request('POST', '/escalated/v1/widget/articles/no-feedback-here/feedback');
        $request->set_param('helpful', true);
        $this->assertEquals(404, $this->server->dispatch($request)->get_status());
    }
}
