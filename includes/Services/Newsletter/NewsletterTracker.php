<?php

namespace Escalated\Services\Newsletter;

use Escalated\Models\Newsletter\Newsletter;
use Escalated\Models\Newsletter\NewsletterDelivery;

class NewsletterTracker
{
    public function __construct(private readonly BounceSuppressionStore $bounces) {}

    public function record_open(string $token): void
    {
        $d = NewsletterDelivery::find_by_token($token);
        if (! $d) {
            return;
        }
        if (in_array($d->status, ['bounced', 'complained', 'failed'], true)) {
            return;
        }
        if ($d->opened_at !== null) {
            return;
        }
        global $wpdb;
        $now = current_time('mysql');
        $wpdb->update(NewsletterDelivery::table(), ['opened_at' => $now], ['id' => (int) $d->id]);
        $this->increment_summary((int) $d->newsletter_id, 'summary_opened');
    }

    public function record_click(string $token, string $url): void
    {
        unset($url);
        $d = NewsletterDelivery::find_by_token($token);
        if (! $d) {
            return;
        }
        if (in_array($d->status, ['bounced', 'complained', 'failed'], true)) {
            return;
        }
        global $wpdb;
        $table = NewsletterDelivery::table();
        $is_first = ((int) $d->clicks_count) === 0;
        $now = current_time('mysql');
        $wpdb->update($table, [
            'clicks_count' => (int) $d->clicks_count + 1,
            'last_clicked_at' => $now,
        ], ['id' => (int) $d->id]);
        if ($d->opened_at === null) {
            $wpdb->update($table, ['opened_at' => $now], ['id' => (int) $d->id]);
            $this->increment_summary((int) $d->newsletter_id, 'summary_opened');
        }
        if ($is_first) {
            $this->increment_summary((int) $d->newsletter_id, 'summary_clicked');
        }
    }

    public function record_bounce(string $token, string $type, ?string $reason = null): void
    {
        if ($type !== 'hard') {
            return;
        }
        $d = NewsletterDelivery::find_by_token($token);
        if (! $d || $d->status === 'bounced') {
            return;
        }
        global $wpdb;
        $wpdb->update(NewsletterDelivery::table(), [
            'status' => 'bounced',
            'bounce_reason' => $reason,
        ], ['id' => (int) $d->id]);
        $this->increment_summary((int) $d->newsletter_id, 'summary_bounced');
        $this->bounces->mark_bounced((string) $d->email_at_send);
    }

    public function record_complaint(string $token): void
    {
        $d = NewsletterDelivery::find_by_token($token);
        if (! $d || $d->status === 'complained') {
            return;
        }
        global $wpdb;
        $wpdb->update(NewsletterDelivery::table(), ['status' => 'complained'], ['id' => (int) $d->id]);
        $this->increment_summary((int) $d->newsletter_id, 'summary_complained');
        $this->bounces->mark_complained((string) $d->email_at_send);
    }

    private function increment_summary(int $newsletter_id, string $column): void
    {
        global $wpdb;
        $table = Newsletter::table();
        $allowed = ['summary_opened', 'summary_clicked', 'summary_bounced', 'summary_complained', 'summary_sent'];
        if (! in_array($column, $allowed, true)) {
            return;
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET {$column} = {$column} + 1 WHERE id = %d",
            $newsletter_id
        ));
    }
}
