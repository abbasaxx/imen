<?php
// api/src/Controllers/GroupController.php
declare(strict_types=1);

class GroupController
{
    /** GET /groups — list all groups the user is a member of. */
    public static function index(array $params, int $userId): never
    {
        $groups = Group::listForUser($userId);
        Response::json(['groups' => $groups]);
    }

    /**
     * POST /groups — create a group and its initial member rows.
     *
     * Body: {
     *   name: string,
     *   encrypted_group_key_self: string,          // group AES key encrypted with creator's RSA public key
     *   members: [{ user_id: int, encrypted_group_key: string }, ...]
     * }
     */
    public static function create(array $params, int $userId): never
    {
        $body    = json_decode(file_get_contents('php://input'), true) ?? [];
        $name    = trim($body['name'] ?? '');
        $selfKey = (string) ($body['encrypted_group_key_self'] ?? '');
        $members = is_array($body['members'] ?? null) ? $body['members'] : [];

        if ($name === '')    Response::error('name is required', 422);
        if ($selfKey === '') Response::error('encrypted_group_key_self is required', 422);

        $groupId = Group::create($name, $userId);

        // Creator's own encrypted key row
        Group::addMember($groupId, $userId, $selfKey);

        // Additional members
        foreach ($members as $m) {
            $peerId = (int) ($m['user_id'] ?? 0);
            $encKey = (string) ($m['encrypted_group_key'] ?? '');
            if ($peerId > 0 && $encKey !== '') {
                Group::addMember($groupId, $peerId, $encKey);
            }
        }

        Response::json(['group_id' => $groupId], 201);
    }

    /** GET /groups/:id/members — return member list with public keys. */
    public static function members(array $params, int $userId): never
    {
        $groupId = (int) ($params['id'] ?? 0);
        if (!Group::isMember($groupId, $userId)) Response::error('Forbidden', 403);

        Response::json(['members' => Group::getMembers($groupId)]);
    }

    /**
     * POST /groups/:id/members — add a new member.
     * Body: { user_id: int, encrypted_group_key: string }
     * The caller must already be a member (and must have decrypted the group key
     * client-side and re-encrypted it for the new member's RSA public key).
     */
    public static function addMember(array $params, int $userId): never
    {
        $groupId = (int) ($params['id'] ?? 0);
        if (!Group::isMember($groupId, $userId)) Response::error('Forbidden', 403);
        if (!Group::isOwner($groupId, $userId)) Response::error('Only group owner can edit group', 403);

        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $peerId = (int) ($body['user_id'] ?? 0);
        $encKey = (string) ($body['encrypted_group_key'] ?? '');

        if ($peerId <= 0) Response::error('user_id is required', 422);
        if ($encKey === '') Response::error('encrypted_group_key is required', 422);

        $peer = User::findById($peerId);
        if (!$peer) Response::error('User not found', 404);

        Group::addMember($groupId, $peerId, $encKey);
        Response::json(['ok' => true]);
    }

    /**
     * PATCH /groups/:id — rename a group.
     * Body: { name: string }
     */
    public static function update(array $params, int $userId): never
    {
        $groupId = (int) ($params['id'] ?? 0);
        if (!Group::isMember($groupId, $userId)) Response::error('Forbidden', 403);
        if (!Group::isOwner($groupId, $userId)) Response::error('Only group owner can edit group', 403);

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') Response::error('name is required', 422);

        Group::rename($groupId, $name);
        Response::json(['ok' => true, 'name' => $name]);
    }

    /**
     * DELETE /groups/:id/members/:memberId — remove a member from a group.
     */
    public static function removeMember(array $params, int $userId): never
    {
        $groupId  = (int) ($params['id'] ?? 0);
        $memberId = (int) ($params['memberId'] ?? 0);

        if (!Group::isMember($groupId, $userId)) Response::error('Forbidden', 403);
        if (!Group::isOwner($groupId, $userId)) Response::error('Only group owner can edit group', 403);
        if ($memberId <= 0) Response::error('Invalid member id', 422);
        if ($memberId === $userId) Response::error('You cannot remove yourself', 422);
        if (!Group::isMember($groupId, $memberId)) Response::error('Member not found', 404);
        if (Group::isOwner($groupId, $memberId)) Response::error('Group owner cannot be removed', 422);
        if (Group::memberCount($groupId) <= 1) Response::error('Group must have at least one member', 422);

        Group::removeMember($groupId, $memberId);
        Response::json(['ok' => true]);
    }

