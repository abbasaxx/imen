<?php
// api/src/Models/Message.php
declare(strict_types=1);

class Message
{
    /** Insert a new private message. Returns the new message id. */
    public static function create(
        int     $conversationId,
        int     $senderId,
        string  $messageType,
        string  $encryptedContent,
        ?string $encryptedKeyRecipient,
        ?string $encryptedKeySender,
        ?int    $replyToId = null
    ): int {
        $db = Database::connection();
        $db->prepare(
            'INSERT INTO messages
                 (conversation_id, sender_id, reply_to_id, message_type,
                  encrypted_content, encrypted_key_recipient, encrypted_key_sender)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $conversationId,
            $senderId,
            $replyToId,
            $messageType,
            $encryptedContent,
            $encryptedKeyRecipient,
            $encryptedKeySender,
        ]);
        return (int) $db->lastInsertId();
    }

    private static function cols(): string
    {
        return 'm.id, m.sender_id, m.reply_to_id, m.message_type, m.is_edited,
                m.encrypted_content, m.encrypted_key_recipient, m.encrypted_key_sender,
                m.created_at,
                mm.file_path, mm.file_type, mm.file_name,
                mm.encrypted_file_key_recipient, mm.encrypted_file_key_sender,
                rm.sender_id               AS reply_sender_id,
                rm.encrypted_content       AS reply_encrypted_content,
                rm.encrypted_key_recipient AS reply_encrypted_key_recipient,
                rm.encrypted_key_sender    AS reply_encrypted_key_sender,
                rmm.file_type              AS reply_file_type';
    }

    private static function join(): string
    {
        return 'LEFT JOIN message_media mm  ON mm.message_id  = m.id
                LEFT JOIN messages      rm  ON rm.id           = m.reply_to_id
                LEFT JOIN message_media rmm ON rmm.message_id  = rm.id';
    }

    /** Return the 10 most recent messages (initial open, no unread). */
    public static function fetchLatest(int $conversationId, int $limit = 10): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::cols() . '
             FROM   messages m ' . self::join() . '
             WHERE  m.conversation_id = ?
             ORDER  BY m.id DESC
             LIMIT  ?'
        );
        $stmt->execute([$conversationId, $limit + 1]);
        $rows = $stmt->fetchAll();

        $hasMoreAbove = count($rows) > $limit;
        if ($hasMoreAbove) array_pop($rows);
        $rows = array_reverse($rows);

        return ['messages' => $rows, 'has_more_above' => $hasMoreAbove, 'has_more_below' => false];
    }

    /** Return up to 10 messages older than $beforeId (scroll up). */
    public static function fetchBefore(int $conversationId, int $beforeId, int $limit = 10): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::cols() . '
             FROM   messages m ' . self::join() . '
             WHERE  m.conversation_id = ? AND m.id < ?
             ORDER  BY m.id DESC
             LIMIT  ?'
        );
        $stmt->execute([$conversationId, $beforeId, $limit + 1]);
        $rows = $stmt->fetchAll();

        $hasMoreAbove = count($rows) > $limit;
        if ($hasMoreAbove) array_pop($rows);
        $rows = array_reverse($rows);

        return ['messages' => $rows, 'has_more_above' => $hasMoreAbove, 'has_more_below' => false];
    }

    /** Return up to 10 messages newer than $afterId (scroll down). */
    public static function fetchAfter(int $conversationId, int $afterId, int $limit = 10): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ' . self::cols() . '
             FROM   messages m ' . self::join() . '
             WHERE  m.conversation_id = ? AND m.id > ?
             ORDER  BY m.id ASC
             LIMIT  ?'
        );
        $stmt->execute([$conversationId, $afterId, $limit + 1]);
        $rows = $stmt->fetchAll();

        $hasMoreBelow = count($rows) > $limit;
        if ($hasMoreBelow) array_pop($rows);

        return ['messages' => $rows, 'has_more_above' => false, 'has_more_below' => $hasMoreBelow];
    }

    /** Return 10 messages before + 10 at/after $aroundId (deep link or unread anchor). */
    public static function fetchAround(int $conversationId, int $aroundId, int $limit = 10): array
    {
        $db   = Database::connection();
        $cols = self::cols();
        $join = self::join();

        $stmt = $db->prepare(
            'SELECT ' . $cols . ' FROM messages m ' . $join . '
             WHERE m.conversation_id = ? AND m.id < ?
             ORDER BY m.id DESC LIMIT ?'
        );
        $stmt->execute([$conversationId, $aroundId, $limit + 1]);
        $before = $stmt->fetchAll();
        $hasMoreAbove = count($before) > $limit;
        if ($hasMoreAbove) array_pop($before);
        $before = array_reverse($before);

        $stmt = $db->prepare(
            'SELECT ' . $cols . ' FROM messages m ' . $join . '
             WHERE m.conversation_id = ? AND m.id >= ?
             ORDER BY m.id ASC LIMIT ?'
        );
        $stmt->execute([$conversationId, $aroundId, $limit + 1]);
        $after = $stmt->fetchAll();
        $hasMoreBelow = count($after) > $limit;
        if ($hasMoreBelow) array_pop($after);

        return [
            'messages'       => array_merge($before, $after),
            'has_more_above' => $hasMoreAbove,
            'has_more_below' => $hasMoreBelow,
        ];
    }

    /**
     * Fetch messages for a conversation with optional cursor-based pagination.
     * @deprecated Use fetchLatest / fetchBefore / fetchAfter / fetchAround instead.
     */
    public static function fetchForConversation(int $conversationId, int $sinceId = 0, int $limit = 50): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT m.id, m.sender_id, m.reply_to_id, m.message_type, m.is_edited,
                    m.encrypted_content, m.encrypted_key_recipient, m.encrypted_key_sender,
                    m.created_at,
                    mm.file_path, mm.file_type, mm.file_name,
                    mm.encrypted_file_key_recipient, mm.encrypted_file_key_sender
             FROM   messages m
             LEFT JOIN message_media mm ON mm.message_id = m.id
             WHERE  m.conversation_id = ? AND m.id > ?
             ORDER  BY m.id ASC
             LIMIT  ?'
        );
        $stmt->execute([$conversationId, $sinceId, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Fetch all new private messages for a user created after $since.
     * Used by GET /updates for cross-conversation polling.
     */
    public static function fetchSince(int $userId, string $since): array
    {
        $sql = <<<SQL
            SELECT m.id, m.conversation_id, m.sender_id, m.reply_to_id, m.message_type, m.is_edited,
                   m.encrypted_content, m.encrypted_key_recipient, m.encrypted_key_sender,
                   m.created_at,
                   mm.file_path, mm.file_type, mm.file_name,
                   mm.encrypted_file_key_recipient, mm.encrypted_file_key_sender,
                   rm.sender_id               AS reply_sender_id,
                   rm.encrypted_content       AS reply_encrypted_content,
                   rm.encrypted_key_recipient AS reply_encrypted_key_recipient,
                   rm.encrypted_key_sender    AS reply_encrypted_key_sender,
                   rmm.file_type              AS reply_file_type
            FROM   messages m
            JOIN   conversations c ON c.id = m.conversation_id
            LEFT JOIN message_media mm  ON mm.message_id  = m.id
            LEFT JOIN messages      rm  ON rm.id           = m.reply_to_id
            LEFT JOIN message_media rmm ON rmm.message_id  = rm.id
            WHERE  (c.user1_id = ? OR c.user2_id = ?)
              AND  (m.created_at > ? OR m.updated_at > ?)
            ORDER  BY m.id ASC
        SQL;

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([$userId, $userId, $since, $since]);
        return $stmt->fetchAll();
    }
}
