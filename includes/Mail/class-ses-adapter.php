<?php
namespace Escalated\Mail;

class Ses_Adapter {

    /**
     * Verify the AWS SNS request.
     *
     * Validates the TopicArn against the configured value and verifies the SNS message
     * signature using the signing certificate.
     *
     * Also handles SNS SubscriptionConfirmation messages by visiting the SubscribeURL.
     *
     * @param \WP_REST_Request $request The incoming webhook request.
     * @return bool True if the request is valid, false otherwise.
     */
    public function verify_request( \WP_REST_Request $request ): bool {
        $json = $request->get_json_params();

        if ( empty( $json ) ) {
            return false;
        }

        // Validate TopicArn if configured.
        $expected_topic_arn = \Escalated\Models\Setting::get( 'ses_topic_arn', '' );
        if ( ! empty( $expected_topic_arn ) ) {
            $topic_arn = $json['TopicArn'] ?? '';
            if ( $topic_arn !== $expected_topic_arn ) {
                return false;
            }
        }

        // Verify the SNS message signature.
        if ( ! $this->verify_sns_signature( $json ) ) {
            return false;
        }

        // Handle SubscriptionConfirmation.
        $message_type = $request->get_header( 'x-amz-sns-message-type' );
        if ( $message_type === 'SubscriptionConfirmation' ) {
            $this->confirm_subscription( $json );
            return true;
        }

        return true;
    }

    /**
     * Parse an AWS SES/SNS webhook request into an Inbound_Message.
     *
     * Handles both the SNS notification wrapper and the raw MIME content inside.
     *
     * @param \WP_REST_Request $request The incoming webhook request.
     * @return Inbound_Message The parsed inbound message.
     */
    public function parse_request( \WP_REST_Request $request ): Inbound_Message {
        $json = $request->get_json_params();
        $message_type = $request->get_header( 'x-amz-sns-message-type' );

        // For subscription confirmation, return a minimal message.
        if ( $message_type === 'SubscriptionConfirmation' ) {
            return new Inbound_Message(
                fromEmail: '',
                fromName:  null,
                toEmail:   '',
                subject:   'SNS Subscription Confirmation',
                bodyText:  'Subscription confirmed.',
                bodyHtml:  null,
            );
        }

        // The actual email content is in the Message field (JSON-encoded or raw).
        $message_content = $json['Message'] ?? '';
        $ses_message = is_string( $message_content ) ? json_decode( $message_content, true ) : $message_content;

        // If the SES message contains raw MIME content, parse it.
        if ( is_array( $ses_message ) && ! empty( $ses_message['content'] ) ) {
            return $this->parse_mime_content( $ses_message['content'], $ses_message );
        }

        // If the SES message has structured mail data.
        if ( is_array( $ses_message ) && ! empty( $ses_message['mail'] ) ) {
            return $this->parse_ses_notification( $ses_message );
        }

        // Fall back to raw MIME parsing of the Message field itself.
        if ( is_string( $message_content ) && ! empty( $message_content ) ) {
            return $this->parse_mime_content( $message_content );
        }

        // Last resort: return what we can.
        return new Inbound_Message(
            fromEmail: '',
            fromName:  null,
            toEmail:   '',
            subject:   '',
            bodyText:  is_string( $message_content ) ? $message_content : wp_json_encode( $message_content ),
            bodyHtml:  null,
        );
    }

