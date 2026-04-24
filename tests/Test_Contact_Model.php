<?php

/**
 * Tests for the Contact model (Pattern B public-ticket dedupe).
 *
 * Pure-function tests for normalize_email / decide_action; the
 * find_or_create_by_email flow is exercised via a live wpdb
 * (the test suite's SQLite/mysql harness).
 */

use Escalated\Models\Contact;

class Test_Contact_Model extends WP_UnitTestCase
{
    // ---------------------------------------------------------------------
    // normalize_email
    // ---------------------------------------------------------------------

    public function test_normalize_email_lowercases()
    {
        $this->assertEquals('alice@example.com', Contact::normalize_email('ALICE@Example.COM'));
    }

    public function test_normalize_email_trims_whitespace()
    {
        $this->assertEquals('alice@example.com', Contact::normalize_email('  alice@example.com  '));
    }

    public function test_normalize_email_handles_null_and_non_string()
    {
        $this->assertEquals('', Contact::normalize_email(null));
        $this->assertEquals('', Contact::normalize_email(123));
    }

    // ---------------------------------------------------------------------
    // decide_action
    // ---------------------------------------------------------------------

    public function test_decide_action_create_when_no_existing()
    {
        $this->assertEquals('create', Contact::decide_action(null, 'Alice'));
    }

    public function test_decide_action_return_existing_when_existing_has_name()
    {
        $existing = (object) ['name' => 'Alice'];
        $this->assertEquals('return-existing', Contact::decide_action($existing, 'Different'));
    }

    public function test_decide_action_update_name_when_existing_name_is_blank()
    {
        $existing = (object) ['name' => null];
        $this->assertEquals('update-name', Contact::decide_action($existing, 'Alice'));

        $existing->name = '';
        $this->assertEquals('update-name', Contact::decide_action($existing, 'Alice'));
    }

    public function test_decide_action_return_existing_when_no_incoming_name()
    {
        $existing = (object) ['name' => null];
        $this->assertEquals('return-existing', Contact::decide_action($existing, null));
        $this->assertEquals('return-existing', Contact::decide_action($existing, ''));
    }
}
