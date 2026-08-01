<?php

/**
 * Widget Controller - public REST API endpoints for the embeddable widget.
 *
 * All endpoints are publicly accessible (no authentication required)
 * but are guarded by a widget_enabled setting and rate limiting.
 */

namespace Escalated\Api;

use Escalated\Models\Article;
use Escalated\Models\ArticleCategory;
use Escalated\Models\Setting;
use Escalated\Models\Ticket;
use Escalated\Services\KnowledgeBaseService;
use Escalated\Services\TicketService;
use WP_REST_Request;
use WP_REST_Server;

class Widget_Controller extends Base_Controller
{
    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'widget';

    /**
     * Register widget routes.
     */
    public function register_routes(): void
    {
        // Widget configuration.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/config',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'get_config'],
                    'permission_callback' => [$this, 'widget_enabled_check'],
                ],
            ]
        );

        // List KB articles for widget.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/articles',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'get_articles'],
                    'permission_callback' => [$this, 'widget_enabled_check'],
                    'args' => [
                        'search' => [
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ]
        );

        // Get a single KB article by slug.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/articles/(?P<slug>[a-z0-9-]+)',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'get_article'],
                    'permission_callback' => [$this, 'widget_enabled_check'],
                    'args' => [
                        'slug' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_title',
                        ],
                    ],
                ],
            ]
        );

        // Submit helpful / not-helpful feedback for a KB article.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/articles/(?P<slug>[a-z0-9-]+)/feedback',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'submit_feedback'],
                    'permission_callback' => [$this, 'widget_enabled_check'],
                    'args' => [
                        'slug' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_title',
                        ],
                        'helpful' => [
                            'required' => true,
                            'type' => 'boolean',
                        ],
                    ],
                ],
            ]
        );

        // Create a ticket via widget.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/tickets',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'create_ticket'],
                    'permission_callback' => [$this, 'widget_enabled_check'],
                    'args' => [
                        'name' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'email' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_email',
                        ],
                        'subject' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'description' => [
                            'required' => true,
                            'type' => 'string',
                        ],
                    ],
                ],
            ]
        );

        // Lookup ticket by reference.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/tickets/(?P<ref>[A-Z]+-\d+)',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'get_ticket'],
                    'permission_callback' => [$this, 'widget_enabled_check'],
                    'args' => [
                        'ref' => [
                            'required' => true,
                            'type' => 'string',
                        ],
                        'guest_token' => [
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Permission callback: check if the widget is enabled and rate limit.
     *
     * @return bool|\WP_Error
     */
    public function widget_enabled_check()
    {
        if (! Setting::get_bool('widget_enabled', false)) {
            return new \WP_Error(
                'escalated_widget_disabled',
                __('Widget is not enabled.', 'escalated'),
                ['status' => 403]
            );
        }

        // Rate limit by IP address.
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = 'escalated_widget_rate_'.md5($ip);
        $limit = 30; // 30 requests per minute for widget.
        $current = (int) get_transient($key);

        if ($current >= $limit) {
            return new \WP_Error(
                'escalated_rate_limited',
                __('Too many requests. Please try again later.', 'escalated'),
                ['status' => 429]
            );
        }

        set_transient($key, $current + 1, MINUTE_IN_SECONDS);

        return true;
    }

    /**
     * Get widget configuration.
     */
    public function get_config()
    {
        return $this->success([
            'color' => Setting::get('widget_color', '#3B82F6'),
            'position' => Setting::get('widget_position', 'bottom-right'),
            'greeting' => Setting::get('widget_greeting', __('Hi there! How can we help?', 'escalated')),
        ]);
    }

    /**
     * Get published KB articles for the widget.
     *
     * Reads from the escalated_articles table (previously this queried a
     * never-registered `escalated_article` custom post type and always
     * returned nothing).
     */
    public function get_articles(WP_REST_Request $request)
    {
        $search = $request->get_param('search');

        $filters = ['limit' => 10];
        if (! empty($search)) {
            $filters['search'] = $search;
        }

        $articles = [];
        foreach (Article::published($filters) as $row) {
            $articles[] = [
                'id' => (int) $row->id,
                'title' => $row->title,
                'slug' => $row->slug,
                'excerpt' => wp_trim_words(wp_strip_all_tags((string) $row->body), 30),
                'category' => $this->article_category($row),
            ];
        }

        return $this->success($articles);
    }

    /**
     * Get a single published KB article by slug. Increments the view counter
     * and returns related articles from the same category.
     */
    public function get_article(WP_REST_Request $request)
    {
        $slug = $request->get_param('slug');

        $article = Article::find_published_by_slug($slug);

        if (! $article) {
            return $this->error('escalated_not_found', __('Article not found.', 'escalated'), 404);
        }

        Article::increment_views($article->id);

        $related = [];
        foreach (Article::related($article->category_id, $article->id, 5) as $row) {
            $related[] = [
                'id' => (int) $row->id,
                'title' => $row->title,
                'slug' => $row->slug,
            ];
        }

        return $this->success([
            'id' => (int) $article->id,
            'title' => $article->title,
            'slug' => $article->slug,
            'content' => wp_kses_post((string) $article->body),
            'category' => $this->article_category($article),
            'related' => $related,
            'feedback_enabled' => KnowledgeBaseService::is_feedback_enabled(),
            'date' => $article->published_at ?: $article->created_at,
        ]);
    }

    /**
     * Record helpful / not-helpful feedback for a published article.
     */
    public function submit_feedback(WP_REST_Request $request)
    {
        if (! KnowledgeBaseService::is_feedback_enabled()) {
            return $this->error('escalated_feedback_disabled', __('Article feedback is disabled.', 'escalated'), 404);
        }

        $article = Article::find_published_by_slug($request->get_param('slug'));
        if (! $article) {
            return $this->error('escalated_not_found', __('Article not found.', 'escalated'), 404);
        }

        if (rest_sanitize_boolean($request->get_param('helpful'))) {
            Article::mark_helpful($article->id);
        } else {
            Article::mark_not_helpful($article->id);
        }

        return $this->success(['message' => __('Thank you for your feedback!', 'escalated')]);
    }

    /**
     * Resolve an article's category to a lightweight {id,name,slug} array.
     *
     * @return array<string, mixed>|null
     */
    private function article_category($article)
    {
        if (empty($article->category_id)) {
            return null;
        }

        $category = ArticleCategory::find((int) $article->category_id);
        if (! $category) {
            return null;
        }

        return ['id' => (int) $category->id, 'name' => $category->name, 'slug' => $category->slug];
    }

    /**
     * Create a ticket via the widget (guest ticket).
     */
    public function create_ticket(WP_REST_Request $request)
    {
        $service = new TicketService;

        try {
            $ticket = $service->create_guest([
                'subject' => $request->get_param('subject'),
                'description' => wp_kses_post($request->get_param('description')),
                'guest_name' => $request->get_param('name'),
                'guest_email' => $request->get_param('email'),
                'channel' => 'widget',
            ]);

            return $this->success([
                'message' => __('Ticket created successfully.', 'escalated'),
                'reference' => $ticket->reference,
                'guest_token' => $ticket->guest_token,
            ], 201);
        } catch (\Throwable $e) {
            return $this->error('escalated_create_failed', $e->getMessage(), 500);
        }
    }

    /**
     * Lookup a ticket by reference (requires the guest token for verification).
     */
    public function get_ticket(WP_REST_Request $request)
    {
        $ref = sanitize_text_field($request->get_param('ref'));
        $guest_token = sanitize_text_field((string) $request->get_param('guest_token'));

        $ticket = Ticket::find_by_reference($ref);
        if (! $ticket) {
            return $this->error('escalated_not_found', __('Ticket not found.', 'escalated'), 404);
        }

        if (empty($ticket->guest_token) || ! hash_equals((string) $ticket->guest_token, $guest_token)) {
            return $this->error('escalated_forbidden', __('Ticket verification failed.', 'escalated'), 403);
        }

        return $this->success([
            'reference' => $ticket->reference,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'created_at' => $ticket->created_at,
            'updated_at' => $ticket->updated_at,
        ]);
    }

    /**
     * Get the widget settings.
     */
    public static function get_settings(): array
    {
        return [
            'widget_enabled' => Setting::get_bool('widget_enabled', false),
            'widget_color' => Setting::get('widget_color', '#3B82F6'),
            'widget_position' => Setting::get('widget_position', 'bottom-right'),
            'widget_greeting' => Setting::get('widget_greeting', __('Hi there! How can we help?', 'escalated')),
        ];
    }

    /**
     * Update widget settings.
     *
     * @param  array  $settings  Settings to update.
     */
    public static function update_settings(array $settings): void
    {
        $allowed = ['widget_enabled', 'widget_color', 'widget_position', 'widget_greeting'];

        foreach ($allowed as $key) {
            if (isset($settings[$key])) {
                Setting::set($key, sanitize_text_field($settings[$key]));
            }
        }
    }
}
