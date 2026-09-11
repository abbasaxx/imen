<?php
// api/src/Models/Reaction.php
declare(strict_types=1);

class Reaction
{
    public const ALLOWED = ['👍', '❤️', '😂', '😮', '😢', '🔥', '👏', '🎉'];

    /** Add a reaction (idempotent — INSERT IGNORE). */
    public static function add(int $messageId, int $userId, string $emoji): void
    {
        Database::connection()->prepare(
            'INSERT IGNORE INTO reactions (message_id, user_id, emoji) VALUES (?, ?, ?)'
        )->execute([$messageId, $userId, $emoji]);
    }

    /** Remove a reaction. */
    public static function remove(int $messageId, int $userId, string $emoji): void
    {
        Database::connection()->prepare(
            'DELETE FROM reactions WHERE message_id = ? AND user_id = ? AND emoji = ?'
        )->execute([$messageId, $userId, $emoji]);
    }

    /**
     * Get all reactions for a message.
     * Returns: [{ emoji, count, reacted_by_me, by: [name, ...] }]
     */
    public static function forMessage(int $messageId, int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT r.emoji, r.user_id, u.name, r.created_at AS at
             FROM   reactions r
             JOIN   users u ON u.id = r.user_id
             WHERE  r.message_id = ?
             ORDER  BY r.created_at ASC'
        );
        $stmt->execute([$messageId]);
        $rows = $stmt->fetchAll();

        $grouped = [];
        foreach ($rows as $row) {
            $e = $row['emoji'];
            if (!isset($grouped[$e])) {
                $grouped[$e] = ['emoji' => $e, 'count' => 0, 'reacted_by_me' => false, 'by' => []];
            }
            $grouped[$e]['count']++;
            if ((int) $row['user_id'] === $userId) $grouped[$e]['reacted_by_me'] = true;
            $grouped[$e]['by'][] = ['name' => $row['name'], 'at' => $row['at']];
        }
        return array_values($grouped);
    }

    /**
     * Get all reactions for a set of message ids.
     * Returns: { [messageId]: [{ emoji, count, reacted_by_me, by: [name, ...] }] }
     */
    public static function forMessages(array $messageIds, int $userId): array
    {
        if (empty($messageIds)) return [];

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT r.message_id, r.emoji, r.user_id, u.name, r.created_at AS at
             FROM   reactions r
             JOIN   users u ON u.id = r.user_id
             WHERE  r.message_id IN ($placeholders)
             ORDER  BY r.created_at ASC"
        );
        $stmt->execute($messageIds);

        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $mid = (int) $row['message_id'];
            $e   = $row['emoji'];
            if (!isset($grouped[$mid][$e])) {
                $grouped[$mid][$e] = ['emoji' => $e, 'count' => 0, 'reacted_by_me' => false, 'by' => []];
            }
            $grouped[$mid][$e]['count']++;
            if ((int) $row['user_id'] === $userId) $grouped[$mid][$e]['reacted_by_me'] = true;
            $grouped[$mid][$e]['by'][] = ['name' => $row['name'], 'at' => $row['at']];
        }

        $result = [];
        foreach ($grouped as $mid => $emojis) {
            $result[$mid] = array_values($emojis);
        }
        return $result;
    }

    /**
     * Fetch reactions changed since $since for all messages visible to $userId.
     * Used by UpdateController polling.
     */
    public static function fetchSince(int $userId, string $since): array
    {
        // Get all message ids that had reactions changed since $since and are visible to $userId
        $sql = <<<SQL
            SELECT DISTINCT r.message_id
            FROM   reactions r
            JOIN   messages m ON m.id = r.message_id
            LEFT JOIN conversations c ON c.id = m.conversation_id
            LEFT JOIN group_members gm ON gm.group_id = m.group_id AND gm.user_id = ?
            WHERE  (c.user1_id = ? OR c.user2_id = ? OR gm.user_id IS NOT NULL)
              AND  r.created_at > ?
        SQL;
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$userId, $userId, $userId, $since]);
        $messageIds = array_column($stmt->fetchAll(), 'message_id');

        if (empty($messageIds)) return [];
        return self::forMessages(array_map('intval', $messageIds), $userId);
    }
}
