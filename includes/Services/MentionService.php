<?php

namespace Escalated\Services;

class MentionService
{
    private const MENTION_REGEX = '/@(\w+(?:\.\w+)*)/';

    public static function extractMentions(?string $text): array
    {
        if (empty($text)) {
            return [];
        }
        preg_match_all(self::MENTION_REGEX, $text, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    public static function extractUsernameFromEmail(string $email): string
    {
        if (empty($email)) {
            return '';
        }
        $parts = explode('@', $email);

        return $parts[0] ?? $email;
    }
}