    /**
     * Verify the SNS message signature.
     *
     * @param array $json The parsed SNS JSON payload.
     * @return bool True if the signature is valid.
     */
    private function verify_sns_signature( array $json ): bool {
        $signing_cert_url = $json['SigningCertURL'] ?? ( $json['SigningCertUrl'] ?? '' );

        if ( empty( $signing_cert_url ) ) {
            return false;
        }

        // Validate that the certificate URL is from AWS.
        $parsed = wp_parse_url( $signing_cert_url );
        if ( empty( $parsed['host'] ) || ! preg_match( '/\.amazonaws\.com$/', $parsed['host'] ) ) {
            return false;
        }
        if ( ( $parsed['scheme'] ?? '' ) !== 'https' ) {
            return false;
        }

        // Fetch the signing certificate.
        $response = wp_remote_get( $signing_cert_url, [
            'timeout' => 10,
            'sslverify' => true,
        ] );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $certificate = wp_remote_retrieve_body( $response );
        if ( empty( $certificate ) ) {
            return false;
        }

        // Build the string to sign based on message type.
        $message_type = $json['Type'] ?? '';
        $string_to_sign = $this->build_string_to_sign( $json, $message_type );

        $signature = base64_decode( $json['Signature'] ?? '' );

        $public_key = openssl_pkey_get_public( $certificate );
        if ( ! $public_key ) {
            return false;
        }

        $result = openssl_verify( $string_to_sign, $signature, $public_key, OPENSSL_ALGO_SHA1 );

        return $result === 1;
    }

    /**
     * Build the canonical string to sign for SNS signature verification.
     *
     * @param array  $json         The SNS message payload.
     * @param string $message_type The SNS message type.
     * @return string The canonical string to sign.
     */
    private function build_string_to_sign( array $json, string $message_type ): string {
        $fields = [];

        if ( $message_type === 'Notification' ) {
            $fields = [ 'Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type' ];
        } else {
            // SubscriptionConfirmation and UnsubscribeConfirmation.
            $fields = [ 'Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type' ];
        }

        $string_to_sign = '';
        foreach ( $fields as $field ) {
            if ( isset( $json[ $field ] ) ) {
                $string_to_sign .= $field . "\n" . $json[ $field ] . "\n";
            }
        }

        return $string_to_sign;
    }

    /**
     * Confirm an SNS subscription by visiting the SubscribeURL.
     *
     * @param array $json The SNS SubscriptionConfirmation payload.
     */
    private function confirm_subscription( array $json ): void {
        $subscribe_url = $json['SubscribeURL'] ?? '';
        if ( empty( $subscribe_url ) ) {
            return;
        }

        wp_remote_get( $subscribe_url, [
            'timeout'   => 10,
            'sslverify' => true,
        ] );
    }

    /**
     * Parse a structured SES notification into an Inbound_Message.
     *
     * @param array $ses_message The SES notification payload with mail/receipt keys.
     * @return Inbound_Message The parsed inbound message.
     */
    private function parse_ses_notification( array $ses_message ): Inbound_Message {
        $mail = $ses_message['mail'] ?? [];
        $headers = [];
        $from_email = '';
        $from_name = null;
        $to_email = '';
        $subject = '';
        $message_id = $mail['messageId'] ?? null;
        $in_reply_to = null;
        $references = null;

        // Parse common headers.
        if ( ! empty( $mail['commonHeaders'] ) ) {
            $common = $mail['commonHeaders'];
            $subject = $common['subject'] ?? '';
            $message_id = $common['messageId'] ?? $message_id;

            if ( ! empty( $common['from'] ) && is_array( $common['from'] ) ) {
                $from_raw = $common['from'][0];
                $from_email = $this->extract_email( $from_raw );
                $from_name = $this->extract_name( $from_raw );
            }

            if ( ! empty( $common['to'] ) && is_array( $common['to'] ) ) {
                $to_email = $this->extract_email( $common['to'][0] );
            }
        }

        // Fall back to source for from address.
        if ( empty( $from_email ) && ! empty( $mail['source'] ) ) {
            $from_email = $mail['source'];
        }

        // Fall back to destination for to address.
        if ( empty( $to_email ) && ! empty( $mail['destination'] ) && is_array( $mail['destination'] ) ) {
            $to_email = $mail['destination'][0];
        }

        // Parse detailed headers.
        if ( ! empty( $mail['headers'] ) && is_array( $mail['headers'] ) ) {
            foreach ( $mail['headers'] as $header ) {
                $name = $header['name'] ?? '';
                $value = $header['value'] ?? '';
                if ( ! empty( $name ) ) {
                    $headers[ $name ] = $value;
                    if ( strtolower( $name ) === 'in-reply-to' ) {
                        $in_reply_to = $value;
                    }
                    if ( strtolower( $name ) === 'references' ) {
                        $references = $value;
                    }
                }
            }
        }

        // Body content - SES may include it in the notification or require fetching.
        $body_text = $ses_message['content'] ?? null;
        $body_html = null;

        if ( is_string( $body_text ) && $this->looks_like_mime( $body_text ) ) {
            return $this->parse_mime_content( $body_text, $ses_message );
        }

        return new Inbound_Message(
            fromEmail:   $from_email,
            fromName:    $from_name,
            toEmail:     $to_email,
            subject:     $subject,
            bodyText:    $body_text,
            bodyHtml:    $body_html,
            messageId:   $message_id,
            inReplyTo:   $in_reply_to,
            references:  $references,
            headers:     $headers,
            attachments: [],
        );
    }

