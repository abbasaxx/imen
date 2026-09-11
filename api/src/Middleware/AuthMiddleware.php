<?php
// api/src/Middleware/AuthMiddleware.php
declare(strict_types=1);

class AuthMiddleware
{
    public static function authenticate(): int
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!str_starts_with($header, 'Bearer ')) {
            Response::error('Unauthorized', 401);
        }
        $token = substr($header, 7);
        try {
            $payload = JWT::decode($token);
        } catch (\InvalidArgumentException) {
            Response::error('Unauthorized', 401);
        }
        if (Session::isBlocked($payload['jti'])) {
            Response::error('Unauthorized', 401);
        }
        $userId = (int) $payload['sub'];
        if (!User::findById($userId)) {
            Response::error('Unauthorized', 401);
        }
        return $userId;
    }
}
