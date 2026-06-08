<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests\Slim;

use Amtgard\IdpClient\Slim\SessionMiddleware;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(SessionMiddleware::class)]
final class SessionMiddlewareTest extends TestCase
{
    public function testStartsSessionAndDelegates(): void
    {
        @session_start();
        session_write_close();

        $middleware = new SessionMiddleware();
        $psr17 = new Psr17Factory();
        $handler = new class ($psr17) implements RequestHandlerInterface {
            public function __construct(private Psr17Factory $psr17) {}

            public function handle(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
            {
                return $this->psr17->createResponse(200);
            }
        };

        $response = $middleware->process(new ServerRequest('GET', '/'), $handler);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
    }
}
