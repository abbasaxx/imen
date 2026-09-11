<?php
// api/src/Controllers/UserController.php
declare(strict_types=1);

class UserController
{
    /**
     * PATCH /profile
     * Body: { name: string }
     */
    public static function updateProfile(array $params, int $userId): never
    {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = trim((string) ($body['name'] ?? ''));

        if ($name === '') Response::error('name is required', 422);
        if (mb_strlen($name) > 100) Response::error('name too long', 422);

        User::updateName($userId, $name);
        $user = User::findById($userId);
        Response::json(['user' => $user]);
    }

    /** GET /users/search?q=<email fragment> — search users by email (authenticated). */
    public static function search(array $params, int $userId): never
    {
        $q = trim($_GET['q'] ?? '');

        if (mb_strlen($q) < 2) {
            Response::error('Query must be at least 2 characters', 422);
        }

        $like = '%' . $q . '%';
        $stmt = Database::connection()->prepare(
            'SELECT id, name, email, public_key
             FROM   users
             WHERE  (email LIKE ? OR name LIKE ?) AND id != ?
             LIMIT  10'
        );
        $stmt->execute([$like, $like, $userId]);
        $users = $stmt->fetchAll();

        Response::json(['users' => $users]);
    }
}