    /**
     * Parse raw MIME content into an Inbound_Message.
     *
     * @param string     $raw_content The raw MIME email content.
     * @param array|null $ses_context Optional SES context for additional metadata.
     * @return Inbound_Message The parsed inbound message.
     */
    private function parse_mime_content( string $raw_content, ?array $ses_context = null ): Inbound_Message {
        $headers = [];
        $body_text = null;
        $body_html = null;
        $from_email = '';
        $from_name = null;
        $to_email = '';
        $subject = '';
        $message_id = null;
        $in_reply_to = null;
        $references = null;
        $attachments = [];

        // Split headers from body.
        $parts = preg_split( '/\r?\n\r?\n/', $raw_content, 2 );
        $header_section = $parts[0] ?? '';
        $body_section = $parts[1] ?? '';

        // Parse headers.
        $current_header = '';
        $current_value = '';
        foreach ( preg_split( '/\r?\n/', $header_section ) as $line ) {
            if ( preg_match( '/^\s+/', $line ) && ! empty( $current_header ) ) {
                // Continuation of previous header.
                $current_value .= ' ' . trim( $line );
                $headers[ $current_header ] = $current_value;
            } elseif ( preg_match( '/^([^:]+):\s*(.*)$/', $line, $matches ) ) {
                $current_header = $matches[1];
                $current_value = $matches[2];
                $headers[ $current_header ] = $current_value;
            }
        }

        // Extract key headers.
        foreach ( $headers as $name => $value ) {
            $lower = strtolower( $name );
            switch ( $lower ) {
                case 'from':
                    $from_email = $this->extract_email( $value );
                    $from_name = $this->extract_name( $value );
                    break;
                case 'to':
                    $to_email = $this->extract_email( $value );
                    break;
                case 'subject':
                    $subject = $value;
                    break;
                case 'message-id':
                    $message_id = $value;
                    break;
                case 'in-reply-to':
                    $in_reply_to = $value;
                    break;
                case 'references':
                    $references = $value;
                    break;
            }
        }

        // Check for multipart content.
        $content_type = $headers['Content-Type'] ?? ( $headers['content-type'] ?? '' );
        if ( preg_match( '/boundary=["\']?([^"\';\s]+)/i', $content_type, $boundary_match ) ) {
            $boundary = $boundary_match[1];
            $mime_parts = explode( '--' . $boundary, $body_section );

            foreach ( $mime_parts as $part ) {
                $part = trim( $part );
                if ( empty( $part ) || $part === '--' ) {
                    continue;
                }

                $part_split = preg_split( '/\r?\n\r?\n/', $part, 2 );
                $part_headers_raw = $part_split[0] ?? '';
                $part_body = $part_split[1] ?? '';

                $part_content_type = '';
                $part_disposition = '';
                $part_filename = '';
                $part_encoding = '';

                foreach ( preg_split( '/\r?\n/', $part_headers_raw ) as $pline ) {
                    if ( preg_match( '/^Content-Type:\s*(.+)/i', $pline, $m ) ) {
                        $part_content_type = trim( $m[1] );
                    }
                    if ( preg_match( '/^Content-Disposition:\s*(.+)/i', $pline, $m ) ) {
                        $part_disposition = trim( $m[1] );
                    }
                    if ( preg_match( '/^Content-Transfer-Encoding:\s*(.+)/i', $pline, $m ) ) {
                        $part_encoding = trim( $m[1] );
                    }
                }

                // Decode body based on transfer encoding.
                $decoded_body = $this->decode_part_body( $part_body, $part_encoding );

                // Check if this is an attachment.
                if ( preg_match( '/attachment/i', $part_disposition ) ) {
                    if ( preg_match( '/filename=["\']?([^"\';\s]+)/i', $part_disposition . ';' . $part_content_type, $fn_match ) ) {
                        $part_filename = $fn_match[1];
                    }
                    $attachments[] = [
                        'name'    => $part_filename ?: 'attachment',
                        'type'    => preg_replace( '/;.*$/', '', $part_content_type ),
                        'content' => $decoded_body,
                        'size'    => strlen( $decoded_body ),
                    ];
                    continue;
                }

                // Body parts.
                if ( stripos( $part_content_type, 'text/plain' ) !== false && $body_text === null ) {
                    $body_text = $decoded_body;
                } elseif ( stripos( $part_content_type, 'text/html' ) !== false && $body_html === null ) {
                    $body_html = $decoded_body;
                }
            }
        } else {
            // Simple non-multipart message.
            $encoding = $headers['Content-Transfer-Encoding'] ?? ( $headers['content-transfer-encoding'] ?? '' );
            $decoded = $this->decode_part_body( $body_section, $encoding );

            if ( stripos( $content_type, 'text/html' ) !== false ) {
                $body_html = $decoded;
            } else {
                $body_text = $decoded;
            }
        }

        return new Inbound_Message(
            fromEmail:   $from_email,
            fromName:    $from_name,
            toEmail:     $to_email,
            subject:     $subject,
            bodyText:    $body_text,
            bodyHtml:    $body_html,
            messageId:   $message_id,
            inReplyTo:   $in_reply_to,
            references:  $references,
            headers:     $headers,
            attachments: $attachments,
        );
    }

