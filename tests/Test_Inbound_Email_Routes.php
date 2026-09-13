<?php

/**
 * Inbound email, end to end.
 *
 * A correctly authenticated webhook from each provider, POSTed through the
 * public REST route, has to create a ticket, or add a reply when the email
 * belongs to an existing ticket.
 *
 * Replies are matched to tickets by the resolution chain in
 * escalated-developer-context/domain-model/email-threading.md. The first
 * match wins:
 *
 *   1. In-Reply-To holds a Message-ID we issued (<ticket-{id}@domain>).
 *   2. References holds one.
 *   3. The recipient is a signed reply+{id}.{hmac8}@domain address.
 *   4. The subject holds a [PREFIX-00001] ticket reference.
 *   5. In-Reply-To or References matches an earlier inbound email.
 *
 * Anything else opens a new ticket.
 */

use Escalated\Escalated;
use Escalated\Mail\Message_Id_Util;
use Escalated\Models\Reply;
use Escalated\Models\Setting;
use Escalated\Models\Ticket;
use Escalated\Services\TicketService;

class Test_Inbound_Email_Routes extends WP_UnitTestCase
{
    private const MAILGUN_KEY = 'mailgun-test-signing-key';

    private const POSTMARK_TOKEN = 'postmark-test-inbound-token';

    private const TOPIC_ARN = 'arn:aws:sns:us-east-1:123456789012:escalated-inbound';

    private const CERT_URL = 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-0123456789abcdef.pem';

    private const REPLY_SECRET = 'inbound-reply-test-secret';

    private const DOMAIN = 'support.example.org';

    private const SENDER = 'jane@customer.example';

    private static string $cert_pem = '';

    /** @var \OpenSSLAsymmetricKey|null */
    private static $private_key = null;

    private WP_REST_Server $server;

    public static function set_up_before_class(): void
    {
        parent::set_up_before_class();

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'sns.amazonaws.com'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        openssl_x509_export($cert, $pem);

        self::$cert_pem = $pem;
        self::$private_key = $key;
    }

