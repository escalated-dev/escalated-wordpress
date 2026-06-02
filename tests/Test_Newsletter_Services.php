<?php

use Escalated\Activator;
use Escalated\Models\Contact;
use Escalated\Models\Newsletter\Newsletter;
use Escalated\Models\Newsletter\NewsletterDelivery;
use Escalated\Models\Newsletter\NewsletterList;
use Escalated\Models\Newsletter\NewsletterListMember;
use Escalated\Services\Newsletter\BounceSuppressionStore;
use Escalated\Services\Newsletter\ContactSegmentResolver;
use Escalated\Services\Newsletter\NewsletterConfig;
use Escalated\Services\Newsletter\NewsletterDispatcher;
use Escalated\Services\Newsletter\NewsletterPlanner;
use Escalated\Services\Newsletter\NewsletterRenderer;
use Escalated\Services\Newsletter\NewsletterTracker;

class Test_Newsletter_Services extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        Activator::activate();
        update_option(NewsletterConfig::OPTION_ENABLED, '1');
    }

    public function test_bounce_suppression_store(): void
    {
        $store = new BounceSuppressionStore;
        $this->assertSame(['a@example.com', 'b@example.com'], $store->filter_sendable(['a@example.com', 'b@example.com']));
        $store->mark_bounced('B@example.com');
        $this->assertTrue($store->is_bounced('b@example.com'));
        $this->assertSame(['a@example.com'], $store->filter_sendable(['a@example.com', 'b@example.com']));
    }

    public function test_segment_resolver_static_list(): void
    {
        $contact = Contact::find_or_create_by_email('seg@example.com', 'Seg');
        $list_id = NewsletterList::create([
            'name' => 'Static',
            'kind' => 'static',
            'filter_json' => null,
            'created_by' => 1,
        ]);
        global $wpdb;
        $wpdb->insert(NewsletterListMember::table(), [
            'list_id' => $list_id,
            'contact_id' => (int) $contact->id,
            'added_at' => current_time('mysql'),
            'added_by' => 1,
        ]);
        $list = NewsletterList::find($list_id);
        $resolver = new ContactSegmentResolver;
        $ids = $resolver->resolve_sendable($list);
        $this->assertContains((int) $contact->id, $ids);
    }

    public function test_planner_creates_deliveries(): void
    {
        $contact = Contact::find_or_create_by_email('plan@example.com', 'Plan');
        $list_id = NewsletterList::create([
            'name' => 'Plan List',
            'kind' => 'static',
            'filter_json' => null,
            'created_by' => 1,
        ]);
        global $wpdb;
        $wpdb->insert(NewsletterListMember::table(), [
            'list_id' => $list_id,
            'contact_id' => (int) $contact->id,
            'added_at' => current_time('mysql'),
            'added_by' => 1,
        ]);
        $nid = Newsletter::create([
            'subject' => 'Hello',
            'from_email' => 'sender@example.com',
            'target_list_id' => $list_id,
            'status' => 'draft',
        ]);
        $newsletter = Newsletter::find($nid);
        (new NewsletterPlanner(new ContactSegmentResolver, new BounceSuppressionStore))->plan($newsletter);
        $updated = Newsletter::find($nid);
        $this->assertSame('sending', $updated->status);
        $this->assertSame(1, (int) $updated->summary_total);
        $delivery = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM '.NewsletterDelivery::table().' WHERE newsletter_id = %d',
            $nid
        ));
        $this->assertNotNull($delivery);
        $this->assertSame('pending', $delivery->status);
    }

    public function test_tracker_record_open(): void
    {
        global $wpdb;
        $nid = Newsletter::create([
            'subject' => 'T',
            'from_email' => 's@example.com',
            'target_list_id' => 1,
            'status' => 'sending',
        ]);
        $token = 'tok'.wp_generate_password(32, false, false);
        $wpdb->insert(NewsletterDelivery::table(), [
            'newsletter_id' => $nid,
            'contact_id' => 1,
            'email_at_send' => 'r@example.com',
            'status' => 'sent',
            'tracking_token' => $token,
            'attempt_count' => 0,
            'is_test' => 0,
            'created_at' => current_time('mysql'),
        ]);
        (new NewsletterTracker(new BounceSuppressionStore))->record_open($token);
        $d = NewsletterDelivery::find_by_token($token);
        $this->assertNotNull($d->opened_at);
    }

    public function test_dispatcher_respects_rate_limit(): void
    {
        add_filter('escalated_newsletter_mail_configured', '__return_true');
        $calls = 0;
        add_filter('pre_wp_mail', function () use (&$calls) {
            $calls++;

            return true;
        });

        global $wpdb;
        $list_id = NewsletterList::create([
            'name' => 'Rate',
            'kind' => 'static',
            'filter_json' => null,
            'created_by' => 1,
        ]);
        $nid = Newsletter::create([
            'subject' => 'Rate',
            'from_email' => 's@example.com',
            'target_list_id' => $list_id,
            'status' => 'sending',
        ]);
        $now = current_time('mysql');
        for ($i = 0; $i < 3; $i++) {
            $wpdb->insert(NewsletterDelivery::table(), [
                'newsletter_id' => $nid,
                'contact_id' => 1,
                'email_at_send' => "u{$i}@example.com",
                'status' => 'pending',
                'tracking_token' => wp_generate_password(40, false, false),
                'attempt_count' => 0,
                'is_test' => 0,
                'created_at' => $now,
            ]);
        }

        \Escalated\Models\Setting::set('newsletter.rate_limit_per_minute', '1');
        \Escalated\Models\Setting::set('newsletter.batch_size', '10');

        $dispatcher = new NewsletterDispatcher(new NewsletterRenderer);
        $dispatcher->dispatch_batch();
        $this->assertLessThanOrEqual(1, $calls);
    }
}
