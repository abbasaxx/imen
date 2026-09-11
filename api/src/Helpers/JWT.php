<?php
// api/src/Helpers/JWT.php
declare(strict_types=1);

class JWT
{
    private static string $secret = '';

    public static function init(): void
    {
        self::$secret = getenv('JWT_SECRET') ?: 'change-me-in-production-32chars!!';
    }

    public static function encode(array $payload): string
    {
        $header  = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload['iat'] = time();
        $payload['exp'] = time() + (86400 * 30); // 30 days
        $payload['jti'] = bin2hex(random_bytes(16));
        $body = self::b64(json_encode($payload));
        $sig  = self::b64(hash_hmac('sha256', "$header.$body", self::$secret, true));
        return "$header.$body.$sig";
    }

    public static function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException('Malformed token');
        }
        [$header, $body, $sig] = $parts;
        $expected = self::b64(hash_hmac('sha256', "$header.$body", self::$secret, true));
        if (!hash_equals($expected, $sig)) {
            throw new \InvalidArgumentException('Invalid signature');
        }
        $data = json_decode(self::b64decode($body), true);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Malformed payload');
        }
        if ($data['exp'] < time()) {
            throw new \InvalidArgumentException('Token expired');
        }
        return $data;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64decode(string $data): string
    {
        $pad = str_repeat('=', (4 - strlen($data) % 4) % 4);
        return base64_decode(strtr($data . $pad, '-_', '+/'));
    }
}

JWT::init();
