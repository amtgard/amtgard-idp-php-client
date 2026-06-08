<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests\Support;

final class Fixtures
{
    public static function read(string $relativePath): string
    {
        $path = __DIR__ . '/../Fixtures/' . $relativePath;
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Fixture not found: {$relativePath}");
        }

        return $contents;
    }
}
