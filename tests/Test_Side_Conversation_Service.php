<?php

/**
 * Tests for side conversations.
 *
 * Pure-function test for valid_channel; the create / reply / close flow is
 * exercised via a live wpdb (the test suite's mysql harness).
 */

use Escalated\Models\SideConversation;
use Escalated\Models\SideConversationReply;
use Escalated\Services\SideConversationService;

class Test_Side_Conversation_Service extends WP_UnitTestCase
{
    // ---------------------------------------------------------------------
    // valid_channel (pure)
    // ---------------------------------------------------------------------

    public function test_valid_channel()
    {
        $this->assertTrue(SideConversation::valid_channel('internal'));
        $this->assertTrue(SideConversation::valid_channel('email'));
        $this->assertFalse(SideConversation::valid_channel('sms'));
        $this->assertFalse(SideConversation::valid_channel(''));
    }

    // ---------------------------------------------------------------------
    // Service flow (live wpdb)
    // ---------------------------------------------------------------------

    public function test_create_opens_conversation_with_first_reply()
    {
        $service = new SideConversationService;

        $id = $service->create(555, 'Need vendor input', 'internal', 'Can you advise?', 7);
        $this->assertNotFalse($id);

        $conversation = SideConversation::find($id);
        $this->assertEquals('open', $conversation->status);
        $this->assertEquals('Need vendor input', $conversation->subject);

        $replies = SideConversationReply::for_conversation($id);
        $this->assertCount(1, $replies);
        $this->assertEquals('Can you advise?', $replies[0]->body);
    }

    public function test_create_rejects_invalid_input()
    {
        $service = new SideConversationService;

        $this->assertFalse($service->create(555, '', 'internal', 'body'));
        $this->assertFalse($service->create(555, 'Subject', 'sms', 'body'));
        $this->assertFalse($service->create(555, 'Subject', 'internal', '   '));
    }

    public function test_add_reply_and_close()
    {
        $service = new SideConversationService;

        $id = $service->create(556, 'Thread', 'email', 'First', 7);
        $this->assertNotFalse($service->add_reply($id, 'Second', 9));
        $this->assertFalse($service->add_reply($id, '   '));

        $replies = SideConversationReply::for_conversation($id);
        $this->assertCount(2, $replies);

        $service->close($id);
        $this->assertEquals('closed', SideConversation::find($id)->status);
    }

    public function test_for_ticket_attaches_replies_newest_first()
    {
        $service = new SideConversationService;

        $service->create(557, 'Older', 'internal', 'a', 7);
        $newer = $service->create(557, 'Newer', 'internal', 'b', 7);
        $service->add_reply($newer, 'b2', 7);

        $threads = $service->for_ticket(557);
        $this->assertCount(2, $threads);
        $this->assertEquals('Newer', $threads[0]->subject);
        $this->assertCount(2, $threads[0]->replies);
    }
}
