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
use Escalated\Models\Department;
use Escalated\Models\Setting;
use Escalated\Models\Ticket;
use Escalated\Services\GuestAccess;
use Escalated\Services\GuestEmailVerification;
use Escalated\Services\GuestRateLimiter;
use Escalated\Services\KnowledgeBaseService;
use Escalated\Services\TicketService;
use Escalated\Services\WidgetMetadata;
use Escalated\Services\WidgetOrigins;
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
                    'permission_callback' => [$this, 'config_permission_check'],
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
                        // The shared widget searches with `q` and reads
                        // {articles: [...]}; `search` keeps the bare array.
                        'q' => [
                            'type' => 'string',
                            'minLength' => 2,
                            'maxLength' => 200,
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
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
                        'verification_id' => ['type' => 'string'],
                        'verification_code' => ['type' => 'string'],
                        'subject' => [
                            'required' => true,
                            'type' => 'string',
                            'sanitize_callback' => 'sanitize_text_field',
                        ],
                        'description' => [
                            'required' => true,
                            'type' => 'string',
                        ],
                        'department_id' => self::department_arg(),
                        'metadata' => WidgetMetadata::rest_arg(),
                    ],
                ],
            ]
        );

        // Email a guest verification code (purpose: ticket, chat or lookup).
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/verification',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'request_verification'],
                    'permission_callback' => [$this, 'guest_write_check'],
                ],
            ]
        );

        // Trade a lookup proof plus a reference for fresh access grants.
        register_rest_route(
            $this->namespace,
            '/'.$this->rest_base.'/lookup',
            [
                [
                    'methods' => WP_REST_Server::CREATABLE,
                    'callback' => [$this, 'lookup'],
                    'permission_callback' => [$this, 'guest_write_check'],
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
     * Permission for the verification and lookup routes: the widget guard
     * plus the per-IP guest reply limit, so codes and lookups cannot be
     * hammered from one address.
     *
     * @return bool|\WP_Error
     */
    public function guest_write_check()
    {
        $guard = $this->widget_enabled_check();
        if ($guard !== true) {
            return $guard;
        }
        $retry_after = GuestRateLimiter::attempt('reply');
        if ($retry_after !== null) {
            $rejection = GuestRateLimiter::rejection($retry_after);

            return new \WP_Error($rejection['code'], $rejection['message'], ['status' => 429, 'retry_after' => $retry_after]);
        }

        return true;
    }

    public function request_verification(WP_REST_Request $request)
    {
        return GuestVerificationEndpoints::request_code($request);
    }

    public function lookup(WP_REST_Request $request)
    {
        $disabled = self::guest_tickets_disabled();
        if ($disabled) {
            return $disabled;
        }

        return GuestVerificationEndpoints::lookup($request);
    }

    /**
     * Permission callback: check if the widget is enabled and rate limit.
     *
     * @return bool|\WP_Error
     */
    public function widget_enabled_check()
    {
        return self::widget_guard('escalated_widget_rate_', 30); // 30 requests per minute for widget.
    }

    /**
     * Check that the widget is enabled and apply a per-IP, per-minute limit.
     *
     * @param  string  $bucket  Transient key prefix for the counter.
     * @param  int  $limit  Requests per minute.
     * @return bool|\WP_Error
     */
    public static function widget_guard(string $bucket, int $limit)
    {
        if (! Setting::get_bool('widget_enabled', false)) {
            return new \WP_Error(
                'escalated_widget_disabled',
                __('Widget is not enabled.', 'escalated'),
                ['status' => 403]
            );
        }

        // Rate limit by IP address, in a fixed one-minute window whose reset
        // time is kept so a 429 can say when to retry.
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = $bucket.md5($ip);
        $now = time();
        $window = get_transient($key);
        if (! is_array($window) || (int) ($window['reset'] ?? 0) <= $now) {
            $window = ['count' => 0, 'reset' => $now + MINUTE_IN_SECONDS];
        }

        $retry_after = max(1, (int) $window['reset'] - $now);
        if ((int) $window['count'] >= $limit) {
            return new \WP_Error(
                'escalated_rate_limited',
                __('Too many requests. Please try again later.', 'escalated'),
                ['status' => 429, 'retry_after' => $retry_after]
            );
        }

        $window['count'] = (int) $window['count'] + 1;
        set_transient($key, $window, $retry_after);

        return true;
    }

    /**
     * A 429 response with a Retry-After header when the per-IP guest
     * limiter rejects this request, otherwise null.
     *
     * @param  string  $scope  'ticket' or 'reply'.
     */
    public static function guest_limited(string $scope): ?\WP_REST_Response
    {
        $retry_after = GuestRateLimiter::attempt($scope);
        if ($retry_after === null) {
            return null;
        }

        $rejection = GuestRateLimiter::rejection($retry_after);
        $response = rest_convert_error_to_response(new \WP_Error(
            $rejection['code'],
            $rejection['message'],
            ['status' => 429, 'retry_after' => $retry_after]
        ));
        $response->header('Retry-After', (string) $retry_after);

        return $response;
    }

    /**
     * A 403 when the admin has turned guest tickets off, otherwise null.
     */
    public static function guest_tickets_disabled(): ?\WP_Error
    {
        if (Setting::get_bool('guest_tickets_enabled', true)) {
            return null;
        }

        return new \WP_Error(
            'escalated_guest_tickets_disabled',
            __('Guest tickets are disabled.', 'escalated'),
            ['status' => 403]
        );
    }

    /**
     * Gate for the widget KB endpoints, matching the customer KB: 404 while
     * the knowledge base is off, 403 for anonymous callers when it is not
     * public. Null when the caller may read it.
     */
    public static function knowledge_base_unavailable(): ?\WP_Error
    {
        if (! KnowledgeBaseService::is_enabled()) {
            return new \WP_Error('escalated_kb_disabled', __('Knowledge base is disabled.', 'escalated'), ['status' => 404]);
        }
        if (! KnowledgeBaseService::can_access()) {
            return new \WP_Error('escalated_kb_private', __('Knowledge base requires sign-in.', 'escalated'), ['status' => 403]);
        }

        return null;
    }

    /**
     * Permission callback for /config: a disabled widget reaches get_config()
     * so it can answer {enabled: false}; otherwise the usual guard applies.
     *
     * @return bool|\WP_Error
     */
    public function config_permission_check()
    {
        if (! Setting::get_bool('widget_enabled', false)) {
            return true;
        }

        return $this->widget_enabled_check();
    }

    /**
     * Get widget configuration.
     */
    public function get_config()
    {
        if (! Setting::get_bool('widget_enabled', false)) {
            return $this->success([
                'enabled' => false,
                'code' => 'escalated_widget_disabled',
                'message' => __('Widget is not enabled.', 'escalated'),
            ], 403);
        }

        $departments = [];
        foreach (Department::active() as $department) {
            $departments[] = ['id' => (int) $department->id, 'name' => $department->name];
        }

        return $this->success([
            'enabled' => true,
            'color' => Setting::get('widget_color', '#3B82F6'),
            'position' => Setting::get('widget_position', 'bottom-right'),
            'greeting' => Setting::get('widget_greeting', __('Hi there! How can we help?', 'escalated')),
            'departments' => $departments,
            'kb_enabled' => KnowledgeBaseService::can_access(),
            'guest_tickets_enabled' => Setting::get_bool('guest_tickets_enabled', true),
            'accepts_metadata' => true,
            // Guests prove their mailbox with an emailed code before they
            // create a ticket, start a chat or look a ticket up.
            'guest_verification_required' => true,
            'status_lookup_enabled' => true,
        ]);
    }

    /**
     * REST arg for an optional department id. The widget sends null when no
     * department is picked.
     *
     * @return array<string, mixed>
     */
    public static function department_arg(): array
    {
        return [
            'type' => ['integer', 'null'],
            'sanitize_callback' => static fn ($value) => $value === null || $value === '' ? null : absint($value),
        ];
    }

    /**
     * The id of an active department, or null for a missing, unknown or
     * inactive one (an unknown department is ignored, not an error).
     *
     * @param  mixed  $value
     */
    public static function active_department_id($value): ?int
    {
        $id = absint($value);
        if ($id === 0) {
            return null;
        }

        $department = Department::find($id);

        return $department && (int) $department->is_active === 1 ? $id : null;
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
        $unavailable = self::knowledge_base_unavailable();
        if ($unavailable) {
            return $unavailable;
        }

        $q = $request->get_param('q');
        $search = $q !== null && $q !== '' ? $q : $request->get_param('search');

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

        if ($q !== null && $q !== '') {
            return $this->success(['articles' => $articles]);
        }

        return $this->success($articles);
    }

    /**
     * Get a single published KB article by slug. Increments the view counter
     * and returns related articles from the same category.
     */
    public function get_article(WP_REST_Request $request)
    {
        $unavailable = self::knowledge_base_unavailable();
        if ($unavailable) {
            return $unavailable;
        }

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

        $body = wp_kses_post((string) $article->body);

        return $this->success([
            'id' => (int) $article->id,
            'title' => $article->title,
            'slug' => $article->slug,
            'body' => $body,
            'content' => $body,
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
        $unavailable = self::knowledge_base_unavailable();
        if ($unavailable) {
            return $unavailable;
        }

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
        $disabled = self::guest_tickets_disabled();
        if ($disabled) {
            return $disabled;
        }

        // Per-IP guest ticket limit, on top of the widget-wide limit in
        // widget_enabled_check(). See GuestRateLimiter for configuration and
        // the reverse-proxy caveat.
        $limited = self::guest_limited('ticket');
        if ($limited) {
            return $limited;
        }

        $email = (string) $request->get_param('email');
        if (! is_email($email)) {
            return GuestVerificationEndpoints::invalid(['email' => [__('Enter a valid email address.', 'escalated')]]);
        }

        $proof = GuestVerificationEndpoints::consume_proof($request, $email, 'ticket');
        if ($proof !== true) {
            return $proof;
        }

        $service = new TicketService;

        try {
            $ticket = $service->create_guest([
                'subject' => $request->get_param('subject'),
                'description' => wp_kses_post($request->get_param('description')),
                'guest_name' => $request->get_param('name'),
                'guest_email' => $email,
                'channel' => 'widget',
                'department_id' => self::active_department_id($request->get_param('department_id')),
                'metadata' => WidgetMetadata::from_request($request),
            ]);
        } catch (\Throwable $e) {
            // The ticket was not created: hand the code back for a retry.
            (new GuestEmailVerification)->release((string) $request->get_param('verification_id'));

            return $this->error('escalated_create_failed', $e->getMessage(), 500);
        }

        $grant = GuestAccess::issue($ticket);
        $body = [
            'message' => __('Ticket created successfully.', 'escalated'),
            'reference' => $ticket->reference,
            'guest_access_token' => $grant['token'],
            'expires_at' => $grant['expires_at'],
        ];
        if (GuestAccess::legacy_tokens_enabled()) {
            // Kept for embedders that read it during the transition.
            $body['guest_token'] = $ticket->guest_token;
        }

        return GuestVerificationEndpoints::no_store($this->success($body, 201));
    }

    /**
     * A ticket's status. Needs `Authorization: Bearer <grant>` for the same
     * reference, or the legacy ?guest_token= while legacy tokens are
     * accepted. An email address never authorises it.
     */
    public function get_ticket(WP_REST_Request $request)
    {
        $ref = sanitize_text_field($request->get_param('ref'));
        $token = GuestVerificationEndpoints::bearer($request);
        if ($token === '' && GuestAccess::legacy_tokens_enabled()) {
            $token = sanitize_text_field((string) $request->get_param('guest_token'));
        }

        $ticket = Ticket::find_by_reference($ref);
        if (! $ticket) {
            return $this->error('escalated_not_found', __('Ticket not found.', 'escalated'), 404);
        }

        $granted = $token !== '' ? GuestAccess::ticket_for_token($token) : null;
        if (! $granted || (int) $granted->id !== (int) $ticket->id) {
            return $this->error('escalated_forbidden', __('The link has expired. Verify your email for a new one.', 'escalated'), 403);
        }

        return GuestVerificationEndpoints::no_store($this->success([
            'reference' => $ticket->reference,
            'subject' => $ticket->subject,
            'status' => $ticket->status,
            'created_at' => $ticket->created_at,
            'updated_at' => $ticket->updated_at,
        ]));
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
            'widget_allowed_origins' => (string) Setting::get('widget_allowed_origins', ''),
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

        // Embedding sites are saved only when every entry is a valid origin.
        if (isset($settings['widget_allowed_origins'])) {
            $origins = sanitize_textarea_field((string) $settings['widget_allowed_origins']);
            if (WidgetOrigins::invalid_entries($origins) === []) {
                Setting::set('widget_allowed_origins', $origins);
            }
        }
    }
}
