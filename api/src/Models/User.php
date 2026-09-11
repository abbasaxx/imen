<?php
// api/src/Models/User.php
declare(strict_types=1);

class User
{
    public static function findByEmail(string $email): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, email, name, password_hash, public_key FROM users WHERE email = ?'
        );
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, email, name, public_key FROM users WHERE id = ?'
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function updateName(int $id, string $name): void
    {
        Database::connection()->prepare(
            'UPDATE users SET name = ? WHERE id = ?'
        )->execute([$name, $id]);
    }

    public static function create(string $email, string $name, string $passwordHash, string $publicKey): int
    {
        $stmt = Database::connection()->prepare(
            'INSERT INTO users (email, name, password_hash, public_key) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$email, $name, $passwordHash, $publicKey]);
        return (int) Database::connection()->lastInsertId();
    }
}
