<?php
// api/src/Models/Session.php
declare(strict_types=1);

class Session
{
    public static function block(int $userId, string $jti, int $exp): void
    {
        $stmt = Database::connection()->prepare(
            'INSERT IGNORE INTO sessions (user_id, jwt_jti, expires_at) VALUES (?, ?, FROM_UNIXTIME(?))'
        );
        $stmt->execute([$userId, $jti, $exp]);
    }

    public static function isBlocked(string $jti): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT id FROM sessions WHERE jwt_jti = ?'
        );
        $stmt->execute([$jti]);
        return (bool) $stmt->fetch();
    }
}
