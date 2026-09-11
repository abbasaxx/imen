<?php
// api/tests/JWTTest.php
namespace Tests;

use PHPUnit\Framework\TestCase;

class JWTTest extends TestCase
{
    public function test_encode_returns_three_part_token(): void
    {
        $token = \JWT::encode(['sub' => 1]);
        $this->assertCount(3, explode('.', $token));
    }

    public function test_decode_recovers_payload(): void
    {
        $token = \JWT::encode(['sub' => 42]);
        $payload = \JWT::decode($token);
        $this->assertSame(42, $payload['sub']);
    }

    public function test_decode_fails_on_tampered_token(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $token = \JWT::encode(['sub' => 1]);
        $parts = explode('.', $token);
        $parts[1] = base64_encode(json_encode(['sub' => 999]));
        \JWT::decode(implode('.', $parts));
    }

    public function test_decode_fails_on_expired_token(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $secret = getenv('JWT_SECRET') ?: 'change-me-in-production-32chars!!';
        $header = rtrim(strtr(base64_encode(json_encode(['alg'=>'HS256','typ'=>'JWT'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode(['sub'=>1,'iat'=>1,'exp'=>2,'jti'=>'x'])), '+/', '-_'), '=');
        $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', "$header.$payload", $secret, true)), '+/', '-_'), '=');
        \JWT::decode("$header.$payload.$sig");
    }

    public function test_encode_includes_jti(): void
    {
        $token = \JWT::encode(['sub' => 1]);
        $payload = \JWT::decode($token);
        $this->assertArrayHasKey('jti', $payload);
        $this->assertNotEmpty($payload['jti']);
    }

    public function test_decode_fails_on_malformed_body(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        // Craft a token with a valid-looking header + invalid JSON body
        $secret  = getenv('JWT_SECRET') ?: 'change-me-in-production-32chars!!';
        $header  = rtrim(strtr(base64_encode(json_encode(['alg'=>'HS256','typ'=>'JWT'])), '+/', '-_'), '=');
        $body    = rtrim(strtr(base64_encode('not-json!!!'), '+/', '-_'), '=');
        $sig     = rtrim(strtr(base64_encode(hash_hmac('sha256', "$header.$body", $secret, true)), '+/', '-_'), '=');
        \JWT::decode("$header.$body.$sig");
    }
}
