<?php
// api/src/Controllers/UpdateController.php
declare(strict_types=1);

class UpdateController
{
    /**
     * GET /updates?since=<ISO-datetime>
     * Returns all new private messages and the refreshed conversation list
     * for the authenticated user. Designed for 2-second polling.
     *
     * The `since` parameter must be an ISO 8601 / MySQL-compatible datetime
     * string (e.g. "2026-04-11T08:30:00.000Z"). The frontend poll.js already
     * sends new Date().toISOString(), so we normalise the Z-suffix here.
     */
    public static function poll(array $params, int $userId): never
    {
        $since = trim($_GET['since'] ?? '');

        // Default: epoch (first call returns nothing older than app boot)
        if ($since === '') {
            $since = '1970-01-01 00:00:00';
        } else {
            // Convert ISO 8601 "T" + "Z" to MySQL datetime
            $since = str_replace(['T', 'Z'], [' ', ''], $since);
            // Trim sub-second precision that MySQL DATETIME doesn't store
            if (str_contains($since, '.')) {
                $since = substr($since, 0, strrpos($since, '.'));
            }
        }

        $messages      = Message::fetchSince($userId, $since);
        $conversations = Conversation::listForUser($userId);
        $groupMessages = Group::fetchMessagesSince($userId, $since);
        $groups        = Group::listForUser($userId);
        $reactions     = Reaction::fetchSince($userId, $since);

        // Deleted message ids the user should remove from their local store
        try {
            $deletedIds = self::fetchDeletedMessageIds($userId, $since);
        } catch (\Throwable $e) {
            $deletedIds = []; // table may not exist yet
        }

        // Read receipts for all groups the user belongs to
        $groupReadReceipts = [];
        foreach ($groups as $g) {
            $groupReadReceipts[(int)$g['id']] = Group::getReadReceipts((int)$g['id']);
        }

        Response::json([
            'messages'              => $messages,
            'conversations'         => $conversations,
            'group_messages'        => $groupMessages,
            'groups'                => $groups,
            'reactions'             => $reactions,
            'deleted_message_ids'   => $deletedIds,
            'group_read_receipts'   => $groupReadReceipts,
            'server_time'           => gmdate('Y-m-d\TH:i:s\Z'),
        ]);
    }

    /**
     * Return ids of messages deleted since $since that the user was a participant of
     * (either via conversation membership or group membership).
     */
    private static function fetchDeletedMessageIds(int $userId, string $since): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT d.message_id
             FROM   message_deletions d
             LEFT JOIN conversations c ON c.id = d.conversation_id
             LEFT JOIN group_members gm ON gm.group_id = d.group_id AND gm.user_id = ?
             WHERE  d.deleted_at > ?
               AND  (c.user1_id = ? OR c.user2_id = ? OR gm.user_id IS NOT NULL)'
        );
        $stmt->execute([$userId, $since, $userId, $userId]);
        return array_column($stmt->fetchAll(\PDO::FETCH_ASSOC), 'message_id');
    }
}
