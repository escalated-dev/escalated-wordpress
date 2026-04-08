<?php

/**
 * Tests for the SavedView model and CRUD operations.
 *
 * Covers creation, reading, updating, deletion, user scoping,
 * shared views, and reordering.
 */

use Escalated\Models\SavedView;

class Test_Saved_Views extends WP_UnitTestCase
{
    private int $user_id;

    private int $other_user_id;

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();
        SavedView::ensure_table();

        $this->user_id = $this->factory->user->create(['role' => 'escalated_agent']);
        $this->other_user_id = $this->factory->user->create(['role' => 'escalated_agent']);
    }

    /**
     * Helper: Create a saved view.
     */
    private function create_view(array $overrides = []): object
    {
        $defaults = [
            'name' => 'My View',
            'filters' => wp_json_encode(['status' => 'open', 'priority' => 'high']),
            'user_id' => $this->user_id,
            'is_shared' => 0,
            'position' => 0,
        ];

        $data = array_merge($defaults, $overrides);
        $id = SavedView::create($data);

        return SavedView::find($id);
    }

    // =========================================================================
    // CRUD Tests
    // =========================================================================

    public function test_create_saved_view(): void
    {
        $view = $this->create_view();

        $this->assertIsObject($view);
        $this->assertEquals('My View', $view->name);
        $this->assertEquals($this->user_id, (int) $view->user_id);
        $this->assertEquals(0, (int) $view->is_shared);
    }

    public function test_find_saved_view(): void
    {
        $view = $this->create_view();

        $found = SavedView::find((int) $view->id);
        $this->assertNotNull($found);
        $this->assertEquals($view->id, $found->id);
    }

    public function test_update_saved_view(): void
    {
        $view = $this->create_view();

        SavedView::update((int) $view->id, [
            'name' => 'Updated View',
            'filters' => wp_json_encode(['status' => 'closed']),
        ]);

        $updated = SavedView::find((int) $view->id);
        $this->assertEquals('Updated View', $updated->name);

        $filters = json_decode($updated->filters, true);
        $this->assertEquals('closed', $filters['status']);
    }

    public function test_delete_saved_view(): void
    {
        $view = $this->create_view();

        SavedView::delete((int) $view->id);

        $deleted = SavedView::find((int) $view->id);
        $this->assertNull($deleted);
    }

    // =========================================================================
    // Filters JSON Tests
    // =========================================================================

    public function test_filters_stored_as_json(): void
    {
        $filters = ['status' => 'open', 'priority' => 'high', 'tag_ids' => [1, 2, 3]];
        $view = $this->create_view([
            'filters' => wp_json_encode($filters),
        ]);

        $decoded = json_decode($view->filters, true);
        $this->assertEquals('open', $decoded['status']);
        $this->assertEquals('high', $decoded['priority']);
        $this->assertCount(3, $decoded['tag_ids']);
    }

    // =========================================================================
    // User Scoping Tests
    // =========================================================================

    public function test_for_user_returns_own_views(): void
    {
        $this->create_view(['name' => 'User 1 View']);
        $this->create_view([
            'name' => 'User 2 View',
            'user_id' => $this->other_user_id,
        ]);

        $views = SavedView::for_user($this->user_id);

        $names = array_map(fn ($v) => $v->name, $views);
        $this->assertContains('User 1 View', $names);
        $this->assertNotContains('User 2 View', $names);
    }

    public function test_for_user_includes_shared_views(): void
    {
        $this->create_view(['name' => 'My Private View']);
        $this->create_view([
            'name' => 'Shared View',
            'user_id' => $this->other_user_id,
            'is_shared' => 1,
        ]);

        $views = SavedView::for_user($this->user_id);

        $names = array_map(fn ($v) => $v->name, $views);
        $this->assertContains('My Private View', $names);
        $this->assertContains('Shared View', $names);
    }

    public function test_for_user_excludes_others_private_views(): void
    {
        $this->create_view([
            'name' => 'Other Private',
            'user_id' => $this->other_user_id,
            'is_shared' => 0,
        ]);

        $views = SavedView::for_user($this->user_id);

        $names = array_map(fn ($v) => $v->name, $views);
        $this->assertNotContains('Other Private', $names);
    }

    // =========================================================================
    // Reordering Tests
    // =========================================================================

    public function test_reorder_views(): void
    {
        $view1 = $this->create_view(['name' => 'First', 'position' => 0]);
        $view2 = $this->create_view(['name' => 'Second', 'position' => 1]);
        $view3 = $this->create_view(['name' => 'Third', 'position' => 2]);

        // Reverse the order.
        SavedView::reorder([(int) $view3->id, (int) $view2->id, (int) $view1->id]);

        $updated1 = SavedView::find((int) $view1->id);
        $updated2 = SavedView::find((int) $view2->id);
        $updated3 = SavedView::find((int) $view3->id);

        $this->assertEquals(2, (int) $updated1->position);
        $this->assertEquals(1, (int) $updated2->position);
        $this->assertEquals(0, (int) $updated3->position);
    }

    // =========================================================================
    // Position Ordering Tests
    // =========================================================================

    public function test_for_user_orders_by_position(): void
    {
        $this->create_view(['name' => 'Z View', 'position' => 2]);
        $this->create_view(['name' => 'A View', 'position' => 0]);
        $this->create_view(['name' => 'M View', 'position' => 1]);

        $views = SavedView::for_user($this->user_id);

        $this->assertEquals('A View', $views[0]->name);
        $this->assertEquals('M View', $views[1]->name);
        $this->assertEquals('Z View', $views[2]->name);
    }

    // =========================================================================
    // All Views Test
    // =========================================================================

    public function test_all_returns_all_views(): void
    {
        $this->create_view(['name' => 'View A']);
        $this->create_view(['name' => 'View B', 'user_id' => $this->other_user_id]);

        $all = SavedView::all();

        $this->assertGreaterThanOrEqual(2, count($all));
    }

    // =========================================================================
    // Shared Flag Tests
    // =========================================================================

    public function test_create_shared_view(): void
    {
        $view = $this->create_view(['is_shared' => 1]);

        $this->assertEquals(1, (int) $view->is_shared);
    }

    public function test_update_to_shared(): void
    {
        $view = $this->create_view(['is_shared' => 0]);

        SavedView::update((int) $view->id, ['is_shared' => 1]);

        $updated = SavedView::find((int) $view->id);
        $this->assertEquals(1, (int) $updated->is_shared);
    }
}