    public function set_up(): void
    {
        parent::set_up();

        \Escalated\Activator::activate();

        Setting::set('inbound_email_enabled', '1');
        Setting::set('mailgun_webhook_signing_key', self::MAILGUN_KEY);
        Setting::set('postmark_inbound_token', self::POSTMARK_TOKEN);
        Setting::set('ses_topic_arn', self::TOPIC_ARN);
        update_option('escalated_email_domain', self::DOMAIN);
        update_option('escalated_email_inbound_secret', self::REPLY_SECRET);

        add_filter('pre_http_request', [$this, 'serve_signing_certificate'], 10, 3);

        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'serve_signing_certificate'], 10);

        global $wp_rest_server;
        $wp_rest_server = null;

        parent::tear_down();
    }

    public function serve_signing_certificate($preempt, $args, $url)
    {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);

        return [
            'headers' => [],
            'body' => str_ends_with($path, '.pem') ? self::$cert_pem : '<ConfirmSubscriptionResponse/>',
            'response' => ['code' => 200, 'message' => 'OK'],
            'cookies' => [],
            'filename' => null,
        ];
    }

    // =========================================================================
    // Each provider opens a ticket
    // =========================================================================

    public function test_mailgun_email_creates_a_ticket(): void
    {
        $before = $this->ticket_count();

        $response = $this->mailgun();

        $this->assert_ticket_opened_by_sender($response, $before);
    }

    public function test_postmark_email_creates_a_ticket(): void
    {
        $before = $this->ticket_count();

        $response = $this->postmark();

        $this->assert_ticket_opened_by_sender($response, $before);
    }

    public function test_ses_email_creates_a_ticket(): void
    {
        $before = $this->ticket_count();

        $response = $this->ses();

        $this->assert_ticket_opened_by_sender($response, $before);
    }

    public function test_ses_subscription_confirmation_creates_no_ticket(): void
    {
        $before = $this->ticket_count();
        $payload = $this->sns_sign([
            'Type' => 'SubscriptionConfirmation',
            'MessageId' => '165545c9-2a5c-472c-8df2-7ff2be2b3b1b',
            'Token' => '2336412f37fb687f5d51e6e241d09c805a5a57b30d712f794cc5f6a988666d92',
            'TopicArn' => self::TOPIC_ARN,
            'Message' => 'You have chosen to subscribe to the topic.',
            'SubscribeURL' => 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn='.rawurlencode(self::TOPIC_ARN).'&Token=2336412f',
            'Timestamp' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'SignatureVersion' => '1',
            'SigningCertURL' => self::CERT_URL,
        ]);

        $response = $this->sns_post($payload);

        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertSame($before, $this->ticket_count());
    }

    public function test_email_from_a_registered_user_opens_a_ticket_as_that_user(): void
    {
        $user_id = $this->factory->user->create(['user_email' => 'member@customer.example']);

        $response = $this->mailgun(['from' => 'Member <member@customer.example>']);

        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertSame($user_id, (int) $this->latest_ticket()->requester_id);
    }

    public function test_postmark_attachment_is_stored_under_its_file_name(): void
    {
        $response = $this->postmark([
            'Attachments' => [[
                'Name' => 'error-log.txt',
                'ContentType' => 'text/plain',
                'Content' => base64_encode("paper jam\n"),
                'ContentLength' => 10,
            ]],
        ]);

        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));

        global $wpdb;
        $table = Escalated::table('attachments');
        $attachment = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE attachable_type = 'ticket' AND attachable_id = %d",
            $this->latest_ticket()->id
        ));

        $this->assertNotNull($attachment, 'No attachment was stored.');
        $this->assertSame('error-log.txt', $attachment->original_filename);
        $this->assertSame('text/plain', $attachment->mime_type);
        $this->assertSame("paper jam\n", file_get_contents($attachment->path));
    }

    // =========================================================================
    // Replies find their ticket
    // =========================================================================

    public function test_reply_is_threaded_by_in_reply_to(): void
    {
        $ticket = $this->existing_ticket();

        $response = $this->mailgun([
            'subject' => 'Re: Printer is on fire',
            'In-Reply-To' => Message_Id_Util::build_message_id((int) $ticket->id, 7, self::DOMAIN),
        ]);

        $this->assert_reply_added($response, $ticket);
    }

    public function test_reply_is_threaded_by_references(): void
    {
        $ticket = $this->existing_ticket();

        $response = $this->mailgun([
            'subject' => 'Re: Printer is on fire',
            'References' => '<unrelated@elsewhere.example> '.Message_Id_Util::build_message_id((int) $ticket->id, null, self::DOMAIN),
        ]);

        $this->assert_reply_added($response, $ticket);
    }

    public function test_reply_is_threaded_by_signed_reply_to_address(): void
    {
        $ticket = $this->existing_ticket();

        $response = $this->mailgun([
            'subject' => 'Re: Printer is on fire',
            'recipient' => Message_Id_Util::build_reply_to((int) $ticket->id, self::REPLY_SECRET, self::DOMAIN),
        ]);

        $this->assert_reply_added($response, $ticket);
    }

    public function test_reply_to_address_with_a_bad_signature_opens_a_new_ticket(): void
    {
        $ticket = $this->existing_ticket();
        $before = $this->ticket_count();

        $response = $this->mailgun([
            'recipient' => 'reply+'.$ticket->id.'.deadbeef@'.self::DOMAIN,
        ]);

        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertSame($before + 1, $this->ticket_count());
        $this->assertSame(0, $this->reply_count($ticket));
    }

    public function test_reply_is_threaded_by_subject_reference(): void
    {
        $ticket = $this->existing_ticket();

        $response = $this->mailgun([
            'subject' => 'Re: ['.$ticket->reference.'] Printer is on fire',
        ]);

        $this->assert_reply_added($response, $ticket);
    }

    public function test_reply_is_threaded_by_an_earlier_inbound_message_id(): void
    {
        $first = $this->mailgun(['Message-Id' => '<first-message@mail.customer.example>']);
        $this->assertSame(200, $first->get_status(), wp_json_encode($first->get_data()));
        $ticket = $this->latest_ticket();

        $response = $this->mailgun([
            'subject' => 'Re: Printer is on fire',
            'Message-Id' => '<second-message@mail.customer.example>',
            'In-Reply-To' => '<first-message@mail.customer.example>',
        ]);

        $this->assert_reply_added($response, $ticket);
    }

    public function test_in_reply_to_outranks_a_subject_reference(): void
    {
        $replied_to = $this->existing_ticket();
        $mentioned = $this->existing_ticket();

        $response = $this->mailgun([
            'subject' => 'Re: ['.$mentioned->reference.'] Printer is on fire',
            'In-Reply-To' => Message_Id_Util::build_message_id((int) $replied_to->id, null, self::DOMAIN),
        ]);

        $this->assert_reply_added($response, $replied_to);
        $this->assertSame(0, $this->reply_count($mentioned));
    }

    public function test_redelivered_email_opens_only_one_ticket(): void
    {
        $before = $this->ticket_count();
        $fields = ['Message-Id' => '<redelivered@mail.customer.example>'];

        $first = $this->mailgun($fields);
        $second = $this->mailgun($fields);

        $this->assertSame(200, $first->get_status(), wp_json_encode($first->get_data()));
        $this->assertSame(200, $second->get_status(), wp_json_encode($second->get_data()));
        $this->assertSame($before + 1, $this->ticket_count());
    }

    // =========================================================================
    // Assertions
    // =========================================================================

    private function assert_ticket_opened_by_sender(WP_REST_Response $response, int $tickets_before): void
    {
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertSame($tickets_before + 1, $this->ticket_count(), 'No ticket was created.');

        $ticket = $this->latest_ticket();
        $this->assertSame('Printer is on fire', $ticket->subject);
        $this->assertSame('email', $ticket->channel);
        $this->assertSame(self::SENDER, $ticket->guest_email);
        $this->assertStringContainsString('It is still on fire.', $ticket->description);
        $this->assertSame((int) $ticket->id, (int) $response->get_data()['ticket_id']);
    }

    private function assert_reply_added(WP_REST_Response $response, object $ticket): void
    {
        $tickets = $this->ticket_count();

        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertSame(1, $this->reply_count($ticket), 'The email was not added to the ticket as a reply.');
        $this->assertSame($tickets, $this->ticket_count());
        $this->assertSame((int) $ticket->id, (int) $response->get_data()['ticket_id']);

        $reply = Escalated::db()->get_row(Escalated::db()->prepare(
            'SELECT * FROM '.Reply::table().' WHERE ticket_id = %d',
            $ticket->id
        ));
        $this->assertStringContainsString('It is still on fire.', $reply->body);
        $this->assertSame(self::SENDER, json_decode($reply->metadata, true)['guest_email'] ?? null);
    }

    // =========================================================================
    // Providers
    // =========================================================================

    private function mailgun(array $fields = []): WP_REST_Response
    {
        $timestamp = (string) time();
        $token = wp_generate_password(50, false);

        $request = new WP_REST_Request('POST', '/escalated/v1/inbound/mailgun');
        $request->set_body_params(array_merge([
            'from' => 'Jane Customer <'.self::SENDER.'>',
            'recipient' => 'help@'.self::DOMAIN,
            'subject' => 'Printer is on fire',
            'body-plain' => 'It is still on fire.',
            'Message-Id' => '<'.wp_generate_password(24, false).'@mail.customer.example>',
            'timestamp' => $timestamp,
            'token' => $token,
            'signature' => hash_hmac('sha256', $timestamp.$token, self::MAILGUN_KEY),
        ], $fields));

        return $this->server->dispatch($request);
    }

    private function postmark(array $fields = []): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/escalated/v1/inbound/postmark');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('X-Postmark-Token', self::POSTMARK_TOKEN);
        $request->set_body(wp_json_encode(array_merge([
            'From' => self::SENDER,
            'FromFull' => ['Email' => self::SENDER, 'Name' => 'Jane Customer'],
            'To' => 'help@'.self::DOMAIN,
            'ToFull' => [['Email' => 'help@'.self::DOMAIN, 'Name' => '']],
            'Subject' => 'Printer is on fire',
            'TextBody' => 'It is still on fire.',
            'HtmlBody' => '<p>It is still on fire.</p>',
            'MessageID' => wp_generate_uuid4(),
            'Headers' => [['Name' => 'Message-ID', 'Value' => '<postmark-1@mail.customer.example>']],
            'Attachments' => [],
        ], $fields)));

        return $this->server->dispatch($request);
    }

    private function ses(): WP_REST_Response
    {
        $mime = implode("\r\n", [
            'From: Jane Customer <'.self::SENDER.'>',
            'To: help@'.self::DOMAIN,
            'Subject: Printer is on fire',
            'Message-ID: <ses-1@mail.customer.example>',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            '',
            'It is still on fire.',
            '',
        ]);

        $message = [
            'notificationType' => 'Received',
            'mail' => [
                'source' => self::SENDER,
                'destination' => ['help@'.self::DOMAIN],
                'messageId' => 'o3vrnil0e2ic28trm7dfhrc2v0clambfsvlkqo81',
                'commonHeaders' => [
                    'from' => ['Jane Customer <'.self::SENDER.'>'],
                    'to' => ['help@'.self::DOMAIN],
                    'subject' => 'Printer is on fire',
                    'messageId' => '<ses-1@mail.customer.example>',
                ],
            ],
            'receipt' => ['action' => ['type' => 'SNS', 'encoding' => 'UTF8', 'topicArn' => self::TOPIC_ARN]],
            'content' => $mime,
        ];

        return $this->sns_post($this->sns_sign([
            'Type' => 'Notification',
            'MessageId' => wp_generate_uuid4(),
            'TopicArn' => self::TOPIC_ARN,
            'Subject' => 'Amazon SES Email Receipt Notification',
            'Message' => wp_json_encode($message),
            'Timestamp' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'SignatureVersion' => '1',
            'SigningCertURL' => self::CERT_URL,
        ]));
    }

    /**
     * Sign an SNS message (SignatureVersion 1) with the test key.
     */
    private function sns_sign(array $message): array
    {
        $fields = $message['Type'] === 'Notification'
            ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
            : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];

        $string_to_sign = '';
        foreach ($fields as $field) {
            if (isset($message[$field])) {
                $string_to_sign .= $field."\n".$message[$field]."\n";
            }
        }

        openssl_sign($string_to_sign, $signature, self::$private_key, OPENSSL_ALGO_SHA1);
        $message['Signature'] = base64_encode($signature);

        return $message;
    }

    private function sns_post(array $payload): WP_REST_Response
    {
        $request = new WP_REST_Request('POST', '/escalated/v1/inbound/ses');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('x-amz-sns-message-type', $payload['Type']);
        $request->set_body(wp_json_encode($payload));

        return $this->server->dispatch($request);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function existing_ticket(): object
    {
        $requester_id = $this->factory->user->create();

        return (new TicketService)->create($requester_id, [
            'subject' => 'Printer is on fire',
            'description' => 'Smoke everywhere.',
            'channel' => 'web',
        ]);
    }

    private function ticket_count(): int
    {
        return (int) Escalated::db()->get_var('SELECT COUNT(*) FROM '.Ticket::table());
    }

    private function latest_ticket(): object
    {
        return Escalated::db()->get_row('SELECT * FROM '.Ticket::table().' ORDER BY id DESC LIMIT 1');
    }

    private function reply_count(object $ticket): int
    {
        return (int) Escalated::db()->get_var(Escalated::db()->prepare(
            'SELECT COUNT(*) FROM '.Reply::table().' WHERE ticket_id = %d',
            $ticket->id
        ));
    }
}
