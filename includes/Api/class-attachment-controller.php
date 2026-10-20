<?php

/**
 * Attachment Controller - authenticated attachment downloads.
 *
 * Attachments are served only through this route, never from a public
 * storage URL. Every response is a download (Content-Disposition:
 * attachment) with nosniff and a sandbox CSP, and types a browser could
 * render as a document are sent as application/octet-stream.
 */

namespace Escalated\Api;

use Escalated\Models\Attachment;
use Escalated\Models\Reply;
use Escalated\Models\Ticket;
use Escalated\Services\AttachmentService;
use Escalated\Services\TicketAccess;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Attachment_Controller extends Base_Controller
{
    public const ROUTE = '/escalated/v1/attachments/(?P<id>\d+)/download';

    /**
     * Types sent with their own Content-Type. Everything else is
     * application/octet-stream.
     */
    private const SAFE_TYPES = [
        'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp',
        'application/pdf', 'text/plain', 'text/csv',
        'application/zip', 'application/x-zip-compressed',
        'audio/mpeg', 'audio/wav', 'video/mp4', 'video/webm',
        'application/msword', 'application/vnd.ms-excel', 'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    public function register_routes(): void
    {
        register_rest_route(
            $this->namespace,
            '/attachments/(?P<id>\d+)/download',
            [
                [
                    'methods' => WP_REST_Server::READABLE,
                    'callback' => [$this, 'download'],
                    'permission_callback' => [$this, 'download_permissions_check'],
                    'args' => [
                        'id' => [
                            'required' => true,
                            'type' => 'integer',
                            'sanitize_callback' => 'absint',
                        ],
                    ],
                ],
            ]
        );

        // A static callback, so registering routes again adds it only once.
        add_filter('rest_pre_serve_request', [self::class, 'serve_raw'], 10, 4);
    }

    /**
     * A bearer token (tickets:read) or a signed-in session with a REST nonce.
     *
     * @return bool|\WP_Error
     */
    public function download_permissions_check(WP_REST_Request $request)
    {
        if ($request->get_header('Authorization')) {
            return $this->check_token_permission($request, 'tickets:read') !== null
                ? true
                : $this->error('escalated_unauthorized', __('Invalid or expired API token.', 'escalated'), 401);
        }

        return is_user_logged_in()
            ? true
            : $this->error('escalated_unauthorized', __('Sign in to download attachments.', 'escalated'), 401);
    }

    /**
     * @return WP_REST_Response|\WP_Error
     */
    public function download(WP_REST_Request $request)
    {
        $user = TicketAccess::actor();
        $not_found = $this->error('escalated_not_found', __('Attachment not found.', 'escalated'), 404);

        // Ticket visibility applies to the attachment, reply and ticket lookups.
        $attachment = Attachment::find((int) $request->get_param('id'));
        if (! $attachment) {
            return $not_found;
        }

        $internal = false;
        $ticket = null;
        if ($attachment->attachable_type === 'ticket') {
            $ticket = Ticket::find((int) $attachment->attachable_id);
        } elseif ($attachment->attachable_type === 'reply') {
            $reply = Reply::find((int) $attachment->attachable_id);
            if ($reply) {
                $internal = (bool) $reply->is_internal_note;
                $ticket = Ticket::find((int) $reply->ticket_id);
            }
        }
        if (! $ticket) {
            return $not_found;
        }

        if (! self::may_download($ticket, $internal, $user)) {
            return $this->error('escalated_forbidden', __('You cannot download this attachment.', 'escalated'), 403);
        }

        if (! AttachmentService::is_servable_path((string) $attachment->path)) {
            return $not_found;
        }

        $content = file_get_contents((string) $attachment->path);
        if ($content === false) {
            return $not_found;
        }

        return new WP_REST_Response($content, 200, self::headers($attachment, strlen($content)));
    }

    /**
     * Staff who can see the ticket, or the requester for anything that is
     * not an internal note.
     */
    public static function may_download(object $ticket, bool $internal, int $user): bool
    {
        if ($user <= 0) {
            return false;
        }
        if (TicketAccess::is_staff($user)) {
            return TicketAccess::allows((array) $ticket, $user);
        }

        return ! $internal && (int) $ticket->requester_id === $user;
    }

    /**
     * Download headers for an attachment.
     *
     * @return array<string, string>
     */
    public static function headers(object $attachment, int $length): array
    {
        $mime = strtolower(trim((string) $attachment->mime_type));
        $type = in_array($mime, self::SAFE_TYPES, true) ? $mime : 'application/octet-stream';

        $name = (string) ($attachment->original_filename ?: 'attachment');
        $ascii = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $name);

        return [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="'.$ascii.'"; filename*=UTF-8\'\''.rawurlencode($name),
            'Content-Length' => (string) $length,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
        ];
    }

    /**
     * Send the file bytes instead of a JSON-encoded string.
     *
     * @param  bool  $served
     * @param  mixed  $result
     * @param  mixed  $request
     * @param  mixed  $server
     * @return bool
     */
    public static function serve_raw($served, $result, $request, $server)
    {
        if ($served || ! $request instanceof WP_REST_Request || ! $result instanceof WP_REST_Response
            || $result->get_status() !== 200 || $result->get_matched_route() !== self::ROUTE) {
            return $served;
        }

        if ($request->get_method() !== 'HEAD') {
            echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- file download.
        }

        return true;
    }
}
