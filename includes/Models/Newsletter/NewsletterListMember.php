<?php

namespace Escalated\Models\Newsletter;

use Escalated\Escalated;

class NewsletterListMember
{
    public static function table(): string
    {
        return Escalated::table('newsletter_list_members');
    }
}
