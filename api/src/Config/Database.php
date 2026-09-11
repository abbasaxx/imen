<?php
// api/src/Config/Database.php
declare(strict_types=1);

class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }
        $host    = getenv('DB_HOST') ?: 'localhost';
        $name    = getenv('DB_NAME') ?: 'securechat';
        $user    = getenv('DB_USER') ?: 'root';
        $pass    = getenv('DB_PASS') ?: '';
        self::$instance = new PDO(
            "mysql:host=$host;dbname=$name;charset=utf8mb4",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]
        );
        self::$instance->exec("SET time_zone = '+00:00'");
        return self::$instance;
    }

    /** Reset singleton — used in tests only */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
