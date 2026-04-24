<?php

/**
 * Tests for Message_Id_Util — pure helpers for RFC 5322 Message-ID
 * threading + signed Reply-To.
 *
 * Mirrors the NestJS and Spring reference test suites. Pure functions,
 * no WP test harness required.
 */

use Escalated\Mail\Message_Id_Util;
use PHPUnit\Framework\TestCase;

class Test_Message_Id_Util extends TestCase
{
    private const DOMAIN = 'support.example.com';

    private const SECRET = 'test-secret-long-enough-for-hmac';

    public function test_build_message_id_initial_ticket(): void
    {
        $id = Message_Id_Util::build_message_id(42, null, self::DOMAIN);
        $this->assertEquals('<ticket-42@support.example.com>', $id);
    }

    public function test_build_message_id_reply_form(): void
    {
        $id = Message_Id_Util::build_message_id(42, 7, self::DOMAIN);
        $this->assertEquals('<ticket-42-reply-7@support.example.com>', $id);
    }

    public function test_parse_ticket_id_from_built_message_id(): void
    {
        $initial = Message_Id_Util::build_message_id(42, null, self::DOMAIN);
        $reply = Message_Id_Util::build_message_id(42, 7, self::DOMAIN);

        $this->assertEquals(42, Message_Id_Util::parse_ticket_id_from_message_id($initial));
        $this->assertEquals(42, Message_Id_Util::parse_ticket_id_from_message_id($reply));
    }

    public function test_parse_ticket_id_accepts_value_without_brackets(): void
    {
        $this->assertEquals(99, Message_Id_Util::parse_ticket_id_from_message_id('ticket-99@example.com'));
    }

    public function test_parse_ticket_id_returns_null_for_unrelated_input(): void
    {
        $this->assertNull(Message_Id_Util::parse_ticket_id_from_message_id(null));
        $this->assertNull(Message_Id_Util::parse_ticket_id_from_message_id(''));
        $this->assertNull(Message_Id_Util::parse_ticket_id_from_message_id('<random@mail.com>'));
        $this->assertNull(Message_Id_Util::parse_ticket_id_from_message_id('ticket-abc@example.com'));
    }

    public function test_build_reply_to_is_stable(): void
    {
        $first = Message_Id_Util::build_reply_to(42, self::SECRET, self::DOMAIN);
        $again = Message_Id_Util::build_reply_to(42, self::SECRET, self::DOMAIN);

        $this->assertEquals($first, $again);
        $this->assertMatchesRegularExpression(
            '/^reply\+42\.[a-f0-9]{8}@support\.example\.com$/',
            $first
        );
    }

    public function test_build_reply_to_different_tickets_produce_different_signatures(): void
    {
        $a = Message_Id_Util::build_reply_to(42, self::SECRET, self::DOMAIN);
        $b = Message_Id_Util::build_reply_to(43, self::SECRET, self::DOMAIN);
        $a_local = substr($a, 0, strpos($a, '@'));
        $b_local = substr($b, 0, strpos($b, '@'));
        $this->assertNotEquals($a_local, $b_local);
    }

    public function test_verify_reply_to_round_trips(): void
    {
        $address = Message_Id_Util::build_reply_to(42, self::SECRET, self::DOMAIN);
        $this->assertEquals(42, Message_Id_Util::verify_reply_to($address, self::SECRET));
    }

    public function test_verify_reply_to_accepts_local_part_only(): void
    {
        $address = Message_Id_Util::build_reply_to(42, self::SECRET, self::DOMAIN);
        $local = substr($address, 0, strpos($address, '@'));
        $this->assertEquals(42, Message_Id_Util::verify_reply_to($local, self::SECRET));
    }

    public function test_verify_reply_to_rejects_tampered_signature(): void
    {
        $address = Message_Id_Util::build_reply_to(42, self::SECRET, self::DOMAIN);
        $at = strpos($address, '@');
        $local = substr($address, 0, $at);
        $last = $local[strlen($local) - 1];
        $tampered = substr($local, 0, -1).($last === '0' ? '1' : '0').substr($address, $at);

        $this->assertNull(Message_Id_Util::verify_reply_to($tampered, self::SECRET));
    }

    public function test_verify_reply_to_rejects_wrong_secret(): void
    {
        $address = Message_Id_Util::build_reply_to(42, self::SECRET, self::DOMAIN);
        $this->assertNull(Message_Id_Util::verify_reply_to($address, 'different-secret'));
    }

    public function test_verify_reply_to_rejects_malformed_input(): void
    {
        $this->assertNull(Message_Id_Util::verify_reply_to(null, self::SECRET));
        $this->assertNull(Message_Id_Util::verify_reply_to('', self::SECRET));
        $this->assertNull(Message_Id_Util::verify_reply_to('alice@example.com', self::SECRET));
        $this->assertNull(Message_Id_Util::verify_reply_to('reply@example.com', self::SECRET));
        $this->assertNull(Message_Id_Util::verify_reply_to('reply+abc.deadbeef@example.com', self::SECRET));
    }

    public function test_verify_reply_to_is_case_insensitive_on_hex(): void
    {
        $address = Message_Id_Util::build_reply_to(42, self::SECRET, self::DOMAIN);
        $upper = strtoupper($address);
        $this->assertEquals(42, Message_Id_Util::verify_reply_to($upper, self::SECRET));
    }
}
