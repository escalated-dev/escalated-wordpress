<?php

use Jeremykenedy\Escalated\Services\MentionService;
use PHPUnit\Framework\TestCase;

class Test_Mention_Service extends TestCase
{
    public function test_single_mention()
    {
        $this->assertEquals(['john'], MentionService::extractMentions('Hello @john review'));
    }

    public function test_multiple_mentions()
    {
        $result = MentionService::extractMentions('@alice and @bob');
        $this->assertContains('alice', $result);
        $this->assertContains('bob', $result);
    }

    public function test_dotted_username()
    {
        $this->assertEquals(['john.doe'], MentionService::extractMentions('cc @john.doe'));
    }

    public function test_deduplicates()
    {
        $this->assertCount(1, MentionService::extractMentions('@alice @alice'));
    }

    public function test_empty()
    {
        $this->assertEmpty(MentionService::extractMentions(''));
    }

    public function test_null()
    {
        $this->assertEmpty(MentionService::extractMentions(null));
    }

    public function test_no_mentions()
    {
        $this->assertEmpty(MentionService::extractMentions('No mentions'));
    }

    public function test_username_from_email()
    {
        $this->assertEquals('john', MentionService::extractUsernameFromEmail('john@example.com'));
    }
}
