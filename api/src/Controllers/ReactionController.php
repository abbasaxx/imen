<?php
// api/src/Controllers/ReactionController.php
declare(strict_types=1);

class ReactionController
{
    /**
     * POST /messages/:id/reactions
     * Body: { emoji: string }
     * Adds a reaction (idempotent).
     */
    public static function add(array $params, int $userId): never
    {
        $messageId = (int) ($params['id'] ?? 0);
        if ($messageId <= 0) Response::error('Invalid message id', 400);

        $body  = json_decode(file_get_contents('php://input'), true) ?? [];
        $emoji = trim($body['emoji'] ?? '');

        if (!in_array($emoji, Reaction::ALLOWED, true)) {
            Response::error('Invalid emoji', 422);
        }

        if (!self::_canAccessMessage($messageId, $userId)) {
            Response::error('Forbidden', 403);
        }

        Reaction::add($messageId, $userId, $emoji);
        Response::json(['reactions' => Reaction::forMessage($messageId, $userId)]);
    }

    /**
     * DELETE /messages/:id/reactions/:emoji
     * Removes a reaction.
     */
    public static function remove(array $params, int $userId): never
    {
        $messageId = (int) ($params['id']    ?? 0);
        $emoji     = urldecode($params['emoji'] ?? '');

        if ($messageId <= 0) Response::error('Invalid message id', 400);
        if (!in_array($emoji, Reaction::ALLOWED, true)) Response::error('Invalid emoji', 422);

        if (!self::_canAccessMessage($messageId, $userId)) {
            Response::error('Forbidden', 403);
        }

        Reaction::remove($messageId, $userId, $emoji);
        Response::json(['reactions' => Reaction::forMessage($messageId, $userId)]);
    }

    /**
     * GET /reactions/batch?ids=1,2,3
     * Returns reactions for multiple messages in one query.
     * Only returns reactions for messages the user can actually access.
     */
    public static function batch(array $params, int $userId): never
    {
        $raw = trim($_GET['ids'] ?? '');
        if ($raw === '') Response::json(['reactions' => []]);

        // Parse, deduplicate and cap to 200 ids to prevent abuse
        $ids = array_values(array_unique(
            array_filter(array_map('intval', explode(',', $raw)))
        ));
        $ids = array_slice($ids, 0, 200);

        if (empty($ids)) Response::json(['reactions' => []]);

        // Filter to only messages the user can see (conversation or group member)
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = Database::connection()->prepare(
            "SELECT DISTINCT m.id
             FROM   messages m
             LEFT JOIN conversations c ON c.id = m.conversation_id
             LEFT JOIN group_members gm ON gm.group_id = m.group_id AND gm.user_id = ?
             WHERE  m.id IN ($placeholders)
               AND  (c.user1_id = ? OR c.user2_id = ? OR gm.user_id IS NOT NULL)"
        );
        $stmt->execute(array_merge([$userId], $ids, [$userId, $userId]));
        $allowed = array_column($stmt->fetchAll(), 'id');

        if (empty($allowed)) Response::json(['reactions' => []]);

        Response::json(['reactions' => Reaction::forMessages(array_map('intval', $allowed), $userId)]);
    }

    /**
     * GET /messages/:id/reactions
     * Returns current reactions for a message.
     */
    public static function index(array $params, int $userId): never
    {
        $messageId = (int) ($params['id'] ?? 0);
        if ($messageId <= 0) Response::error('Invalid message id', 400);

        if (!self::_canAccessMessage($messageId, $userId)) {
            Response::error('Forbidden', 403);
        }

        Response::json(['reactions' => Reaction::forMessage($messageId, $userId)]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Check that the user is a participant in the conversation/group that owns the message. */
    private static function _canAccessMessage(int $messageId, int $userId): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT m.conversation_id, m.group_id
             FROM   messages m
             WHERE  m.id = ?'
        );
        $stmt->execute([$messageId]);
        $msg = $stmt->fetch();
        if (!$msg) return false;

        if ($msg['conversation_id']) {
            $conv = Conversation::findById((int) $msg['conversation_id']);
            return $conv && ((int) $conv['user1_id'] === $userId || (int) $conv['user2_id'] === $userId);
        }

        if ($msg['group_id']) {
            return Group::isMember((int) $msg['group_id'], $userId);
        }

        return false;
    }
}
