<?php
// api/tests/AuthControllerTest.php
namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests — require securechat_test MySQL database with schema applied.
 * Apply schema: mysql -u root securechat_test < schema.sql
 */
class AuthControllerTest extends TestCase
{
    private static \PDO $db;

    public static function setUpBeforeClass(): void
    {
        putenv('DB_NAME=securechat_test');
        \Database::reset();
        self::$db = \Database::connection();
        self::$db->exec('DELETE FROM sessions');
        self::$db->exec('DELETE FROM users');
    }

    protected function tearDown(): void
    {
        self::$db->exec('DELETE FROM sessions');
        self::$db->exec('DELETE FROM users');
    }

    public function test_check_returns_false_for_unknown_email(): void
    {
        \AuthController::$testInput = json_encode(['email' => 'nobody@test.com']);
        ob_start();
        (new \AuthController())->check([]);
        $out = json_decode(ob_get_clean(), true);
        $this->assertFalse($out['exists']);
    }

    public function test_register_creates_user_and_returns_token(): void
    {
        \AuthController::$testInput = json_encode([
            'email'      => 'alice@test.com',
            'name'       => 'Alice',
            'password'   => 'secret123',
            'public_key' => '-----BEGIN PUBLIC KEY-----\nfakekey\n-----END PUBLIC KEY-----',
        ]);
        ob_start();
        (new \AuthController())->register([]);
        $out = json_decode(ob_get_clean(), true);
        $this->assertArrayHasKey('token', $out);
        $this->assertCount(3, explode('.', $out['token']));
    }

    public function test_check_returns_true_after_registration(): void
    {
        \User::create('bob@test.com', 'Bob', password_hash('pass123', PASSWORD_BCRYPT), 'pubkey');
        \AuthController::$testInput = json_encode(['email' => 'bob@test.com']);
        ob_start();
        (new \AuthController())->check([]);
        $out = json_decode(ob_get_clean(), true);
        $this->assertTrue($out['exists']);
    }

    public function test_login_returns_token_for_valid_credentials(): void
    {
        \User::create('carol@test.com', 'Carol', password_hash('mypassword', PASSWORD_BCRYPT), 'pubkey');
        \AuthController::$testInput = json_encode(['email' => 'carol@test.com', 'password' => 'mypassword']);
        ob_start();
        (new \AuthController())->login([]);
        $out = json_decode(ob_get_clean(), true);
        $this->assertArrayHasKey('token', $out);
    }

    public function test_login_fails_with_wrong_password(): void
    {
        \User::create('dave@test.com', 'Dave', password_hash('rightpass', PASSWORD_BCRYPT), 'pubkey');
        \AuthController::$testInput = json_encode(['email' => 'dave@test.com', 'password' => 'wrongpass']);
        ob_start();
        (new \AuthController())->login([]);
        $out = json_decode(ob_get_clean(), true);
        $this->assertArrayHasKey('error', $out);
    }

    public function test_register_rejects_short_password(): void
    {
        \AuthController::$testInput = json_encode([
            'email' => 'eve@test.com', 'name' => 'Eve',
            'password' => '123', 'public_key' => 'pubkey',
        ]);
        ob_start();
        (new \AuthController())->register([]);
        $out = json_decode(ob_get_clean(), true);
        $this->assertArrayHasKey('error', $out);
    }

    public function test_register_rejects_duplicate_email(): void
    {
        \User::create('frank@test.com', 'Frank', password_hash('pass123', PASSWORD_BCRYPT), 'pubkey');
        \AuthController::$testInput = json_encode([
            'email' => 'frank@test.com', 'name' => 'Frank2',
            'password' => 'pass123456', 'public_key' => 'pubkey',
        ]);
        ob_start();
        (new \AuthController())->register([]);
        $out = json_decode(ob_get_clean(), true);
        $this->assertArrayHasKey('error', $out);
    }
}
