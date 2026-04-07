<?php

namespace Escalated\Mail;

class Mailgun_Adapter
{
    /**
     * Verify the Mailgun webhook request using HMAC-SHA256 signature.
     *
     * Validates the signature and enforces a 5-minute replay protection window.
     *
     * @param  \WP_REST_Request  $request  The incoming webhook request.
     * @return bool True if the request signature is valid, false otherwise.
     */
    public function verify_request(\WP_REST_Request $request): bool
    {
        $api_key = \Escalated\Models\Setting::get('mailgun_webhook_signing_key', '');
        if (empty($api_key)) {
            return false;
        }

        $timestamp = $request->get_param('timestamp');
        $token = $request->get_param('token');
        $signature = $request->get_param('signature');

        if (empty($timestamp) || empty($token) || empty($signature)) {
            return false;
        }

        // Replay protection: reject requests older than 5 minutes.
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $computed = hash_hmac('sha256', $timestamp.$token, $api_key);

        return hash_equals($computed, $signature);
    }

    /**
     * Parse a Mailgun webhook request into an Inbound_Message.
     *
     * @param  \WP_REST_Request  $request  The incoming webhook request.
     * @return Inbound_Message The parsed inbound message.
     */
    public function parse_request(\WP_REST_Request $request): Inbound_Message
    {
        $from = $request->get_param('from') ?? '';
        $from_email = $this->extract_email($from);
        $from_name = $this->extract_name($from);

        $recipient = $request->get_param('recipient') ?? '';
        $subject = $request->get_param('subject') ?? '';
        $body_text = $request->get_param('body-plain');
        $body_html = $request->get_param('body-html');
        $message_id = $request->get_param('Message-Id');
        $in_reply_to = $request->get_param('In-Reply-To');
        $references = $request->get_param('References');

        // Parse headers from the message-headers JSON parameter.
        $headers = [];
        $raw_headers = $request->get_param('message-headers');
        if (! empty($raw_headers)) {
            $decoded = is_string($raw_headers) ? json_decode($raw_headers, true) : $raw_headers;
            if (is_array($decoded)) {
                foreach ($decoded as $header) {
                    if (is_array($header) && count($header) >= 2) {
                        $headers[$header[0]] = $header[1];
                    }
                }
            }
        }

        // Parse attachments from file params.
        $attachments = $this->parse_attachments($request);

        return new Inbound_Message(
            fromEmail: $from_email,
            fromName: $from_name,
            toEmail: $recipient,
            subject: $subject,
            bodyText: $body_text,
            bodyHtml: $body_html,
            messageId: $message_id,
            inReplyTo: $in_reply_to,
            references: $references,
            headers: $headers,
            attachments: $attachments,
        );
    }

    /**
     * Extract an email address from a "Name <email>" formatted string.
     *
     * @param  string  $from  The from string.
     * @return string The extracted email address.
     */
    private function extract_email(string $from): string
    {
        if (preg_match('/<([^>]+)>/', $from, $matches)) {
            return $matches[1];
        }

        return trim($from);
    }

    /**
     * Extract a display name from a "Name <email>" formatted string.
     *
     * @param  string  $from  The from string.
     * @return string|null The extracted name, or null if not present.
     */
    private function extract_name(string $from): ?string
    {
        if (preg_match('/^(.+?)\s*</', $from, $matches)) {
            return trim($matches[1], ' "');
        }

        return null;
    }

    /**
     * Parse file attachments from the webhook request.
     *
     * @param  \WP_REST_Request  $request  The incoming webhook request.
     * @return array Array of attachment data arrays.
     */
    private function parse_attachments(\WP_REST_Request $request): array
    {
        $file_params = $request->get_file_params();
        $attachments = [];

        if (empty($file_params)) {
            return $attachments;
        }

        // Mailgun sends attachments as attachment-1, attachment-2, etc.
        foreach ($file_params as $key => $file) {
            if (strpos($key, 'attachment') !== 0) {
                continue;
            }
            if (! empty($file['tmp_name']) && is_uploaded_file($file['tmp_name'])) {
                $attachments[] = [
                    'name' => $file['name'] ?? 'attachment',
                    'type' => $file['type'] ?? 'application/octet-stream',
                    'tmp_name' => $file['tmp_name'],
                    'size' => $file['size'] ?? 0,
                ];
            }
        }

        return $attachments;
    }
}
