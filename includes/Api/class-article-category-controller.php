<?php

/**
 * Article Category Controller - admin CRUD for knowledge base categories.
 *
 * Ports the Laravel reference Admin\ArticleCategoryController. Reads require
 * escalated_kb_view; writes require escalated_kb_edit — reusing existing
 * capabilities so no new capability is introduced.
 *
 * Routes (namespace escalated/v1):
 *   GET    /admin/kb/categories        index
 *   POST   /admin/kb/categories        store
 *   PUT    /admin/kb/categories/{id}   update
 *   PATCH  /admin/kb/categories/{id}   update
 *   DELETE /admin/kb/categories/{id}   destroy
 */

namespace Escalated\Api;

use Escalated\Models\ArticleCategory;
use Escalated\Models\AuditLog;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

class Article_Category_Controller extends Base_Controller
{
    public function register_routes(): void
    {
        $ns = $this->namespace;

        register_rest_route($ns, '/admin/kb/categories', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'index'],
                'permission_callback' => [$this, 'permission_view'],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'store'],
                'permission_callback' => [$this, 'permission_edit'],
            ],
        ]);

        register_rest_route($ns, '/admin/kb/categories/(?P<id>\d+)', [
            [
                'methods' => ['PUT', 'PATCH'],
                'callback' => [$this, 'update'],
                'permission_callback' => [$this, 'permission_edit'],
                'args' => ['id' => ['required' => true, 'type' => 'integer']],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'destroy'],
                'permission_callback' => [$this, 'permission_edit'],
                'args' => ['id' => ['required' => true, 'type' => 'integer']],
            ],
        ]);
    }

    /**
     * @return bool|WP_Error
     */
    public function permission_view()
    {
        return $this->require_cap('escalated_kb_view');
    }

    /**
     * @return bool|WP_Error
     */
    public function permission_edit()
    {
        return $this->require_cap('escalated_kb_edit');
    }

    /**
     * @return bool|WP_Error
     */
    private function require_cap(string $cap)
    {
        if (! is_user_logged_in()) {
            return new WP_Error(
                'escalated_unauthorized',
                __('You must be logged in.', 'escalated'),
                ['status' => 401]
            );
        }

        if (! current_user_can($cap)) {
            return new WP_Error(
                'escalated_forbidden',
                __('You do not have permission to manage knowledge base categories.', 'escalated'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * GET /admin/kb/categories
     */
    public function index(WP_REST_Request $request)
    {
        unset($request);

        $categories = [];
        foreach (ArticleCategory::all_with_article_counts() as $row) {
            $categories[] = $this->format_category($row);
        }

        return $this->success(['categories' => $categories]);
    }

    /**
     * POST /admin/kb/categories
     */
    public function store(WP_REST_Request $request)
    {
        $data = $this->parse_json_body($request);

        $validated = $this->validate($data);
        if (is_wp_error($validated)) {
            return $validated;
        }

        $slug_base = sanitize_title($validated['slug'] !== '' ? $validated['slug'] : $validated['name']);
        $validated['slug'] = ArticleCategory::unique_slug($slug_base);

        $id = ArticleCategory::create($validated);
        if ($id === false) {
            return $this->error('escalated_create_failed', __('Failed to create category.', 'escalated'), 500);
        }

        AuditLog::record('kb_category.created', 'ArticleCategory', (int) $id, null, [
            'name' => $validated['name'],
            'slug' => $validated['slug'],
        ]);

        return $this->success(['id' => (int) $id, 'category' => $this->format_category(ArticleCategory::find($id))], 201);
    }

    /**
     * PUT/PATCH /admin/kb/categories/{id}
     */
    public function update(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        $existing = ArticleCategory::find($id);
        if (! $existing) {
            return $this->error('escalated_not_found', __('Category not found.', 'escalated'), 404);
        }

        $data = $this->parse_json_body($request);

        $validated = $this->validate($data, $id);
        if (is_wp_error($validated)) {
            return $validated;
        }

        $slug_base = sanitize_title($validated['slug'] !== '' ? $validated['slug'] : $validated['name']);
        $validated['slug'] = ArticleCategory::unique_slug($slug_base, $id);

        $ok = ArticleCategory::update($id, $validated);
        if ($ok === false) {
            return $this->error('escalated_update_failed', __('Failed to update category.', 'escalated'), 500);
        }

        AuditLog::record('kb_category.updated', 'ArticleCategory', $id, [
            'name' => $existing->name,
        ], [
            'name' => $validated['name'],
        ]);

        return $this->success(['category' => $this->format_category(ArticleCategory::find($id))]);
    }

    /**
     * DELETE /admin/kb/categories/{id}
     */
    public function destroy(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        $existing = ArticleCategory::find($id);
        if (! $existing) {
            return $this->error('escalated_not_found', __('Category not found.', 'escalated'), 404);
        }

        ArticleCategory::delete($id);

        AuditLog::record('kb_category.deleted', 'ArticleCategory', $id, [
            'name' => $existing->name,
            'slug' => $existing->slug,
        ], null);

        return $this->success(null, 204);
    }

    /**
     * Validate + normalise a category payload.
     *
     * @param  array<string, mixed>  $data
     * @param  int|null  $self_id  The row being updated (excluded from parent checks).
     * @return array<string, mixed>|WP_Error
     */
    private function validate(array $data, $self_id = null)
    {
        $name = isset($data['name']) ? trim(sanitize_text_field((string) $data['name'])) : '';
        if ($name === '') {
            return $this->error('escalated_invalid', __('A name is required.', 'escalated'), 422);
        }

        $parent_id = null;
        if (isset($data['parent_id']) && $data['parent_id'] !== '' && $data['parent_id'] !== null) {
            $parent_id = (int) $data['parent_id'];
            if ($self_id !== null && $parent_id === (int) $self_id) {
                return $this->error('escalated_invalid', __('A category cannot be its own parent.', 'escalated'), 422);
            }
            if (! ArticleCategory::find($parent_id)) {
                return $this->error('escalated_invalid', __('The selected parent category does not exist.', 'escalated'), 422);
            }
        }

        return [
            'name' => $name,
            'slug' => isset($data['slug']) ? (string) $data['slug'] : '',
            'parent_id' => $parent_id,
            'position' => isset($data['position']) ? max(0, (int) $data['position']) : 0,
            'description' => isset($data['description']) ? sanitize_textarea_field((string) $data['description']) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function format_category($row): array
    {
        return [
            'id' => (int) $row->id,
            'name' => $row->name,
            'slug' => $row->slug,
            'parent_id' => $row->parent_id !== null ? (int) $row->parent_id : null,
            'position' => (int) $row->position,
            'description' => $row->description,
            'articles_count' => isset($row->articles_count) ? (int) $row->articles_count : 0,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parse_json_body(WP_REST_Request $request): array
    {
        $json = $request->get_json_params();
        if (is_array($json)) {
            return $json;
        }

        $body = $request->get_body_params();

        return is_array($body) ? $body : [];
    }
}
