<?php
// api/src/Models/Group.php
declare(strict_types=1);

class Group
{
    public static function create(string $name, int $createdBy): int
    {
        $db = Database::connection();
        $db->prepare('INSERT INTO `groups` (name, created_by) VALUES (?, ?)')->execute([$name, $createdBy]);
        return (int) $db->lastInsertId();
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM `groups` WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Add a member row (or silently ignore if already a member). */
    public static function addMember(int $groupId, int $userId, string $encryptedGroupKey): void
    {
        Database::connection()->prepare(
            'INSERT IGNORE INTO group_members (group_id, user_id, encrypted_group_key)
             VALUES (?, ?, ?)'
        )->execute([$groupId, $userId, $encryptedGroupKey]);
    }

    /** Rename a group. */
    public static function rename(int $groupId, string $name): void
    {
        Database::connection()->prepare(
            'UPDATE `groups` SET name = ? WHERE id = ?'
        )->execute([$name, $groupId]);
    }

    /** Remove one member from a group and clear their read-state row for that group. */
    public static function removeMember(int $groupId, int $userId): void
    {
        $db = Database::connection();
        $db->prepare(
            'DELETE FROM group_members WHERE group_id = ? AND user_id = ?'
        )->execute([$groupId, $userId]);
        $db->prepare(
            'DELETE FROM user_chat_state WHERE group_id = ? AND user_id = ?'
        )->execute([$groupId, $userId]);
    }

    /** Return current member count for a group. */
    public static function memberCount(int $groupId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) FROM group_members WHERE group_id = ?'
        );
        $stmt->execute([$groupId]);
        return (int) $stmt->fetchColumn();
    }

    public static function isMember(int $groupId, int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM group_members WHERE group_id = ? AND user_id = ?'
        );
        $stmt->execute([$groupId, $userId]);
        return (bool) $stmt->fetchColumn();
    }

    public static function isOwner(int $groupId, int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT 1 FROM `groups` WHERE id = ? AND created_by = ?'
        );
        $stmt->execute([$groupId, $userId]);
        return (bool) $stmt->fetchColumn();
    }

    /** Return the caller's own `encrypted_group_key` for this group. */
    public static function getMyEncryptedKey(int $groupId, int $userId): ?string
    {
        $stmt = Database::connection()->prepare(
            'SELECT encrypted_group_key FROM group_members WHERE group_id = ? AND user_id = ?'
        );
        $stmt->execute([$groupId, $userId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (string) $val : null;
    }

    /** Return all members of a group including their public keys. */
    public static function getMembers(int $groupId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT u.id, u.name, u.email, u.public_key, gm.encrypted_group_key
             FROM   group_members gm
             JOIN   users u ON u.id = gm.user_id
             WHERE  gm.group_id = ?
             ORDER  BY gm.joined_at ASC'
        );
        $stmt->execute([$groupId]);
        return $stmt->fetchAll();
    }

    /**
     * List all groups the user belongs to, with last message preview and unread count.
     * The `encrypted_group_key` in each row is the caller's own copy.
     */
    public static function listForUser(int $userId): array
    {
        $sql = <<<SQL
            SELECT
                g.id,
                g.name,
                g.created_by,
                gm_self.encrypted_group_key,
                m.id                                          AS last_message_id,
                m.encrypted_content                           AS last_encrypted_content,
                m.sender_id                                   AS last_sender_id,
                m.created_at                                  AS last_message_at,
                COALESCE(ucs.last_read_message_id, 0)        AS last_read_message_id,
                (
                    SELECT COUNT(*)
                    FROM   messages mm
                    WHERE  mm.group_id = g.id
                      AND  mm.id > COALESCE(ucs.last_read_message_id, 0)
                      AND  mm.sender_id != :uid2
                )                                             AS unread_count
            FROM `groups` g
            JOIN group_members gm_self
                ON gm_self.group_id = g.id AND gm_self.user_id = :uid
            LEFT JOIN messages m
                ON m.id = (SELECT MAX(id) FROM messages WHERE group_id = g.id)
            LEFT JOIN user_chat_state ucs
                ON ucs.user_id = :uid3 AND ucs.group_id = g.id
            ORDER BY COALESCE(m.created_at, g.created_at) DESC
        SQL;

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([':uid' => $userId, ':uid2' => $userId, ':uid3' => $userId]);
        return $stmt->fetchAll();
    }

    /**
     * Fetch all group messages for this user created after $since (ISO datetime).
     * Used by GET /updates.
     */
    /**
     * Return last_read_message_id for every member of a group.
     * Used to show read receipts.
     */
    public static function getReadReceipts(int $groupId): array
    {
        $sql = <<<SQL
            SELECT gm.user_id, u.name,
                   COALESCE(ucs.last_read_message_id, 0) AS last_read_message_id,
                   ucs.updated_at                          AS read_at
            FROM   group_members gm
            JOIN   users u ON u.id = gm.user_id
            LEFT JOIN user_chat_state ucs
                   ON ucs.user_id = gm.user_id AND ucs.group_id = ?
            WHERE  gm.group_id = ?
        SQL;
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$groupId, $groupId]);
        return $stmt->fetchAll();
    }

    public static function fetchMessagesSince(int $userId, string $since): array
    {
        $sql = <<<SQL
            SELECT m.id, m.group_id, m.sender_id, m.reply_to_id, m.message_type, m.is_edited,
                   m.encrypted_content, m.created_at,
                   rm.sender_id         AS reply_sender_id,
                   rm.encrypted_content AS reply_encrypted_content,
                   rm.message_type      AS reply_message_type
            FROM   messages m
            JOIN   group_members gm ON gm.group_id = m.group_id AND gm.user_id = ?
            LEFT JOIN messages rm ON rm.id = m.reply_to_id
            WHERE  (m.created_at > ? OR m.updated_at > ?)
            ORDER  BY m.id ASC
        SQL;
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$userId, $since, $since]);
        return $stmt->fetchAll();
    }

    private static function msgCols(): string
    {
        return 'm.id, m.group_id, m.sender_id, m.reply_to_id, m.message_type, m.is_edited,
                m.encrypted_content, m.created_at,
                rm.sender_id         AS reply_sender_id,
                rm.encrypted_content AS reply_encrypted_content,
                rm.message_type      AS reply_message_type';
    }

    private static function msgJoin(): string
    {
        return 'LEFT JOIN messages rm ON rm.id = m.reply_to_id';
    }

    /** Return the 10 most recent group messages. */
    public static function fetchLatestMessages(int $groupId, int $limit = 10): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::msgCols() . '
             FROM   messages m ' . self::msgJoin() . '
             WHERE  m.group_id = ?
             ORDER  BY m.id DESC
             LIMIT  ?'
        );
        $stmt->execute([$groupId, $limit + 1]);
        $rows = $stmt->fetchAll();

        $hasMoreAbove = count($rows) > $limit;
        if ($hasMoreAbove) array_pop($rows);
        $rows = array_reverse($rows);

        return ['messages' => $rows, 'has_more_above' => $hasMoreAbove, 'has_more_below' => false];
    }

    /** Return up to 10 group messages older than $beforeId (scroll up). */
    public static function fetchMessagesBefore(int $groupId, int $beforeId, int $limit = 10): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::msgCols() . '
             FROM   messages m ' . self::msgJoin() . '
             WHERE  m.group_id = ? AND m.id < ?
             ORDER  BY m.id DESC
             LIMIT  ?'
        );
        $stmt->execute([$groupId, $beforeId, $limit + 1]);
        $rows = $stmt->fetchAll();

        $hasMoreAbove = count($rows) > $limit;
        if ($hasMoreAbove) array_pop($rows);
        $rows = array_reverse($rows);

        return ['messages' => $rows, 'has_more_above' => $hasMoreAbove, 'has_more_below' => false];
    }

    /** Return up to 10 group messages newer than $afterId (scroll down). */
    public static function fetchMessagesAfter(int $groupId, int $afterId, int $limit = 10): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::msgCols() . '
             FROM   messages m ' . self::msgJoin() . '
             WHERE  m.group_id = ? AND m.id > ?
             ORDER  BY m.id ASC
             LIMIT  ?'
        );
        $stmt->execute([$groupId, $afterId, $limit + 1]);
        $rows = $stmt->fetchAll();

        $hasMoreBelow = count($rows) > $limit;
        if ($hasMoreBelow) array_pop($rows);

        return ['messages' => $rows, 'has_more_above' => false, 'has_more_below' => $hasMoreBelow];
    }

    /** Return 10 messages before + 10 at/after $aroundId. */
    public static function fetchMessagesAround(int $groupId, int $aroundId, int $limit = 10): array
    {
        $db   = Database::connection();
        $cols = self::msgCols();
        $join = self::msgJoin();

        $stmt = $db->prepare(
            'SELECT ' . $cols . ' FROM messages m ' . $join . '
             WHERE m.group_id = ? AND m.id < ?
             ORDER BY m.id DESC LIMIT ?'
        );
        $stmt->execute([$groupId, $aroundId, $limit + 1]);
        $before = $stmt->fetchAll();
        $hasMoreAbove = count($before) > $limit;
        if ($hasMoreAbove) array_pop($before);
        $before = array_reverse($before);

        $stmt = $db->prepare(
            'SELECT ' . $cols . ' FROM messages m ' . $join . '
             WHERE m.group_id = ? AND m.id >= ?
             ORDER BY m.id ASC LIMIT ?'
        );
        $stmt->execute([$groupId, $aroundId, $limit + 1]);
        $after = $stmt->fetchAll();
        $hasMoreBelow = count($after) > $limit;
        if ($hasMoreBelow) array_pop($after);

        return [
            'messages'       => array_merge($before, $after),
            'has_more_above' => $hasMoreAbove,
            'has_more_below' => $hasMoreBelow,
        ];
    }
}
