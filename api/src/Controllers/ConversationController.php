<?php
// api/src/Controllers/ConversationController.php
declare(strict_types=1);

class ConversationController
{
    /** GET /conversations — list all conversations for the authenticated user. */
    public static function index(array $params, int $userId): never
    {
        $conversations = Conversation::listForUser($userId);
        Response::json(['conversations' => $conversations]);
    }

    /** POST /conversations — find or create a private conversation with another user. */
    public static function create(array $params, int $userId): never
    {
        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $peerId = (int) ($body['peer_id'] ?? 0);

        if ($peerId <= 0)          Response::error('peer_id is required', 422);
        if ($peerId === $userId)   Response::error('Cannot start a conversation with yourself', 422);

        $peer = User::findById($peerId);
        if (!$peer)                Response::error('User not found', 404);

        $convId = Conversation::findOrCreate($userId, $peerId);
        $conv   = Conversation::findById($convId);

        Response::json(['conversation' => $conv, 'peer' => $peer], 201);
    }

    /** PATCH /conversations/:id/read — update the last-read pointer for this user. */
    public static function markRead(array $params, int $userId): never
    {
        $convId = (int) ($params['id'] ?? 0);
        if ($convId <= 0) Response::error('Invalid conversation id', 422);

        $conv = Conversation::findById($convId);
        if (!$conv) Response::error('Conversation not found', 404);

        if ((int) $conv['user1_id'] !== $userId && (int) $conv['user2_id'] !== $userId) {
            Response::error('Forbidden', 403);
        }

        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $lastId = (int) ($body['last_message_id'] ?? 0);

        UserChatState::upsert($userId, $convId, null, $lastId);
        Response::json(['ok' => true]);
    }
}
