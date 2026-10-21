<?php

/**
 * Guest Controller - verified guest access for the guest pages
 * ([escalated_guest_create] and the guest ticket view).
 *
 * POST /guest/verification emails a code; POST /guest/lookup trades a lookup
 * code and a ticket reference for fresh private links. See
 * docs/guest-access.md.
 */

namespace Escalated\Api;

use Escalated\Frontend\Guest_Handler;
use Escalated\Models\Setting;
use Escalated\Services\GuestRateLimiter;
use WP_REST_Request;
use WP_REST_Server;

class Guest_Controller extends Base_Controller
{
    protected $rest_base = 'guest';

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/'.$this->rest_base.'/verification', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [GuestVerificationEndpoints::class, 'request_code'],
                'permission_callback' => [$this, 'guest_check'],
            ],
        ]);

        register_rest_route($this->namespace, '/'.$this->rest_base.'/lookup', [
            [
                'methods' => WP_REST_Server::CREATABLE,
                'callback' => [$this, 'lookup'],
                'permission_callback' => [$this, 'guest_check'],
            ],
        ]);
    }

    /**
     * Guest tickets must be on, and the per-IP guest reply limit applies.
     *
     * @return bool|\WP_Error
     */
    public function guest_check()
    {
        if (! Setting::get_bool('guest_tickets_enabled', true)) {
            return new \WP_Error('escalated_guest_tickets_disabled', __('Guest tickets are disabled.', 'escalated'), ['status' => 403]);
        }

        $retry_after = GuestRateLimiter::attempt('reply');
        if ($retry_after !== null) {
            $rejection = GuestRateLimiter::rejection($retry_after);

            return new \WP_Error($rejection['code'], $rejection['message'], ['status' => 429, 'retry_after' => $retry_after]);
        }

        return true;
    }

    /**
     * Like the widget's lookup, plus each ticket's private `view_url`.
     */
    public function lookup(WP_REST_Request $request)
    {
        return GuestVerificationEndpoints::lookup($request, static function (array $match, object $ticket, array $grant): array {
            $match['view_url'] = Guest_Handler::generate_view_url($ticket, $grant['token']);

            return $match;
        });
    }
}
