<?php

namespace Escalated\Services\Newsletter;

use Escalated\Models\Setting;

class BounceSuppressionStore
{
    private const KEY = 'newsletter.suppressed_emails';

    public function mark_bounced(string $email): void
    {
        $this->mark($email);
    }

    public function mark_complained(string $email): void
    {
        $this->mark($email);
    }

    public function is_bounced(string $email): bool
    {
        return in_array(strtolower($email), $this->load(), true);
    }

    /**
     * @param  array<string>  $emails
     * @return array<string>
     */
    public function filter_sendable(array $emails): array
    {
        $suppressed = array_flip($this->load());

        return array_values(array_filter($emails, fn ($e) => ! isset($suppressed[strtolower($e)])));
    }

    private function mark(string $email): void
    {
        $list = $this->load();
        $email = strtolower($email);
        if (in_array($email, $list, true)) {
            return;
        }
        $list[] = $email;
        Setting::set(self::KEY, wp_json_encode($list));
    }

    /**
     * @return array<string>
     */
    private function load(): array
    {
        $raw = Setting::get(self::KEY);
        if (! $raw) {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }
}
