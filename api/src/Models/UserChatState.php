<?php
// api/src/Models/UserChatState.php
declare(strict_types=1);

class UserChatState
{
    /**
     * Upsert the last-read message pointer for a user in a conversation or group.
     * Uses GREATEST so the pointer never moves backwards.
     */
    public static function upsert(int $userId, ?int $conversationId, ?int $groupId, int $lastReadMessageId): void
    {
        $db = Database::connection();

        if ($conversationId !== null) {
            $db->prepare(
                'INSERT INTO user_chat_state (user_id, conversation_id, last_read_message_id)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id))'
            )->execute([$userId, $conversationId, $lastReadMessageId]);
            return;
        }

        if ($groupId !== null) {
            $db->prepare(
                'INSERT INTO user_chat_state (user_id, group_id, last_read_message_id)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                     last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id))'
            )->execute([$userId, $groupId, $lastReadMessageId]);
        }
    }
}
