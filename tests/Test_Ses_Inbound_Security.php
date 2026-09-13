<?php

/**
 * Security tests for the SES (Amazon SNS) inbound webhook.
 *
 * POST /escalated/v1/inbound/ses is public: the SNS message signature is its
 * only authentication. Both URLs the adapter fetches, the signing certificate
 * and the SubscribeURL, come out of that unauthenticated request body, so both
 * have to be pinned to Amazon SNS before anything is fetched. Otherwise anyone
 * can host a certificate of their own, sign with its key, and make the site
 * request any URL they like.
 *
 * Outbound HTTP is intercepted with pre_http_request, which records every URL
 * the adapter asks for and serves a test signing certificate.
 */

use Escalated\Mail\Ses_Adapter;
use Escalated\Models\Setting;

class Test_Ses_Inbound_Security extends WP_UnitTestCase
{
    private const TOPIC_ARN = 'arn:aws:sns:us-east-1:123456789012:escalated-inbound';

    private const CERT_URL = 'https://sns.us-east-1.amazonaws.com/SimpleNotificationService-0123456789abcdef.pem';

    private static string $cert_pem = '';

    /** @var \OpenSSLAsymmetricKey|null */
    private static $private_key = null;

    private WP_REST_Server $server;

    /** @var array<int, array{url: string, args: array}> */
    private array $fetched = [];

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
        Setting::set('ses_topic_arn', self::TOPIC_ARN);

        $this->fetched = [];
        add_filter('pre_http_request', [$this, 'intercept_http'], 10, 3);

