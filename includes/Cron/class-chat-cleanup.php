<?php

namespace Escalated\Cron;

class Chat_Cleanup
{
    public function register(): void
    {
        add_action('escalated_chat_cleanup', [$this, 'run']);
    }

    public function run(): void
    {
        $timeout_minutes = (int) \Escalated\Models\Setting::get('chat_idle_timeout', 30);
        $cutoff = gmdate('Y-m-d H:i:s', strtotime("-{$timeout_minutes} minutes"));

        $idle_sessions = \Escalated\Models\ChatSession::get_idle($cutoff);

        $service = new \Escalated\Services\ChatSessionService;

        foreach ($idle_sessions as $session) {
            try {
                $service->end((int) $session->id);
            } catch (\Throwable $e) {
                // Skip sessions that can't be ended.
            }
        }
    }
}
