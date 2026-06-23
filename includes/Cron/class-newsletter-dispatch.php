<?php

namespace Escalated\Cron;

use Escalated\Models\Newsletter\Newsletter;
use Escalated\Services\Newsletter\BounceSuppressionStore;
use Escalated\Services\Newsletter\ContactSegmentResolver;
use Escalated\Services\Newsletter\NewsletterConfig;
use Escalated\Services\Newsletter\NewsletterDispatcher;
use Escalated\Services\Newsletter\NewsletterPlanner;
use Escalated\Services\Newsletter\NewsletterRenderer;

class Newsletter_Dispatch
{
    private const LOCK_KEY = 'escalated_newsletter_dispatch_running';

    public function register(): void
    {
        add_action('escalated_dispatch_newsletters', [$this, 'run']);
    }

    public function run(): void
    {
        if (! NewsletterConfig::is_enabled()) {
            return;
        }
        if (get_transient(self::LOCK_KEY)) {
            return;
        }
        set_transient(self::LOCK_KEY, 1, 5 * MINUTE_IN_SECONDS);

        try {
            $this->plan_due();
            (new NewsletterDispatcher(new NewsletterRenderer))->dispatch_batch();
        } finally {
            delete_transient(self::LOCK_KEY);
        }
    }

    private function plan_due(): void
    {
        global $wpdb;
        $table = Newsletter::table();
        $now = current_time('mysql');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = 'scheduled' AND scheduled_at <= %s",
            $now
        )) ?: [];

        $planner = new NewsletterPlanner(new ContactSegmentResolver, new BounceSuppressionStore);
        foreach ($rows as $newsletter) {
            $planner->plan($newsletter);
        }
    }
}
