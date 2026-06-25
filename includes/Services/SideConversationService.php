<?php

namespace Escalated\Services;

use Escalated\Models\SideConversation;
use Escalated\Models\SideConversationReply;

/**
 * Manages side conversations (private internal/email threads on a ticket).
 * Mirrors the Laravel SideConversationController: creating a conversation
 * opens it with a first reply, replies can be appended, and a conversation
 * can be closed.
 */
class SideConversationService
{
    /**
     * Open a new side conversation on a ticket with its first reply.
     *
     * @param  int  $ticket_id
     * @param  string  $subject
     * @param  string  $channel
     * @param  string  $body
     * @param  int|string|null  $created_by
     * @return int|false The new conversation ID, or false on invalid input.
     */
    public function create($ticket_id, $subject, $channel, $body, $created_by = null)
    {
        $subject = trim((string) $subject);
        $body = trim((string) $body);

        if ($subject === '' || $body === '' || ! SideConversation::valid_channel($channel)) {
            return false;
        }

        $conversation_id = SideConversation::create([
            'ticket_id' => $ticket_id,
            'subject' => $subject,
            'channel' => $channel,
            'status' => SideConversation::STATUS_OPEN,
            'created_by' => $created_by,
        ]);

        if ($conversation_id === false) {
            return false;
        }

        SideConversationReply::create([
            'side_conversation_id' => $conversation_id,
            'body' => $body,
            'author_id' => $created_by,
        ]);

        return $conversation_id;
    }

    /**
     * Append a reply to a conversation.
     *
     * @param  int  $conversation_id
     * @param  string  $body
     * @param  int|string|null  $author_id
     * @return int|false The new reply ID, or false on invalid input.
     */
    public function add_reply($conversation_id, $body, $author_id = null)
    {
        $body = trim((string) $body);
        if ($body === '') {
            return false;
        }

        return SideConversationReply::create([
            'side_conversation_id' => $conversation_id,
            'body' => $body,
            'author_id' => $author_id,
        ]);
    }

    /**
     * Close a side conversation.
     *
     * @param  int  $conversation_id
     * @return bool
     */
    public function close($conversation_id)
    {
        return SideConversation::update($conversation_id, [
            'status' => SideConversation::STATUS_CLOSED,
        ]);
    }

    /**
     * All side conversations for a ticket (newest first), each with its
     * replies attached as a `replies` property.
     *
     * @param  int  $ticket_id
     * @return array
     */
    public function for_ticket($ticket_id)
    {
        $conversations = SideConversation::for_ticket($ticket_id);

        foreach ($conversations as $conversation) {
            $conversation->replies = SideConversationReply::for_conversation($conversation->id);
        }

        return $conversations;
    }
}
