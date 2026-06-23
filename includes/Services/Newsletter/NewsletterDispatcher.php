<?php

namespace Escalated\Services\Newsletter;

use Escalated\Models\Contact;
use Escalated\Models\Newsletter\Newsletter;
use Escalated\Models\Newsletter\NewsletterDelivery;
use Escalated\Models\Newsletter\NewsletterTemplate;

class NewsletterDispatcher
{
    /** @var array<int, int> */
    private const BACKOFF_MINUTES = [1, 5, 30];

    public function __construct(private readonly NewsletterRenderer $renderer) {}

    public function dispatch_batch(): void
    {
        if (! NewsletterConfig::is_enabled()) {
            return;
        }

        $this->reclaim_stuck_rows();

        $batch_size = NewsletterConfig::batch_size();
        $rate_limit = NewsletterConfig::rate_limit_per_minute();
        $minute_key = 'escalated_newsletters_sent_'.gmdate('YmdHi');
        $sent_this_minute = (int) get_transient($minute_key);
        $allowance = max(0, $rate_limit - $sent_this_minute);

        if ($allowance > 0) {
            $claim_limit = min($batch_size, $allowance);
            $ids = $this->claim_pending($claim_limit);
            if ($ids !== []) {
                set_transient($minute_key, $sent_this_minute + count($ids), 2 * MINUTE_IN_SECONDS);
            }
            foreach ($ids as $id) {
                $delivery = $this->load_delivery((int) $id);
                if ($delivery) {
                    $this->dispatch_one($delivery);
                }
            }
        }

        $this->finalize_completed_newsletters();
        $this->check_auto_pause();
    }

