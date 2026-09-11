<?php
// api/tests/RouterTest.php
namespace Tests;

use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    private function compile(\Router $router, string $path): array
    {
        $ref = new \ReflectionMethod(\Router::class, 'compile');
        return $ref->invoke($router, $path);
    }

    public function test_compiles_static_route(): void
    {
        $router = new \Router();
        [$pattern, $params] = $this->compile($router, '/auth/login');
        $this->assertSame([], $params);
        $this->assertMatchesRegularExpression($pattern, '/auth/login');
    }

    public function test_compiles_parameterized_route(): void
    {
        $router = new \Router();
        [$pattern, $params] = $this->compile($router, '/conversations/:id/messages');
        $this->assertSame(['id'], $params);
        $this->assertMatchesRegularExpression($pattern, '/conversations/42/messages');
        $this->assertDoesNotMatchRegularExpression($pattern, '/conversations/messages');
    }

    public function test_static_route_does_not_match_parameterized_path(): void
    {
        $router = new \Router();
        [$pattern] = $this->compile($router, '/auth/login');
        $this->assertDoesNotMatchRegularExpression($pattern, '/auth/login/extra');
    }
}
