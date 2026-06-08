<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

final class ThrowingHttpClient implements ClientInterface
{
    public function sendRequest(RequestInterface $request): never
    {
        throw new class ('connection reset') extends \RuntimeException implements ClientExceptionInterface {};
    }
}