    /**
     * @return array<int>
     */
    private function claim_pending(int $limit): array
    {
        global $wpdb;
        $table = NewsletterDelivery::table();
        $now = current_time('mysql');

        $wpdb->query('START TRANSACTION');
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table}
             WHERE status = 'pending'
               AND (next_attempt_at IS NULL OR next_attempt_at <= %s)
             ORDER BY id ASC
             LIMIT %d
             FOR UPDATE",
            $now,
            $limit
        )) ?: [];

        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '%d'));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET status = 'queued', claimed_at = %s WHERE id IN ({$placeholders})",
                $now,
                ...array_map('intval', $ids)
            ));
        }
        $wpdb->query('COMMIT');

        return array_map('intval', $ids);
    }

    private function dispatch_one(object $delivery): void
    {
        $newsletter = Newsletter::find((int) $delivery->newsletter_id);
        $contact = Contact::find((int) $delivery->contact_id);
        if (! $newsletter || ! $contact) {
            $this->fail_delivery($delivery, 'Missing newsletter or contact');

            return;
        }

        $template = ! empty($newsletter->template_id)
            ? NewsletterTemplate::find((int) $newsletter->template_id)
            : null;

        try {
            $html = $this->renderer->render($delivery, $newsletter, $contact, $template);
            $unsub = $this->renderer->unsubscribe_url($delivery);
            $host = wp_parse_url(home_url(), PHP_URL_HOST) ?: 'localhost';
            $headers = [
                'Content-Type: text/html; charset=UTF-8',
                'List-Unsubscribe: <'.$unsub.'>',
                'List-Unsubscribe-Post: List-Unsubscribe=One-Click',
                'X-Escalated-Newsletter-Id: '.(int) $newsletter->id,
            ];
            $message_id = sprintf('n-%d-%s@%s', (int) $newsletter->id, $delivery->tracking_token, $host);
            $headers[] = 'Message-ID: <'.$message_id.'>';

            $from = ! empty($newsletter->from_name)
                ? sprintf('%s <%s>', $newsletter->from_name, $newsletter->from_email)
                : (string) $newsletter->from_email;
            $headers[] = 'From: '.$from;
            if (! empty($newsletter->reply_to)) {
                $headers[] = 'Reply-To: '.$newsletter->reply_to;
            }

            $sent = wp_mail(
                (string) $delivery->email_at_send,
                (string) $newsletter->subject,
                $html,
                $headers
            );
            if (! $sent) {
                throw new \RuntimeException('wp_mail failed');
            }

            global $wpdb;
            $now = current_time('mysql');
            $wpdb->update(NewsletterDelivery::table(), [
                'status' => 'sent',
                'sent_at' => $now,
                'claimed_at' => null,
                'next_attempt_at' => null,
            ], ['id' => (int) $delivery->id]);
            $wpdb->query($wpdb->prepare(
                'UPDATE '.Newsletter::table().' SET summary_sent = summary_sent + 1 WHERE id = %d',
                (int) $newsletter->id
            ));
        } catch (\Throwable $e) {
            $next = (int) $delivery->attempt_count + 1;
            if ($next >= count(self::BACKOFF_MINUTES)) {
                $this->fail_delivery($delivery, $e->getMessage(), $next);
            } else {
                global $wpdb;
                $backoff = self::BACKOFF_MINUTES[$next - 1];
                $wpdb->update(NewsletterDelivery::table(), [
                    'status' => 'pending',
                    'attempt_count' => $next,
                    'claimed_at' => null,
                    'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + $backoff * 60),
                ], ['id' => (int) $delivery->id]);
            }
        }
    }

    private function fail_delivery(object $delivery, string $reason, ?int $attempts = null): void
    {
        global $wpdb;
        $wpdb->update(NewsletterDelivery::table(), [
            'status' => 'failed',
            'failure_reason' => $reason,
            'attempt_count' => $attempts ?? (int) $delivery->attempt_count + 1,
            'claimed_at' => null,
            'next_attempt_at' => null,
        ], ['id' => (int) $delivery->id]);
    }

    private function reclaim_stuck_rows(): void
    {
        global $wpdb;
        $table = NewsletterDelivery::table();
        $minutes = NewsletterConfig::claim_timeout_minutes();
        $cutoff = gmdate('Y-m-d H:i:s', time() - $minutes * 60);
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'pending', claimed_at = NULL
             WHERE status = 'queued' AND claimed_at IS NOT NULL AND claimed_at < %s",
            $cutoff
        ));
    }

    private function finalize_completed_newsletters(): void
    {
        global $wpdb;
        $newsletters = $wpdb->get_results(
            'SELECT id, sent_at FROM '.Newsletter::table()." WHERE status = 'sending'"
        ) ?: [];
        $deliveries = NewsletterDelivery::table();
        foreach ($newsletters as $n) {
            $remaining = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$deliveries}
                 WHERE newsletter_id = %d AND status IN ('pending','queued')",
                (int) $n->id
            ));
            if ($remaining === 0) {
                Newsletter::update((int) $n->id, [
                    'status' => 'sent',
                    'sent_at' => $n->sent_at ?: current_time('mysql'),
                ]);
            }
        }
    }

    private function check_auto_pause(): void
    {
        global $wpdb;
        $threshold = NewsletterConfig::auto_pause_threshold();
        $rate = NewsletterConfig::auto_pause_bounce_rate();
        $deliveries = NewsletterDelivery::table();
        $newsletters = $wpdb->get_col(
            'SELECT id FROM '.Newsletter::table()." WHERE status = 'sending'"
        ) ?: [];

        foreach ($newsletters as $nid) {
            $statuses = $wpdb->get_col($wpdb->prepare(
                "SELECT status FROM {$deliveries}
                 WHERE newsletter_id = %d AND status IN ('sent','bounced','complained','failed')
                 ORDER BY id ASC LIMIT %d",
                (int) $nid,
                $threshold
            )) ?: [];
            if (count($statuses) < $threshold) {
                continue;
            }
            $bounced = count(array_filter($statuses, fn ($s) => $s === 'bounced'));
            if ($bounced / $threshold >= $rate) {
                Newsletter::update((int) $nid, ['status' => 'paused']);
            }
        }
    }

    private function load_delivery(int $id): ?object
    {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM '.NewsletterDelivery::table().' WHERE id = %d',
            $id
        )) ?: null;
    }
}
