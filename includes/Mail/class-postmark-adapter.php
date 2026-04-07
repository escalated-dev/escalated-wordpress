<?php

namespace Escalated\Mail;

class Postmark_Adapter
{
    /**
     * Verify the Postmark webhook request.
     *
     * Checks the X-Postmark-Token header or query parameter against the configured token.
     *
     * @param  \WP_REST_Request  $request  The incoming webhook request.
     * @return bool True if the request is authenticated, false otherwise.
     */
    public function verify_request(\WP_REST_Request $request): bool
    {
        $expected_token = \Escalated\Models\Setting::get('postmark_inbound_token', '');
        if (empty($expected_token)) {
            return false;
        }

        // Check header first, then fall back to query parameter.
        $provided_token = $request->get_header('X-Postmark-Token');
        if (empty($provided_token)) {
            $provided_token = $request->get_param('token');
        }

        if (empty($provided_token)) {
            return false;
        }

        return hash_equals($expected_token, $provided_token);
    }

    /**
     * Parse a Postmark inbound webhook request into an Inbound_Message.
     *
     * @param  \WP_REST_Request  $request  The incoming webhook request.
     * @return Inbound_Message The parsed inbound message.
     */
    public function parse_request(\WP_REST_Request $request): Inbound_Message
    {
        $json = $request->get_json_params();

        $from_email = $json['FromFull']['Email'] ?? ($json['From'] ?? '');
        $from_name = $json['FromFull']['Name'] ?? null;

        // Get the first "To" recipient.
        $to_email = '';
        if (! empty($json['ToFull']) && is_array($json['ToFull'])) {
            $to_email = $json['ToFull'][0]['Email'] ?? '';
        }
        if (empty($to_email) && ! empty($json['To'])) {
            $to_email = $this->extract_first_email($json['To']);
        }

        $subject = $json['Subject'] ?? '';
        $body_text = $json['TextBody'] ?? null;
        $body_html = $json['HtmlBody'] ?? null;
        $message_id = $json['MessageID'] ?? null;

        // Parse headers.
        $headers = [];
        $in_reply_to = null;
        $references = null;
        if (! empty($json['Headers']) && is_array($json['Headers'])) {
            foreach ($json['Headers'] as $header) {
                $name = $header['Name'] ?? '';
                $value = $header['Value'] ?? '';
                if (! empty($name)) {
                    $headers[$name] = $value;
                    if (strtolower($name) === 'in-reply-to') {
                        $in_reply_to = $value;
                    }
                    if (strtolower($name) === 'references') {
                        $references = $value;
                    }
                    if (strtolower($name) === 'message-id' && empty($message_id)) {
                        $message_id = $value;
                    }
                }
            }
        }

        // Parse attachments.
        $attachments = $this->parse_attachments($json);

        return new Inbound_Message(
            fromEmail: $from_email,
            fromName: $from_name,
            toEmail: $to_email,
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
     * Extract the first email address from a comma-separated email string.
     *
     * @param  string  $to  The To header string.
     * @return string The first email address found.
     */
    private function extract_first_email(string $to): string
    {
        if (preg_match('/<([^>]+)>/', $to, $matches)) {
            return $matches[1];
        }
        $parts = explode(',', $to);

        return trim($parts[0]);
    }

    /**
     * Parse attachments from the Postmark JSON payload.
     *
     * @param  array  $json  The parsed JSON payload.
     * @return array Array of attachment data arrays with base64-decoded content.
     */
    private function parse_attachments(array $json): array
    {
        $attachments = [];

        if (empty($json['Attachments']) || ! is_array($json['Attachments'])) {
            return $attachments;
        }

        foreach ($json['Attachments'] as $attachment) {
            $content = ! empty($attachment['Content']) ? base64_decode($attachment['Content']) : '';
            $attachments[] = [
                'name' => $attachment['Name'] ?? 'attachment',
                'type' => $attachment['ContentType'] ?? 'application/octet-stream',
                'content' => $content,
                'size' => strlen($content),
                'cid' => $attachment['ContentID'] ?? null,
            ];
        }

        return $attachments;
    }
}
