<?php
// api/src/Controllers/MessageController.php
declare(strict_types=1);

class MessageController
{
    /**
     * GET /messages?conversation_id=X[&before_id=Y|&after_id=Y|&around_id=Y]
     * Fetch a paginated window of messages for a conversation the user belongs to.
     */
    public static function index(array $params, int $userId): never
    {
        $convId = (int) ($_GET['conversation_id'] ?? 0);
        if ($convId <= 0) Response::error('conversation_id is required', 422);

        $conv = Conversation::findById($convId);
        if (!$conv) Response::error('Conversation not found', 404);

        if ((int) $conv['user1_id'] !== $userId && (int) $conv['user2_id'] !== $userId) {
            Response::error('Forbidden', 403);
        }

        $beforeId = isset($_GET['before_id']) ? (int) $_GET['before_id'] : null;
        $afterId  = isset($_GET['after_id'])  ? (int) $_GET['after_id']  : null;
        $aroundId = isset($_GET['around_id']) ? (int) $_GET['around_id'] : null;

        if ($aroundId !== null) {
            $result = Message::fetchAround($convId, $aroundId);
        } elseif ($beforeId !== null) {
            $result = Message::fetchBefore($convId, $beforeId);
        } elseif ($afterId !== null) {
            $result = Message::fetchAfter($convId, $afterId);
        } else {
            $result = Message::fetchLatest($convId);
        }

        Response::json($result);
    }

    /**
     * POST /messages — send an encrypted private message.
     * Body: {
     *   conversation_id, message_type, encrypted_content,
     *   encrypted_key_recipient, encrypted_key_sender,
     *   -- optional media fields --
     *   file_id, file_type,
     *   encrypted_file_key_recipient, encrypted_file_key_sender
     * }
     */
    public static function send(array $params, int $userId): never
    {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $convId              = (int)    ($body['conversation_id']           ?? 0);
        $messageType         = (string) ($body['message_type']              ?? 'text');
        $encryptedContent    = (string) ($body['encrypted_content']         ?? '');
        $encryptedKeyRcpt    = $body['encrypted_key_recipient']             ?? null;
        $encryptedKeySender  = $body['encrypted_key_sender']                ?? null;
        $fileId              = $body['file_id']                             ?? null;
        $fileType            = $body['file_type']                           ?? null;
        $fileName            = isset($body['file_name']) ? mb_substr(trim((string) $body['file_name']), 0, 255) : null;
        $encFileKeyRcpt      = $body['encrypted_file_key_recipient']        ?? null;
        $encFileKeySender    = $body['encrypted_file_key_sender']           ?? null;
        $replyToId           = isset($body['reply_to_id']) ? (int) $body['reply_to_id'] : null;

        if ($convId <= 0) Response::error('conversation_id is required', 422);
        if ($encryptedContent === '' && !$fileId) Response::error('encrypted_content is required', 422);

        $allowed = ['text', 'voice', 'media', 'text_media'];
        if (!in_array($messageType, $allowed, true)) Response::error('Invalid message_type', 422);

        $conv = Conversation::findById($convId);
        if (!$conv) Response::error('Conversation not found', 404);

        if ((int) $conv['user1_id'] !== $userId && (int) $conv['user2_id'] !== $userId) {
            Response::error('Forbidden', 403);
        }

        $msgId = Message::create(
            $convId, $userId, $messageType,
            $encryptedContent ?: '{}', $encryptedKeyRcpt, $encryptedKeySender, $replyToId
        );

        // Store media metadata if a file was attached
        if ($fileId && $fileType) {
            $allowedFileTypes = ['image', 'video', 'voice', 'file'];
            if (in_array($fileType, $allowedFileTypes, true)) {
                $db = Database::connection();
                $db->prepare(
                    'INSERT INTO message_media (message_id, file_path, file_type, file_name, encrypted_file_key_recipient, encrypted_file_key_sender)
                     VALUES (?, ?, ?, ?, ?, ?)'
                )->execute([$msgId, $fileId, $fileType, $fileName, $encFileKeyRcpt, $encFileKeySender]);
            }
        }

        Response::json(['message_id' => $msgId], 201);
    }

    /**
     * PATCH /messages/:id — edit own message (re-encrypts content client-side).
     * Body: { encrypted_content, encrypted_key_recipient, encrypted_key_sender }
     */
    public static function edit(array $params, int $userId): never
    {
        $msgId = (int) ($params['id'] ?? 0);
        if ($msgId <= 0) Response::error('Invalid message id', 400);

        $db   = Database::connection();
        $stmt = $db->prepare('SELECT sender_id, conversation_id FROM messages WHERE id = ?');
        $stmt->execute([$msgId]);
        $msg = $stmt->fetch();

        if (!$msg) Response::error('Not found', 404);
        if ((int) $msg['sender_id'] !== $userId) Response::error('Forbidden', 403);

        $body             = json_decode(file_get_contents('php://input'), true) ?? [];
        $encryptedContent = (string) ($body['encrypted_content']    ?? '');
        $encKeyRcpt       = $body['encrypted_key_recipient']         ?? null;
        $encKeySender     = $body['encrypted_key_sender']            ?? null;

        if ($encryptedContent === '') Response::error('encrypted_content is required', 422);

        $db->prepare(
            'UPDATE messages
             SET encrypted_content = ?, encrypted_key_recipient = ?, encrypted_key_sender = ?, is_edited = 1
             WHERE id = ?'
        )->execute([$encryptedContent, $encKeyRcpt, $encKeySender, $msgId]);

        Response::json(['ok' => true]);
    }

    /**
     * DELETE /messages/:id — delete own message (cascades to media + reactions).
     * Records the deletion in message_deletions so polling can broadcast it.
     */
    public static function destroy(array $params, int $userId): never
    {
        $msgId = (int) ($params['id'] ?? 0);
        if ($msgId <= 0) Response::error('Invalid message id', 400);

        $db   = Database::connection();
        $stmt = $db->prepare('SELECT sender_id, conversation_id, group_id FROM messages WHERE id = ?');
        $stmt->execute([$msgId]);
        $msg = $stmt->fetch();

        if (!$msg) Response::error('Not found', 404);
        if ((int) $msg['sender_id'] !== $userId) Response::error('Forbidden', 403);

        // Log deletion before removing the row so polling can notify other participants.
        // Wrapped in try-catch so a missing table never blocks the actual delete.
        try {
            $db->prepare(
                'INSERT IGNORE INTO message_deletions (message_id, conversation_id, group_id)
                 VALUES (?, ?, ?)'
            )->execute([$msgId, $msg['conversation_id'] ?: null, $msg['group_id'] ?: null]);
        } catch (\Throwable $e) { /* table may not exist yet */ }

        $db->prepare('DELETE FROM messages WHERE id = ?')->execute([$msgId]);
        Response::json(['ok' => true]);
    }
}
