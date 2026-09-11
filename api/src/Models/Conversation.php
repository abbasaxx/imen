<?php
// api/src/Models/Conversation.php
declare(strict_types=1);

class Conversation
{
    /**
     * Find an existing conversation between two users, or create one.
     * Normalises so user1_id < user2_id to satisfy the UNIQUE KEY.
     */
    public static function findOrCreate(int $userId, int $peerId): int
    {
        $u1 = min($userId, $peerId);
        $u2 = max($userId, $peerId);

        $db   = Database::connection();
        $stmt = $db->prepare('SELECT id FROM conversations WHERE user1_id = ? AND user2_id = ?');
        $stmt->execute([$u1, $u2]);
        $row = $stmt->fetch();
        if ($row) return (int) $row['id'];

        try {
            $db->prepare('INSERT INTO conversations (user1_id, user2_id) VALUES (?, ?)')->execute([$u1, $u2]);
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), '1452')) {
                Response::error('One or both users not found', 404);
            }
            throw $e;
        }
        return (int) $db->lastInsertId();
    }

    /** Find a conversation by its id. */
    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM conversations WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * List all conversations for a user with peer info, last message metadata,
     * and unread message count.
     */
    public static function listForUser(int $userId): array
    {
        $sql = <<<SQL
            SELECT
                c.id,
                IF(c.user1_id = :uid, c.user2_id, c.user1_id)   AS peer_id,
                u.name                                            AS peer_name,
                u.email                                           AS peer_email,
                u.public_key                                      AS peer_public_key,
                m.id                                              AS last_message_id,
                m.encrypted_content                               AS last_encrypted_content,
                m.encrypted_key_recipient                         AS last_encrypted_key_recipient,
                m.encrypted_key_sender                            AS last_encrypted_key_sender,
                m.sender_id                                       AS last_sender_id,
                m.created_at                                      AS last_message_at,
                COALESCE(ucs.last_read_message_id, 0)            AS last_read_message_id,
                COALESCE(ucs_peer.last_read_message_id, 0)       AS peer_last_read_message_id,
                ucs_peer.updated_at                               AS peer_read_at,
                (
                    SELECT COUNT(*)
                    FROM   messages mm
                    WHERE  mm.conversation_id = c.id
                      AND  mm.id > COALESCE(ucs.last_read_message_id, 0)
                      AND  mm.sender_id != :uid2
                )                                                 AS unread_count
            FROM conversations c
            JOIN users u
                ON u.id = IF(c.user1_id = :uid3, c.user2_id, c.user1_id)
            LEFT JOIN messages m
                ON m.id = (
                    SELECT MAX(id) FROM messages WHERE conversation_id = c.id
                )
            LEFT JOIN user_chat_state ucs
                ON ucs.user_id = :uid4 AND ucs.conversation_id = c.id
            LEFT JOIN user_chat_state ucs_peer
                ON ucs_peer.user_id = IF(c.user1_id = :uid7, c.user2_id, c.user1_id)
               AND ucs_peer.conversation_id = c.id
            WHERE c.user1_id = :uid5 OR c.user2_id = :uid6
            ORDER BY COALESCE(m.created_at, c.created_at) DESC
        SQL;

        $stmt = Database::connection()->prepare($sql);
        $stmt->execute([
            ':uid'  => $userId,
            ':uid2' => $userId,
            ':uid3' => $userId,
            ':uid4' => $userId,
            ':uid5' => $userId,
            ':uid6' => $userId,
            ':uid7' => $userId,
        ]);
        return $stmt->fetchAll();
    }
}
