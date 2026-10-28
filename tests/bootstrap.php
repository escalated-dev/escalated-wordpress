<?php

/**
 * PHPUnit bootstrap file for Escalated plugin tests.
 */
$_tests_dir = getenv('WP_TESTS_DIR');
if (! $_tests_dir) {
    $_tests_dir = rtrim(sys_get_temp_dir(), '/\\').'/wordpress-tests-lib';
}

if (! file_exists($_tests_dir.'/includes/functions.php')) {
    echo "Could not find $_tests_dir/includes/functions.php\n";
    exit(1);
}

require_once $_tests_dir.'/includes/functions.php';

tests_add_filter('muplugins_loaded', function () {
    require dirname(__DIR__).'/escalated.php';
});

require $_tests_dir.'/includes/bootstrap.php';

// Activate plugin tables, roles, and defaults once before tests run.
\Escalated\Activator::activate();

/**
 * Request a guest verification code through the real service and read it
 * from the email, the way a guest would. Code budgets are lifted for the
 * call so fixtures can create many tickets for one address.
 *
 * @return array{verification_id: string, verification_code: string}
 */
/**
 * The numeric id of the chat a widget chat token opens. `POST chat/start`
 * returns only the token (as `id` and `session_id`, like escalated-laravel).
 */
function escalated_test_chat_id(array $chat): int
{
    $session = \Escalated\Models\ChatSession::find_by_visitor_token_hash(hash('sha256', (string) $chat['id']));
    if (! $session) {
        throw new RuntimeException('No chat session for that token.');
    }

    return (int) $session->id;
}

function escalated_test_guest_proof(string $email, string $purpose = 'ticket'): array
{
    $code = null;
    $capture = static function ($return, $atts) use (&$code) {
        if (preg_match('/\b(\d{8})\b/', (string) $atts['message'], $m)) {
            $code = $m[1];
        }

        return true;
    };
    $limits = static fn () => ['per_client' => 100000, 'per_mailbox' => 100000];

    add_filter('pre_wp_mail', $capture, 10, 2);
    add_filter('escalated_guest_verification_limits', $limits);
    try {
        $result = (new \Escalated\Services\GuestEmailVerification)->request($email, $purpose);
    } finally {
        remove_filter('pre_wp_mail', $capture, 10);
        remove_filter('escalated_guest_verification_limits', $limits);
    }

    return ['verification_id' => (string) ($result['id'] ?? ''), 'verification_code' => (string) $code];
}
