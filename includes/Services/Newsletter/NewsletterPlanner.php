<?php

namespace Escalated\Services\Newsletter;

use Escalated\Models\Contact;
use Escalated\Models\Newsletter\Newsletter;
use Escalated\Models\Newsletter\NewsletterDelivery;
use Escalated\Models\Newsletter\NewsletterList;

class NewsletterPlanner
{
    public function __construct(
        private readonly ContactSegmentResolver $segments,
        private readonly BounceSuppressionStore $bounces,
    ) {}

    public function plan(object $newsletter): void
    {
        Newsletter::update((int) $newsletter->id, ['status' => 'sending']);

        $list = NewsletterList::find((int) $newsletter->target_list_id);
        if (! $list) {
            Newsletter::update((int) $newsletter->id, ['summary_total' => 0]);

            return;
        }

        $contact_ids = $this->segments->resolve_sendable($list);
        if ($contact_ids === []) {
            Newsletter::update((int) $newsletter->id, ['summary_total' => 0]);

            return;
        }

        global $wpdb;
        $contacts_table = Contact::table();
        $placeholders = implode(',', array_fill(0, count($contact_ids), '%d'));
        $contacts = $wpdb->get_results($wpdb->prepare(
            "SELECT id, email FROM {$contacts_table} WHERE id IN ({$placeholders})",
            ...$contact_ids
        )) ?: [];

        $emails = array_map(fn ($c) => (string) $c->email, $contacts);
        $sendable = array_flip(array_map('strtolower', $this->bounces->filter_sendable($emails)));

        $rows = [];
        $now = current_time('mysql');
        foreach ($contacts as $contact) {
            if (! isset($sendable[strtolower((string) $contact->email)])) {
                continue;
            }
            $rows[] = [
                'newsletter_id' => (int) $newsletter->id,
                'contact_id' => (int) $contact->id,
                'email_at_send' => (string) $contact->email,
                'status' => 'pending',
                'tracking_token' => $this->generate_token(),
                'attempt_count' => 0,
                'is_test' => 0,
                'created_at' => $now,
            ];
        }

        $table = NewsletterDelivery::table();
        foreach (array_chunk($rows, 500) as $chunk) {
            foreach ($chunk as $row) {
                $wpdb->insert($table, $row);
            }
        }

        Newsletter::update((int) $newsletter->id, ['summary_total' => count($rows)]);
    }

    private function generate_token(): string
    {
        return wp_generate_password(40, false, false);
    }
}
