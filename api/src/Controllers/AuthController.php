<?php
// api/src/Controllers/AuthController.php
declare(strict_types=1);

class AuthController
{
    /** Overridden in tests to bypass php://input */
    public static ?string $testInput = null;

    private function body(): array
    {
        $raw = self::$testInput ?? file_get_contents('php://input');
        self::$testInput = null;
        return json_decode($raw, true) ?? [];
    }

    public function check(array $params): void
    {
        $body  = $this->body();
        $email = trim($body['email'] ?? '');
        if (!$email) Response::error('email required');
        Response::json(['exists' => User::findByEmail($email) !== null]);
    }

    public function register(array $params): void
    {
        $body      = $this->body();
        $email     = trim($body['email'] ?? '');
        $name      = trim($body['name'] ?? '');
        $password  = $body['password'] ?? '';
        $publicKey = $body['public_key'] ?? '';

        if (!$email || !$name || !$password || !$publicKey) {
            Response::error('All fields required');
        }
        if (strlen($password) < 6) {
            Response::error('Password must be at least 6 characters');
        }
        if (User::findByEmail($email)) {
            Response::error('Email already registered', 409);
        }

        $hash   = password_hash($password, PASSWORD_BCRYPT);
        $userId = User::create($email, $name, $hash, $publicKey);
        $token  = JWT::encode(['sub' => $userId]);
        Response::json([
            'token' => $token,
            'user'  => [
                'id'         => $userId,
                'name'       => $name,
                'email'      => $email,
                'public_key' => $publicKey,
            ],
        ], 201);
    }

    public function login(array $params): void
    {
        $body     = $this->body();
        $email    = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';

        if (!$email || !$password) {
            Response::error('email and password required');
        }
        $user = User::findByEmail($email);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            Response::error('Invalid credentials', 401);
        }

        $token = JWT::encode(['sub' => $user['id']]);
        Response::json([
            'token' => $token,
            'user'  => [
                'id'         => $user['id'],
                'name'       => $user['name'],
                'email'      => $user['email'],
                'public_key' => $user['public_key'],
            ],
        ]);
    }

    public function logout(array $params, int $userId): void
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($header, 'Bearer ')) {
            Response::error('Unauthorized', 401);
        }
        try {
            $payload = JWT::decode(substr($header, 7));
        } catch (\InvalidArgumentException) {
            Response::error('Unauthorized', 401);
        }
        Session::block($userId, $payload['jti'], $payload['exp']);
        Response::json(['ok' => true]);
    }
}