        global $wp_rest_server;
        $this->server = $wp_rest_server = new WP_REST_Server;
        do_action('rest_api_init');
    }

    public function tear_down(): void
    {
        remove_filter('pre_http_request', [$this, 'intercept_http'], 10);

        global $wp_rest_server;
        $wp_rest_server = null;

        parent::tear_down();
    }

    /**
     * Record the fetch and answer it without touching the network.
     */
    public function intercept_http($preempt, $args, $url)
    {
        $this->fetched[] = ['url' => $url, 'args' => $args];

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
    // Signing certificate location
    // =========================================================================

    public function test_s3_hosted_signing_certificate_is_rejected_before_any_fetch(): void
    {
        // *.s3.amazonaws.com ends in .amazonaws.com, but anyone can put a
        // certificate in a bucket and sign with the matching key.
        $payload = $this->subscription_confirmation([
            'SigningCertURL' => 'https://attacker-bucket.s3.amazonaws.com/SimpleNotificationService-evil.pem',
            'SubscribeURL' => 'http://169.254.169.254/latest/meta-data/iam/security-credentials/',
        ]);

        $response = $this->post($payload);

        $this->assertSame(403, $response->get_status());
        $this->assertSame([], $this->fetched_urls());
    }

    /**
     * @dataProvider untrusted_signing_cert_urls
     */
    public function test_untrusted_signing_cert_url_is_rejected(string $cert_url): void
    {
        $response = $this->post($this->subscription_confirmation(['SigningCertURL' => $cert_url]));

        $this->assertSame(403, $response->get_status());
        $this->assertSame([], $this->fetched_urls());
    }

    public static function untrusted_signing_cert_urls(): array
    {
        return [
            'S3 bucket under amazonaws.com' => ['https://attacker-bucket.s3.amazonaws.com/SimpleNotificationService-x.pem'],
            'plain http' => ['http://sns.us-east-1.amazonaws.com/SimpleNotificationService-x.pem'],
            'look-alike suffix' => ['https://sns.us-east-1.amazonaws.com.attacker.example/SimpleNotificationService-x.pem'],
            'label before sns' => ['https://evilsns.us-east-1.amazonaws.com/SimpleNotificationService-x.pem'],
            'user-info' => ['https://attacker@sns.us-east-1.amazonaws.com/SimpleNotificationService-x.pem'],
            'host in user-info' => ['https://sns.us-east-1.amazonaws.com@attacker.example/SimpleNotificationService-x.pem'],
            'explicit port' => ['https://sns.us-east-1.amazonaws.com:8443/SimpleNotificationService-x.pem'],
            'not a .pem' => ['https://sns.us-east-1.amazonaws.com/SimpleNotificationService-x.txt'],
            'internal host' => ['https://169.254.169.254/latest/meta-data.pem'],
        ];
    }

    // =========================================================================
    // SubscribeURL
    // =========================================================================

    /**
     * @dataProvider untrusted_subscribe_urls
     */
    public function test_untrusted_subscribe_url_is_never_fetched(string $subscribe_url): void
    {
        $response = $this->post($this->subscription_confirmation(['SubscribeURL' => $subscribe_url]));

        $this->assertSame(403, $response->get_status());
        $this->assertNotContains($subscribe_url, $this->fetched_urls());
    }

    public static function untrusted_subscribe_urls(): array
    {
        $topic = rawurlencode(self::TOPIC_ARN);

        return [
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/iam/security-credentials/'],
            'loopback' => ['http://127.0.0.1:8080/wp-admin/'],
            'other host' => ['https://attacker.example/?Action=ConfirmSubscription&TopicArn='.$topic],
            'plain http to SNS' => ['http://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn='.$topic],
            'S3 bucket under amazonaws.com' => ['https://attacker-bucket.s3.amazonaws.com/?Action=ConfirmSubscription&TopicArn='.$topic],
            'SNS host, other action' => ['https://sns.us-east-1.amazonaws.com/?Action=Unsubscribe&SubscriptionArn=x'],
            'SNS host, other topic' => ['https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn=arn%3Aaws%3Asns%3Aus-east-1%3A999999999999%3Aother&Token=x'],
        ];
    }

    public function test_unsigned_type_header_cannot_turn_a_notification_into_a_confirmation(): void
    {
        // A Notification's signature does not cover SubscribeURL, so a
        // SubscribeURL bolted onto one was never signed by anybody.
        $payload = $this->sign([
            'Type' => 'Notification',
            'MessageId' => '22b80b92-fdea-4c2c-8f9d-bdfb0c7bf324',
            'TopicArn' => self::TOPIC_ARN,
            'Subject' => 'Amazon SES Email Receipt Notification',
            'Message' => '{}',
            'Timestamp' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'SignatureVersion' => '1',
            'SigningCertURL' => self::CERT_URL,
        ]);
        $payload['SubscribeURL'] = $this->confirm_url(self::TOPIC_ARN);

        $request = $this->make_request($payload);
        $request->set_header('x-amz-sns-message-type', 'SubscriptionConfirmation');

        $response = $this->server->dispatch($request);

        $this->assertSame(403, $response->get_status());
        $this->assertNotContains($payload['SubscribeURL'], $this->fetched_urls());
    }

    // =========================================================================
    // Topic
    // =========================================================================

    public function test_request_is_rejected_when_no_topic_arn_is_configured(): void
    {
        Setting::delete('ses_topic_arn');

        $response = $this->post($this->subscription_confirmation());

        $this->assertSame(403, $response->get_status());
        $this->assertSame([], $this->fetched_urls());
    }

    public function test_request_for_another_topic_is_rejected(): void
    {
        $response = $this->post($this->subscription_confirmation([
            'TopicArn' => 'arn:aws:sns:us-east-1:999999999999:someone-else',
        ]));

        $this->assertSame(403, $response->get_status());
        $this->assertSame([], $this->fetched_urls());
    }

    // =========================================================================
    // Genuine SNS traffic still works
    // =========================================================================

    public function test_genuine_subscription_confirmation_is_verified_and_confirmed_safely(): void
    {
        $payload = $this->subscription_confirmation();

        $verified = (new Ses_Adapter)->verify_request($this->make_request($payload));

        $this->assertTrue($verified);
        $this->assertSame([self::CERT_URL, $payload['SubscribeURL']], $this->fetched_urls());
        foreach ($this->fetched as $fetch) {
            // wp_safe_remote_get refuses private and loopback hosts, and with
            // redirects off nothing can move the fetch off the checked host.
            $this->assertTrue($fetch['args']['reject_unsafe_urls'], $fetch['url']);
            $this->assertSame(0, $fetch['args']['redirection'], $fetch['url']);
        }
    }

    public function test_genuine_notification_fetches_only_the_certificate(): void
    {
        $payload = $this->sign([
            'Type' => 'Notification',
            'MessageId' => '22b80b92-fdea-4c2c-8f9d-bdfb0c7bf324',
            'TopicArn' => self::TOPIC_ARN,
            'Subject' => 'Amazon SES Email Receipt Notification',
            'Message' => '{}',
            'Timestamp' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'SignatureVersion' => '1',
            'SigningCertURL' => self::CERT_URL,
        ]);

        $this->assertTrue((new Ses_Adapter)->verify_request($this->make_request($payload)));
        $this->assertSame([self::CERT_URL], $this->fetched_urls());
    }

    public function test_china_partition_sns_host_is_accepted(): void
    {
        $topic = 'arn:aws-cn:sns:cn-north-1:123456789012:escalated-inbound';
        Setting::set('ses_topic_arn', $topic);

        $payload = $this->subscription_confirmation([
            'TopicArn' => $topic,
            'SigningCertURL' => 'https://sns.cn-north-1.amazonaws.com.cn/SimpleNotificationService-0123456789abcdef.pem',
            'SubscribeURL' => 'https://sns.cn-north-1.amazonaws.com.cn/?Action=ConfirmSubscription&TopicArn='.rawurlencode($topic).'&Token=abc',
        ]);

        $this->assertTrue((new Ses_Adapter)->verify_request($this->make_request($payload)));
        $this->assertCount(2, $this->fetched);
    }

    // =========================================================================
    // Signature versions
    // =========================================================================

    public function test_signature_version_2_notification_is_verified(): void
    {
        $payload = $this->notification(['SignatureVersion' => '2']);

        $this->assertTrue((new Ses_Adapter)->verify_request($this->make_request($payload)));
        $this->assertSame([self::CERT_URL], $this->fetched_urls());
    }

    public function test_signature_version_2_subscription_confirmation_is_confirmed(): void
    {
        $payload = $this->subscription_confirmation(['SignatureVersion' => '2']);

        $this->assertTrue((new Ses_Adapter)->verify_request($this->make_request($payload)));
        $this->assertSame([self::CERT_URL, $payload['SubscribeURL']], $this->fetched_urls());
    }

    /**
     * A message has to be signed with the algorithm its SignatureVersion names.
     *
     * @dataProvider mismatched_signature_algorithms
     */
    public function test_signature_made_with_another_versions_algorithm_is_rejected(string $version, int $algorithm): void
    {
        $payload = $this->notification(['SignatureVersion' => $version], $algorithm);

        $this->assertFalse((new Ses_Adapter)->verify_request($this->make_request($payload)));
    }

    public static function mismatched_signature_algorithms(): array
    {
        return [
            'version 1 signed with SHA256' => ['1', OPENSSL_ALGO_SHA256],
            'version 2 signed with SHA1' => ['2', OPENSSL_ALGO_SHA1],
        ];
    }

    /**
     * @dataProvider unsupported_signature_versions
     */
    public function test_unsupported_signature_version_is_rejected_before_any_fetch(?string $version): void
    {
        // Signed with SHA1, so only the version check can reject it.
        $payload = $this->notification(['SignatureVersion' => $version], OPENSSL_ALGO_SHA1);

        $response = $this->post($payload);

        $this->assertSame(403, $response->get_status());
        $this->assertSame([], $this->fetched_urls());
    }

    public static function unsupported_signature_versions(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'version 0' => ['0'],
            'version 3' => ['3'],
            'algorithm name' => ['SHA256'],
        ];
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * A signed Notification. An override of null leaves that field out.
     */
    private function notification(array $overrides = [], ?int $algorithm = null): array
    {
        $message = array_merge([
            'Type' => 'Notification',
            'MessageId' => '22b80b92-fdea-4c2c-8f9d-bdfb0c7bf324',
            'TopicArn' => self::TOPIC_ARN,
            'Subject' => 'Amazon SES Email Receipt Notification',
            'Message' => '{}',
            'Timestamp' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'SignatureVersion' => '1',
            'SigningCertURL' => self::CERT_URL,
        ], $overrides);

        return $this->sign(array_filter($message, fn ($value) => $value !== null), $algorithm);
    }

    private function confirm_url(string $topic_arn): string
    {
        return 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&TopicArn='
            .rawurlencode($topic_arn)
            .'&Token=2336412f37fb687f5d51e6e241d09c805a5a57b30d712f794cc5f6a988666d92';
    }

    private function subscription_confirmation(array $overrides = []): array
    {
        return $this->sign(array_merge([
            'Type' => 'SubscriptionConfirmation',
            'MessageId' => '165545c9-2a5c-472c-8df2-7ff2be2b3b1b',
            'Token' => '2336412f37fb687f5d51e6e241d09c805a5a57b30d712f794cc5f6a988666d92',
            'TopicArn' => self::TOPIC_ARN,
            'Message' => 'You have chosen to subscribe to the topic. To confirm the subscription, visit the SubscribeURL included in this message.',
            'SubscribeURL' => $this->confirm_url(self::TOPIC_ARN),
            'Timestamp' => gmdate('Y-m-d\TH:i:s.000\Z'),
            'SignatureVersion' => '1',
            'SigningCertURL' => self::CERT_URL,
        ], $overrides));
    }

    /**
     * Sign the way SNS does with the test key: SHA1 for SignatureVersion 1,
     * SHA256 for SignatureVersion 2, or the algorithm a test asks for. Anyone
     * can do the same with a key of their own, which is why the certificate's
     * location is the thing that has to be trusted.
     */
    private function sign(array $message, ?int $algorithm = null): array
    {
        $fields = ($message['Type'] ?? '') === 'Notification'
            ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
            : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];

        $string_to_sign = '';
        foreach ($fields as $field) {
            if (isset($message[$field])) {
                $string_to_sign .= $field."\n".$message[$field]."\n";
            }
        }

        $algorithm ??= ($message['SignatureVersion'] ?? null) === '2' ? OPENSSL_ALGO_SHA256 : OPENSSL_ALGO_SHA1;

        openssl_sign($string_to_sign, $signature, self::$private_key, $algorithm);
        $message['Signature'] = base64_encode($signature);

        return $message;
    }

    private function make_request(array $payload): WP_REST_Request
    {
        $request = new WP_REST_Request('POST', '/escalated/v1/inbound/ses');
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('x-amz-sns-message-type', $payload['Type'] ?? 'SubscriptionConfirmation');
        $request->set_body(wp_json_encode($payload));

        return $request;
    }

    private function post(array $payload): WP_REST_Response
    {
        return $this->server->dispatch($this->make_request($payload));
    }

    /**
     * @return array<int, string>
     */
    private function fetched_urls(): array
    {
        return array_column($this->fetched, 'url');
    }
}
