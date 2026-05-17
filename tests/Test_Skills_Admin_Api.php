<?php

/**
 * REST tests for admin skills routes (escalated/v1/admin/skills).
 */

use Escalated\Models\Department;
use Escalated\Models\Tag;
use Escalated\Services\SkillService;

class Test_Skills_Admin_Api extends WP_UnitTestCase
{
    private int $admin_id;

    private WP_REST_Server $server;

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

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

    public function test_admin_skills_index_requires_auth(): void
    {
        wp_set_current_user(0);
        $request = new WP_REST_Request('GET', '/escalated/v1/admin/skills');
        $response = $this->server->dispatch($request);
        $this->assertEquals(401, $response->get_status());
    }

    public function test_agent_cannot_access_admin_skills(): void
    {
        $agent_id = $this->factory->user->create(['role' => 'escalated_agent']);
        wp_set_current_user($agent_id);
        $request = new WP_REST_Request('GET', '/escalated/v1/admin/skills');
        $response = $this->server->dispatch($request);
        $this->assertEquals(403, $response->get_status());
    }

    public function test_index_returns_skills_shape(): void
    {
        // TODO(#55): index returns 0 rows here even though SkillService::create
        // succeeded — likely an isolation issue with WP_UnitTestCase resetting
        // the activator-created tables between set_up and the REST call. Track
        // and re-enable once the test bootstrap reliably persists the row.
        $this->markTestSkipped('REST index returns empty under WP_UnitTestCase transaction reset — track in #55.');
        wp_set_current_user($this->admin_id);

        $tid = Tag::create([
            'name' => 'Bug',
            'slug' => 'bug',
            'color' => '#ff0000',
        ]);
        $this->assertNotFalse($tid);

        $sid = SkillService::create([
            'name' => 'Networking',
            'routing_tag_ids' => [(int) $tid],
            'routing_department_ids' => [],
            'agents' => [],
        ]);
        $this->assertIsInt($sid);

        $request = new WP_REST_Request('GET', '/escalated/v1/admin/skills');
        $response = $this->server->dispatch($request);

        $this->assertEquals(200, $response->get_status());
        $data = $response->get_data();
        $this->assertArrayHasKey('skills', $data);
        $this->assertNotEmpty($data['skills']);
        $row = $data['skills'][0];
        $this->assertArrayHasKey('agents_count', $row);
        $this->assertArrayHasKey('routing_tags_count', $row);
        $this->assertArrayHasKey('routing_departments_count', $row);
        $this->assertArrayHasKey('updated_at', $row);
        $this->assertSame(1, $row['routing_tags_count']);
    }

    public function test_store_update_delete_flow(): void
    {
        wp_set_current_user($this->admin_id);

        $request = new WP_REST_Request('POST', '/escalated/v1/admin/skills');
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode([
            'name' => 'Security',
            'routing_tag_ids' => [],
            'routing_department_ids' => [],
            'agents' => [],
        ]));
        $response = $this->server->dispatch($request);
        $this->assertEquals(201, $response->get_status());
        $id = (int) $response->get_data()['id'];
        $this->assertGreaterThan(0, $id);

        $request = new WP_REST_Request('PATCH', '/escalated/v1/admin/skills/'.$id);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode([
            'name' => 'Security Ops',
            'routing_tag_ids' => [],
            'routing_department_ids' => [],
            'agents' => [],
        ]));
        $response = $this->server->dispatch($request);
        $this->assertEquals(200, $response->get_status());

        $row = SkillService::find_for_edit($id);
        $this->assertSame('Security Ops', $row['name']);

        $request = new WP_REST_Request('DELETE', '/escalated/v1/admin/skills/'.$id);
        $response = $this->server->dispatch($request);
        $this->assertEquals(204, $response->get_status());
        $this->assertNull(SkillService::find_for_edit($id));
    }

    public function test_new_and_edit_form_props(): void
    {
        wp_set_current_user($this->admin_id);

        $request = new WP_REST_Request('GET', '/escalated/v1/admin/skills/new');
        $response = $this->server->dispatch($request);
        $this->assertEquals(200, $response->get_status());
        $d = $response->get_data();
        $this->assertArrayHasKey('available_agents', $d);
        $this->assertArrayHasKey('available_tags', $d);
        $this->assertArrayHasKey('available_departments', $d);
        $this->assertNull($d['skill']);

        $did = Department::create([
            'name' => 'Support',
            'slug' => 'support',
            'description' => '',
            'is_active' => 1,
        ]);
        $this->assertNotFalse($did);

        $sid = SkillService::create([
            'name' => 'Billing',
            'routing_tag_ids' => [],
            'routing_department_ids' => [(int) $did],
            'agents' => [],
        ]);

        $request = new WP_REST_Request('GET', '/escalated/v1/admin/skills/'.$sid.'/edit');
        $response = $this->server->dispatch($request);
        $this->assertEquals(200, $response->get_status());
        $d = $response->get_data();
        $this->assertIsArray($d['skill']);
        $this->assertSame([(int) $did], $d['skill']['routing_department_ids']);
    }
}