    /**
     * Decode a MIME part body based on its transfer encoding.
     *
     * @param string $body     The encoded body content.
     * @param string $encoding The Content-Transfer-Encoding value.
     * @return string The decoded body content.
     */
    private function decode_part_body( string $body, string $encoding ): string {
        $encoding = strtolower( trim( $encoding ) );
        switch ( $encoding ) {
            case 'base64':
                return base64_decode( $body ) ?: $body;
            case 'quoted-printable':
                return quoted_printable_decode( $body );
            default:
                return $body;
        }
    }

    /**
     * Check if a string looks like raw MIME content.
     *
     * @param string $content The content to check.
     * @return bool True if the content appears to be MIME formatted.
     */
    private function looks_like_mime( string $content ): bool {
        return (bool) preg_match( '/^(From:|MIME-Version:|Content-Type:|Received:)/im', $content );
    }

    /**
     * Extract an email address from a "Name <email>" formatted string.
     *
     * @param string $value The string to extract from.
     * @return string The extracted email address.
     */
    private function extract_email( string $value ): string {
        if ( preg_match( '/<([^>]+)>/', $value, $matches ) ) {
            return $matches[1];
        }
        return trim( $value );
    }

    /**
     * Extract a display name from a "Name <email>" formatted string.
     *
     * @param string $value The string to extract from.
     * @return string|null The extracted name, or null if not present.
     */
    private function extract_name( string $value ): ?string {
        if ( preg_match( '/^(.+?)\s*</', $value, $matches ) ) {
            return trim( $matches[1], ' "' );
        }
        return null;
    }
}
