<?php

/**
 * Mobile Guest Controller - the mobile guest API.
 *
 * The routes a mobile app uses to let a guest (no account) file a ticket,
 * read it, reply to it and get back into it later, under the plugin's REST
 * namespace: /wp-json/escalated/v1/mobile/guest/...
 *
 *   POST verification              email a code (same handler as /widget and /guest)
 *   POST lookup                    fresh grants for a reference (same handler)
 *   POST tickets                   create a ticket with a `ticket` code
 *   GET  tickets/{token}           the ticket the grant opens
 *   POST tickets/{token}/replies   reply as the guest
 *
 * {token} is the guest access grant from GuestAccess::issue(), shared with
 * the widget and the guest pages. Any bad, expired, rotated or chat
 * credential is a 404. Canonical contract:
 * https://github.com/escalated-dev/escalated-developer-context/blob/main/domain-model/mobile-guest-api.md
 * See docs/guest-access.md.
 */

namespace Escalated\Api;

use Escalated\Helpers\Enums;
use Escalated\Models\Attachment;
use Escalated\Models\Department;
use Escalated\Models\Reply;
use Escalated\Models\Setting;
use Escalated\Models\Ticket;
use Escalated\Services\AttachmentService;
use Escalated\Services\GuestAccess;
use Escalated\Services\GuestEmailVerification;
use Escalated\Services\GuestRateLimiter;
use Escalated\Services\TicketAccess;
use Escalated\Services\TicketService;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Mobile_Guest_Controller extends Base_Controller
{
    protected $rest_base = 'mobile/guest';

    /** Grants are opaque; anything longer than this is not one. */
    private const MAX_TOKEN_LENGTH = 2048;

    private const MAX_ATTACHMENTS = 10;

    public function register_routes(): void
    {
        $base = '/'.$this->rest_base;
        $routes = [
            [$base.'/verification', WP_REST_Server::CREATABLE, 'verification'],
            [$base.'/lookup', WP_REST_Server::CREATABLE, 'lookup'],
            [$base.'/tickets', WP_REST_Server::CREATABLE, 'create_ticket'],
            [$base.'/tickets/(?P<token>[^/]+)', WP_REST_Server::READABLE, 'show_ticket'],
            [$base.'/tickets/(?P<token>[^/]+)/replies', WP_REST_Server::CREATABLE, 'reply'],
        ];

        // Validation, limits and guest checks run in the callbacks so their
        // order is the contract's and every answer gets the guest headers.
        foreach ($routes as [$path, $methods, $callback]) {
            register_rest_route($this->namespace, $path, [[
                'methods' => $methods,
                'callback' => [$this, $callback],
                'permission_callback' => '__return_true',
            ]]);
        }
    }

    /**
     * POST verification: {email, purpose}. Guest budget: submissions.
     */
    public function verification(WP_REST_Request $request): WP_REST_Response
    {
        return $this->guarded('ticket', static fn () => GuestVerificationEndpoints::request_code($request));
    }

    /**
     * POST lookup: {email, reference, verification_id, verification_code}.
     * Guest budget: replies.
     */
    public function lookup(WP_REST_Request $request): WP_REST_Response
    {
        return $this->guarded('reply', static function () use ($request) {
            return Widget_Controller::guest_tickets_disabled() ?? GuestVerificationEndpoints::lookup($request);
        });
    }

    /**
     * POST tickets. Guest budget: submissions.
     */
    public function create_ticket(WP_REST_Request $request): WP_REST_Response
    {
        return $this->guarded('ticket', function () use ($request) {
            if (! Setting::get_bool('guest_tickets_enabled', true)) {
                return new WP_REST_Response([
                    'code' => 'escalated_guest_tickets_disabled',
                    'message' => __('Guest tickets are not enabled.', 'escalated'),
                ], 403);
            }

            $files = self::uploaded_files($request);
            $errors = self::ticket_errors($request) + self::attachment_errors($files);
            if ($errors !== []) {
                return GuestVerificationEndpoints::invalid($errors);
            }

            $email = GuestEmailVerification::normalize_email((string) $request->get_param('email'));
            $proof = GuestVerificationEndpoints::consume_proof($request, $email, 'ticket');
            if ($proof !== true) {
                return $proof;
            }

            $priority = self::string_param($request, 'priority');
            $department_id = absint($request->get_param('department_id'));

            try {
                $ticket = (new TicketService)->create_guest([
                    'subject' => self::string_param($request, 'subject'),
                    'description' => wp_kses_post(self::string_param($request, 'description')),
                    'guest_name' => self::string_param($request, 'name'),
                    'guest_email' => $email,
                    'channel' => 'web',
                    'priority' => $priority !== '' ? $priority : null,
                    'department_id' => $department_id ?: null,
                ]);
                $attachments = new AttachmentService;
                foreach ($files as $file) {
                    $attachments->store('ticket', (int) $ticket->id, $file);
                }
            } catch (\Throwable $e) {
                // Hand the code back so the guest can retry.
                (new GuestEmailVerification)->release((string) $request->get_param('verification_id'));

                return new WP_REST_Response([
                    'code' => 'escalated_create_failed',
                    'message' => __('The ticket could not be created. Please try again.', 'escalated'),
                ], 500);
            }

            $grant = GuestAccess::issue($ticket);
            $ticket = TicketAccess::without_restriction(static fn () => Ticket::find((int) $ticket->id));

            return new WP_REST_Response([
                'data' => self::present($ticket, $grant['token'], false),
                'message' => __('Ticket created.', 'escalated'),
            ], 201);
        });
    }

    /**
     * GET tickets/{token}. Only the API limit applies.
     */
    public function show_ticket(WP_REST_Request $request): WP_REST_Response
    {
        return $this->guarded(null, static function () use ($request) {
            $token = (string) $request->get_param('token');
            $ticket = self::ticket_for($token);
            if (! $ticket) {
                return self::not_found();
            }

            return new WP_REST_Response(['data' => self::present($ticket, $token, true)], 200);
        });
    }

    /**
     * POST tickets/{token}/replies: {email, body, attachments[]}. Guest
     * budget: replies.
     */
    public function reply(WP_REST_Request $request): WP_REST_Response
    {
        return $this->guarded('reply', static function () use ($request) {
            $ticket = self::ticket_for((string) $request->get_param('token'));
            if (! $ticket) {
                return self::not_found();
            }

            if ($ticket->status === 'closed') {
                return new WP_REST_Response([
                    'code' => 'escalated_ticket_closed',
                    'message' => __('This ticket is closed.', 'escalated'),
                ], 422);
            }

            $body = self::string_param($request, 'body');
            $email = self::string_param($request, 'email');
            $files = self::uploaded_files($request);
            $errors = [];
            if (trim($body) === '' || mb_strlen($body) > 65535) {
                $errors['body'] = [__('Enter a reply of at most 65,535 characters.', 'escalated')];
            }
            if (! is_email(trim($email))) {
                $errors['email'] = [__('Enter a valid email address.', 'escalated')];
            }
            $errors += self::attachment_errors($files);
            if ($errors !== []) {
                return GuestVerificationEndpoints::invalid($errors);
            }

            // A second factor on top of the grant, not a substitute for it.
            if (! hash_equals(GuestEmailVerification::normalize_email((string) $ticket->guest_email), GuestEmailVerification::normalize_email($email))) {
                return new WP_REST_Response([
                    'code' => 'escalated_email_mismatch',
                    'message' => __('The provided email does not match this ticket.', 'escalated'),
                ], 403);
            }

            $now = current_time('mysql');
            $reply_id = Reply::create([
                'ticket_id' => (int) $ticket->id,
                'author_id' => null,
                'body' => wp_kses_post($body),
                'is_internal_note' => 0,
                'type' => 'reply',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if (! $reply_id) {
                return new WP_REST_Response([
                    'code' => 'escalated_reply_failed',
                    'message' => __('The reply could not be saved. Please try again.', 'escalated'),
                ], 500);
            }

            $attachments = new AttachmentService;
            foreach ($files as $file) {
                try {
                    $attachments->store('reply', (int) $reply_id, $file);
                } catch (\InvalidArgumentException $e) {
                    // Validated above; a file that still fails is skipped.
                }
            }

            do_action('escalated_reply_created', Reply::find($reply_id), $ticket);

            return new WP_REST_Response(['message' => __('Reply sent.', 'escalated')], 201);
        });
    }

    // =========================================================================
    // The mobile ticket object
    // =========================================================================

    /**
     * A ticket as the mobile guest API returns it: an allow-list, never the
     * row. `metadata` is always an empty object; staff appear by display
     * name only; replies are public ones, newest first.
     *
     * @return array<string, mixed>
     */
    public static function present(object $ticket, ?string $grant, bool $with_replies): array
    {
        $status = (string) $ticket->status;
        $priority = (string) $ticket->priority;
        $statuses = Enums::ticket_statuses();
        $priorities = Enums::ticket_priorities();

        $department = null;
        if (! empty($ticket->department_id)) {
            $row = Department::find((int) $ticket->department_id);
            $department = $row ? ['id' => (int) $row->id, 'name' => (string) $row->name] : null;
        }

        return [
            'id' => (int) $ticket->id,
            'reference' => (string) $ticket->reference,
            'guest_access_token' => $grant,
            'guest_access_expires_at' => $grant !== null ? self::iso_utc($ticket->guest_access_expires_at ?? null) : null,
            'subject' => (string) $ticket->subject,
            'description' => (string) ($ticket->description ?? ''),
            'status' => ['value' => $status, 'label' => $statuses[$status]['label'] ?? ucwords(str_replace('_', ' ', $status))],
            'priority' => ['value' => $priority, 'label' => $priorities[$priority]['label'] ?? ucwords($priority)],
            'channel' => (string) ($ticket->channel ?? 'web'),
            'metadata' => new \stdClass,
            'requester' => [
                'name' => (string) ($ticket->guest_name ?: 'Guest'),
                'email' => (string) $ticket->guest_email,
            ],
            'assignee' => empty($ticket->assigned_to) ? null : ['id' => 0, 'name' => self::staff_name((int) $ticket->assigned_to), 'email' => ''],
            'department' => $department,
            'tags' => [],
            'replies' => $with_replies ? self::replies($ticket) : [],
            'activities' => [],
            'sla' => [
                'first_response_due_at' => self::iso_local($ticket->first_response_due_at ?? null),
                'first_response_at' => self::iso_local($ticket->first_response_at ?? null),
                'first_response_breached' => (bool) ($ticket->sla_first_response_breached ?? false),
                'resolution_due_at' => self::iso_local($ticket->resolution_due_at ?? null),
                'resolution_breached' => (bool) ($ticket->sla_resolution_breached ?? false),
            ],
            'is_following' => false,
            'followers_count' => 0,
            'resolved_at' => self::iso_local($ticket->resolved_at ?? null),
            'closed_at' => self::iso_local($ticket->closed_at ?? null),
            'created_at' => self::iso_local($ticket->created_at ?? null),
            'updated_at' => self::iso_local($ticket->updated_at ?? null),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function replies(object $ticket): array
    {
        $replies = Reply::for_ticket((int) $ticket->id, false);
        usort($replies, static fn ($a, $b) => [strtotime((string) $b->created_at), (int) $b->id] <=> [strtotime((string) $a->created_at), (int) $a->id]);

        $out = [];
        foreach ($replies as $reply) {
            if (! empty($reply->is_internal_note)) {
                continue;
            }
            $author_id = (int) ($reply->author_id ?? 0);
            $is_guest = $author_id === 0 || ($author_id === (int) ($ticket->requester_id ?? 0) && ! TicketAccess::is_staff($author_id));

            $out[] = [
                'id' => (int) $reply->id,
                'body' => (string) $reply->body,
                'is_internal_note' => false,
                'is_pinned' => (bool) ($reply->is_pinned ?? false),
                'author' => $is_guest
                    ? ['id' => 0, 'name' => (string) ($ticket->guest_name ?: 'Guest'), 'email' => (string) $ticket->guest_email]
                    : ['id' => 0, 'name' => self::staff_name($author_id), 'email' => ''],
                'attachments' => array_map(static fn ($attachment) => [
                    'id' => (int) $attachment->id,
                    'filename' => (string) $attachment->original_filename,
                    'mime_type' => (string) $attachment->mime_type,
                    'size' => (int) $attachment->size,
                    'url' => AttachmentService::guest_download_url($attachment, $ticket),
                ], Attachment::for_attachable('reply', (int) $reply->id) ?: []),
                'created_at' => self::iso_local($reply->created_at ?? null),
            ];
        }

        return $out;
    }

    private static function staff_name(int $user_id): string
    {
        $user = $user_id > 0 ? get_userdata($user_id) : false;

        return $user && $user->display_name !== '' ? (string) $user->display_name : 'Support';
    }

    /** A site-local MySQL datetime (current_time('mysql')) as ISO 8601. */
    private static function iso_local($value): ?string
    {
        if (empty($value) || strpos((string) $value, '0000-00-00') === 0) {
            return null;
        }

        return get_gmt_from_date((string) $value, 'c');
    }

    /** A UTC MySQL datetime (gmdate()) as ISO 8601. */
    private static function iso_utc($value): ?string
    {
        if (empty($value) || strpos((string) $value, '0000-00-00') === 0) {
            return null;
        }

        return gmdate('c', (int) strtotime($value.' UTC'));
    }

    // =========================================================================
    // Validation
    // =========================================================================

    /**
     * @return array<string, string[]>
     */
    private static function ticket_errors(WP_REST_Request $request): array
    {
        $errors = [];
        $required = [
            'name' => 255,
            'subject' => 255,
            'description' => 65535,
        ];
        foreach ($required as $field => $max) {
            $value = self::string_param($request, $field);
            if (trim($value) === '') {
                $errors[$field] = [sprintf(__('The %s field is required.', 'escalated'), $field)];
            } elseif (mb_strlen($value) > $max) {
                $errors[$field] = [sprintf(__('The %1$s field must be at most %2$d characters.', 'escalated'), $field, $max)];
            }
        }

        $email = trim(self::string_param($request, 'email'));
        if (! is_email($email) || strlen($email) > 255) {
            $errors['email'] = [__('Enter a valid email address.', 'escalated')];
        }

        $verification_id = self::string_param($request, 'verification_id');
        if ($verification_id === '' || strlen($verification_id) > 64) {
            $errors['verification_id'] = [__('Verify your email address first: request a code and enter it.', 'escalated')];
        }
        $code = self::string_param($request, 'verification_code');
        if ($code === '' || strlen($code) > 16) {
            $errors['verification_code'] = [__('Verify your email address first: request a code and enter it.', 'escalated')];
        }

        $priority = $request->get_param('priority');
        if ($priority !== null && $priority !== '' && ! (is_string($priority) && isset(Enums::ticket_priorities()[$priority]))) {
            $errors['priority'] = [__('The priority must be low, medium, high, urgent or critical.', 'escalated')];
        }

        $department_id = $request->get_param('department_id');
        if ($department_id !== null && $department_id !== '') {
            if (! is_numeric($department_id) || ! Department::find(absint($department_id))) {
                $errors['department_id'] = [__('The selected department does not exist.', 'escalated')];
            }
        }

        return $errors;
    }

    /**
     * @param  array<int, array<string, mixed>>  $files
     * @return array<string, string[]>
     */
    private static function attachment_errors(array $files): array
    {
        if (count($files) > self::MAX_ATTACHMENTS) {
            return ['attachments' => [sprintf(__('Attach at most %d files.', 'escalated'), self::MAX_ATTACHMENTS)]];
        }

        $service = new AttachmentService;
        foreach ($files as $file) {
            try {
                $service->validate_file($file);
            } catch (\InvalidArgumentException $e) {
                return ['attachments' => [$e->getMessage()]];
            }
        }

        return [];
    }

    /**
     * The `attachments` / `attachments[]` uploads as a list of single-file
     * arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function uploaded_files(WP_REST_Request $request): array
    {
        $params = $request->get_file_params();
        $upload = $params['attachments'] ?? null;
        if (! is_array($upload) || ! isset($upload['name'])) {
            return [];
        }
        if (! is_array($upload['name'])) {
            return ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$upload];
        }

        $files = [];
        foreach (array_keys($upload['name']) as $i) {
            if (($upload['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = [
                'name' => $upload['name'][$i],
                'type' => $upload['type'][$i] ?? '',
                'tmp_name' => $upload['tmp_name'][$i] ?? '',
                'error' => $upload['error'][$i] ?? UPLOAD_ERR_OK,
                'size' => (int) ($upload['size'][$i] ?? 0),
            ];
        }

        return $files;
    }

    private static function string_param(WP_REST_Request $request, string $key): string
    {
        $value = $request->get_param($key);

        return is_scalar($value) ? (string) $value : '';
    }

    // =========================================================================
    // Grants, limits and headers
    // =========================================================================

    /**
     * The ticket an active grant opens, or null. Legacy permanent links and
     * chat tokens never open the mobile routes.
     */
    private static function ticket_for(string $token): ?object
    {
        if ($token === '' || strlen($token) > self::MAX_TOKEN_LENGTH) {
            return null;
        }

        return GuestAccess::ticket_for_grant($token);
    }

    private static function not_found(): WP_REST_Response
    {
        return new WP_REST_Response([
            'code' => 'escalated_not_found',
            'message' => __('Ticket not found.', 'escalated'),
        ], 404);
    }

    /**
     * Run a handler behind the API limit and the shared guest budget, and
     * give every answer, errors included, the guest privacy headers.
     *
     * @param  string|null  $guest_scope  GuestRateLimiter scope: 'ticket'
     *                                    (submissions), 'reply' (replies) or
     *                                    null (API limit only).
     */
    private function guarded(?string $guest_scope, callable $handler): WP_REST_Response
    {
        $api = self::api_attempt();

        if ($api['retry_after'] !== null) {
            $response = new WP_REST_Response([
                'message' => __('Too many requests.', 'escalated'),
                'retry_after' => $api['retry_after'],
            ], 429);
            $response->header('Retry-After', (string) $api['retry_after']);
        } else {
            $response = $guest_scope !== null ? Widget_Controller::guest_limited($guest_scope) : null;
            if ($response === null) {
                $result = $handler();
                $response = is_wp_error($result) ? rest_convert_error_to_response($result) : rest_ensure_response($result);
            }
        }

        $response->header('X-RateLimit-Limit', (string) $api['limit']);
        $response->header('X-RateLimit-Remaining', (string) $api['remaining']);
        $response->header('Cache-Control', 'private, no-store');
        $response->header('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /**
     * The API limit (the `api_rate_limit` setting, 60 a minute by default)
     * counted per client IP, since these routes carry no API token.
     *
     * @return array{limit: int, remaining: int, retry_after: int|null}
     */
    private static function api_attempt(): array
    {
        $limit = max(1, (int) Setting::get('api_rate_limit', 60));
        $key = 'escalated_api_ip_rate_'.md5(GuestRateLimiter::client_ip());
        $now = time();

        $window = get_transient($key);
        if (! is_array($window) || (int) ($window['reset'] ?? 0) <= $now) {
            $window = ['count' => 0, 'reset' => $now + MINUTE_IN_SECONDS];
        }
        $retry_after = max(1, (int) $window['reset'] - $now);

        if ((int) $window['count'] >= $limit) {
            return ['limit' => $limit, 'remaining' => 0, 'retry_after' => $retry_after];
        }

        $window['count'] = (int) $window['count'] + 1;
        set_transient($key, $window, $retry_after);

        return ['limit' => $limit, 'remaining' => $limit - (int) $window['count'], 'retry_after' => null];
    }
}
