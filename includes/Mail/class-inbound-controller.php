<?php

namespace Escalated\Mail;

class Inbound_Controller
{
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route('escalated/v1', '/inbound/(?P<adapter>[a-z]+)', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_webhook'],
            'permission_callback' => '__return_true', // Auth via webhook signature
        ]);
    }

    public function handle_webhook(\WP_REST_Request $request): \WP_REST_Response
    {
        if (! \Escalated\Models\Setting::get_bool('inbound_email_enabled', false)) {
            return new \WP_REST_Response(['error' => 'Inbound email is disabled.'], 404);
        }

        $adapter_name = $request->get_param('adapter');
        $adapter = match ($adapter_name) {
            'mailgun' => new Mailgun_Adapter,
            'postmark' => new Postmark_Adapter,
            'ses' => new Ses_Adapter,
            default => null,
        };

        if (! $adapter) {
            return new \WP_REST_Response(['error' => 'Unknown adapter.'], 400);
        }

        if (! $adapter->verify_request($request)) {
            return new \WP_REST_Response(['error' => 'Invalid signature.'], 403);
        }

        try {
            $message = $adapter->parse_request($request);

            // An SNS subscription or unsubscribe confirmation carries no email.
            // verify_request() has already handled it.
            if ($message === null) {
                return new \WP_REST_Response(['status' => 'ok', 'ticket_id' => null]);
            }

            $ticket = (new \Escalated\Services\InboundEmailService)->process($message, $adapter_name);

            // A null ticket is a redelivered email, or a failure already
            // recorded on its inbound_emails row. Either way the provider
            // should not retry.
            return new \WP_REST_Response(['status' => 'ok', 'ticket_id' => $ticket ? (int) $ticket->id : null]);
        } catch (\Throwable $e) {
            return new \WP_REST_Response(['error' => 'Processing failed.'], 500);
        }
    }
}