    /** PATCH /groups/:id/read — update last-read pointer. */
    public static function markRead(array $params, int $userId): never
    {
        $groupId = (int) ($params['id'] ?? 0);
        if (!Group::isMember($groupId, $userId)) Response::error('Forbidden', 403);

        $body   = json_decode(file_get_contents('php://input'), true) ?? [];
        $lastId = (int) ($body['last_message_id'] ?? 0);

        UserChatState::upsert($userId, null, $groupId, $lastId);
        Response::json(['ok' => true]);
    }

    /**
     * POST /groups/:id/messages — send an encrypted group message.
     * Body: { encrypted_content: string, message_type: string }
     * `encrypted_content` is JSON: { ciphertext: base64, iv: base64 }
     * encrypted with the shared group AES key (no per-message RSA wrapping).
     */
    public static function sendMessage(array $params, int $userId): never
    {
        $groupId = (int) ($params['id'] ?? 0);
        if (!Group::isMember($groupId, $userId)) Response::error('Forbidden', 403);

        $body             = json_decode(file_get_contents('php://input'), true) ?? [];
        $encryptedContent = (string) ($body['encrypted_content'] ?? '');
        $messageType      = (string) ($body['message_type'] ?? 'text');
        $replyToId        = isset($body['reply_to_id']) ? (int) $body['reply_to_id'] : null;

        if ($encryptedContent === '') Response::error('encrypted_content is required', 422);

        $allowed = ['text', 'voice', 'media', 'text_media'];
        if (!in_array($messageType, $allowed, true)) Response::error('Invalid message_type', 422);

        $db = Database::connection();
        $db->prepare(
            'INSERT INTO messages (group_id, sender_id, reply_to_id, message_type, encrypted_content)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$groupId, $userId, $replyToId, $messageType, $encryptedContent]);

        Response::json(['message_id' => (int) $db->lastInsertId()], 201);
    }

    /** GET /groups/:id/read-receipts — return per-member last-read pointer. */
    public static function readReceipts(array $params, int $userId): never
    {
        $groupId = (int) ($params['id'] ?? 0);
        if (!Group::isMember($groupId, $userId)) Response::error('Forbidden', 403);
        Response::json(['receipts' => Group::getReadReceipts($groupId)]);
    }

    /**
     * PATCH /groups/:id/messages/:msgId — edit own group message.
     * Body: { encrypted_content: string }  (re-encrypted with group AES key)
     */
    public static function editMessage(array $params, int $userId): never
    {
        $groupId = (int) ($params['id']    ?? 0);
        $msgId   = (int) ($params['msgId'] ?? 0);

        if (!Group::isMember($groupId, $userId)) Response::error('Forbidden', 403);

        $db   = Database::connection();
        $stmt = $db->prepare('SELECT sender_id FROM messages WHERE id = ? AND group_id = ?');
        $stmt->execute([$msgId, $groupId]);
        $msg = $stmt->fetch();

        if (!$msg) Response::error('Not found', 404);
        if ((int) $msg['sender_id'] !== $userId) Response::error('Forbidden', 403);

        $body             = json_decode(file_get_contents('php://input'), true) ?? [];
        $encryptedContent = (string) ($body['encrypted_content'] ?? '');
        if ($encryptedContent === '') Response::error('encrypted_content is required', 422);

        $db->prepare(
            'UPDATE messages SET encrypted_content = ?, is_edited = 1 WHERE id = ?'
        )->execute([$encryptedContent, $msgId]);

        Response::json(['ok' => true]);
    }

    /**
     * GET /groups/:id/messages[?before_id=Y|&after_id=Y|&around_id=Y]
     * Returns paginated messages + the caller's encrypted_group_key.
     */
    public static function getMessages(array $params, int $userId): never
    {
        $groupId = (int) ($params['id'] ?? 0);
        if (!Group::isMember($groupId, $userId)) Response::error('Forbidden', 403);

        $beforeId = isset($_GET['before_id']) ? (int) $_GET['before_id'] : null;
        $afterId  = isset($_GET['after_id'])  ? (int) $_GET['after_id']  : null;
        $aroundId = isset($_GET['around_id']) ? (int) $_GET['around_id'] : null;

        if ($aroundId !== null) {
            $result = Group::fetchMessagesAround($groupId, $aroundId);
        } elseif ($beforeId !== null) {
            $result = Group::fetchMessagesBefore($groupId, $beforeId);
        } elseif ($afterId !== null) {
            $result = Group::fetchMessagesAfter($groupId, $afterId);
        } else {
            $result = Group::fetchLatestMessages($groupId);
        }

        $result['encrypted_group_key'] = Group::getMyEncryptedKey($groupId, $userId);
        Response::json($result);
    }
}
