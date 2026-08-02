<?php

/**
 * Article Controller - admin CRUD for knowledge base articles.
 *
 * Ports the Laravel reference Admin\ArticleController. Gated by the existing
 * granular knowledge base capabilities (escalated_kb_view / _create / _edit /
 * _delete) so no new capability is introduced.
 *
 * Routes (namespace escalated/v1):
 *   GET    /admin/kb/articles          index
 *   POST   /admin/kb/articles          store
 *   GET    /admin/kb/articles/{id}     show
 *   PUT    /admin/kb/articles/{id}     update
 *   PATCH  /admin/kb/articles/{id}     update
 *   DELETE /admin/kb/articles/{id}     destroy
 */

namespace Escalated\Api;

use Escalated\Models\Article;
use Escalated\Models\ArticleCategory;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

class Article_Controller extends Base_Controller
{
    public function register_routes(): void
    {
        $ns = $this->namespace;

        register_rest_route($ns, '/admin/kb/articles', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'index'],
                'permission_callback' => [$this, 'permission_view'],
                'args' => [
                    'search' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                    'status' => ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field'],
                    'category_id' => ['type' => 'integer'],
                ],
            ],
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'store'],
                'permission_callback' => [$this, 'permission_create'],
            ],
        ]);

        register_rest_route($ns, '/admin/kb/articles/(?P<id>\d+)', [
            [
                'methods' => WP_REST_Server::READABLE,
                'callback' => [$this, 'show'],
                'permission_callback' => [$this, 'permission_view'],
                'args' => ['id' => ['required' => true, 'type' => 'integer']],
            ],
            [
                'methods' => ['PUT', 'PATCH'],
                'callback' => [$this, 'update'],
                'permission_callback' => [$this, 'permission_edit'],
                'args' => ['id' => ['required' => true, 'type' => 'integer']],
            ],
            [
                'methods' => WP_REST_Server::DELETABLE,
                'callback' => [$this, 'destroy'],
                'permission_callback' => [$this, 'permission_delete'],
                'args' => ['id' => ['required' => true, 'type' => 'integer']],
            ],
        ]);
    }

    // ---------------------------------------------------------------------
    // Permission callbacks (existing kb.* capabilities — no new caps added)
    // ---------------------------------------------------------------------

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
    public function permission_create()
    {
        return $this->require_cap('escalated_kb_create');
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
    public function permission_delete()
    {
        return $this->require_cap('escalated_kb_delete');
    }

    /**
     * Shared login + capability guard.
     *
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
                __('You do not have permission to manage knowledge base articles.', 'escalated'),
                ['status' => 403]
            );
        }

        return true;
    }

    // ---------------------------------------------------------------------
    // Handlers
    // ---------------------------------------------------------------------

    /**
     * GET /admin/kb/articles
     */
    public function index(WP_REST_Request $request)
    {
        $filters = [
            'search' => (string) $request->get_param('search'),
            'status' => (string) $request->get_param('status'),
            'category_id' => $request->get_param('category_id'),
        ];

        $articles = array_map([$this, 'format_article'], Article::all($filters));

        return $this->success([
            'articles' => $articles,
            'categories' => $this->format_categories(ArticleCategory::all()),
            'filters' => [
                'search' => $filters['search'],
                'status' => $filters['status'],
                'category_id' => $filters['category_id'] !== null ? (int) $filters['category_id'] : null,
            ],
        ]);
    }

    /**
     * GET /admin/kb/articles/{id}
     */
    public function show(WP_REST_Request $request)
    {
        $article = Article::find((int) $request->get_param('id'));
        if (! $article) {
            return $this->error('escalated_not_found', __('Article not found.', 'escalated'), 404);
        }

        return $this->success([
            'article' => $this->format_article($article),
            'categories' => $this->format_categories(ArticleCategory::all()),
        ]);
    }

    /**
     * POST /admin/kb/articles
     */
    public function store(WP_REST_Request $request)
    {
        $data = $this->parse_json_body($request);

        $validated = $this->validate($data);
        if (is_wp_error($validated)) {
            return $validated;
        }

        $slug_base = sanitize_title($validated['slug'] !== '' ? $validated['slug'] : $validated['title']);
        $validated['slug'] = Article::unique_slug($slug_base);
        $validated['author_id'] = get_current_user_id() ?: null;

        if ($validated['status'] === 'published') {
            $validated['published_at'] = current_time('mysql');
        }

        $id = Article::create($validated);
        if ($id === false) {
            return $this->error('escalated_create_failed', __('Failed to create article.', 'escalated'), 500);
        }

        return $this->success(['id' => (int) $id, 'article' => $this->format_article(Article::find($id))], 201);
    }

    /**
     * PUT/PATCH /admin/kb/articles/{id}
     */
    public function update(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        $article = Article::find($id);
        if (! $article) {
            return $this->error('escalated_not_found', __('Article not found.', 'escalated'), 404);
        }

        $data = $this->parse_json_body($request);

        $validated = $this->validate($data);
        if (is_wp_error($validated)) {
            return $validated;
        }

        $slug_base = sanitize_title($validated['slug'] !== '' ? $validated['slug'] : $validated['title']);
        $validated['slug'] = Article::unique_slug($slug_base, $id);

        // Stamp published_at the first time an article becomes published.
        if ($validated['status'] === 'published' && empty($article->published_at)) {
            $validated['published_at'] = current_time('mysql');
        }

        $ok = Article::update($id, $validated);
        if ($ok === false) {
            return $this->error('escalated_update_failed', __('Failed to update article.', 'escalated'), 500);
        }

        return $this->success(['article' => $this->format_article(Article::find($id))]);
    }

    /**
     * DELETE /admin/kb/articles/{id}
     */
    public function destroy(WP_REST_Request $request)
    {
        $id = (int) $request->get_param('id');
        if (! Article::find($id)) {
            return $this->error('escalated_not_found', __('Article not found.', 'escalated'), 404);
        }

        Article::delete($id);

        return $this->success(null, 204);
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Validate + normalise an article payload.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|WP_Error
     */
    private function validate(array $data)
    {
        $title = isset($data['title']) ? trim(sanitize_text_field((string) $data['title'])) : '';
        if ($title === '') {
            return $this->error('escalated_invalid', __('A title is required.', 'escalated'), 422);
        }

        $status = isset($data['status']) ? (string) $data['status'] : 'draft';
        if (! in_array($status, ['draft', 'published'], true)) {
            return $this->error('escalated_invalid', __('Status must be draft or published.', 'escalated'), 422);
        }

        $category_id = null;
        if (isset($data['category_id']) && $data['category_id'] !== '' && $data['category_id'] !== null) {
            $category_id = (int) $data['category_id'];
            if (! ArticleCategory::find($category_id)) {
                return $this->error('escalated_invalid', __('The selected category does not exist.', 'escalated'), 422);
            }
        }

        return [
            'title' => $title,
            'slug' => isset($data['slug']) ? (string) $data['slug'] : '',
            'body' => isset($data['body']) ? wp_kses_post((string) $data['body']) : null,
            'status' => $status,
            'category_id' => $category_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function format_article($row): array
    {
        $category = null;
        if (! empty($row->category_id)) {
            $cat = ArticleCategory::find((int) $row->category_id);
            if ($cat) {
                $category = ['id' => (int) $cat->id, 'name' => $cat->name, 'slug' => $cat->slug];
            }
        }

        return [
            'id' => (int) $row->id,
            'category_id' => $row->category_id !== null ? (int) $row->category_id : null,
            'category' => $category,
            'title' => $row->title,
            'slug' => $row->slug,
            'body' => $row->body,
            'status' => $row->status,
            'author_id' => $row->author_id !== null ? (int) $row->author_id : null,
            'view_count' => (int) $row->view_count,
            'helpful_count' => (int) $row->helpful_count,
            'not_helpful_count' => (int) $row->not_helpful_count,
            'published_at' => $row->published_at,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /**
     * @param  array<int, object>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function format_categories(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['id' => (int) $row->id, 'name' => $row->name, 'slug' => $row->slug];
        }

        return $out;
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
