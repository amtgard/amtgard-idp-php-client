<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests\Iam;

use Amtgard\IAM\Catalog\ServiceCatalog;
use Amtgard\IdpClient\Iam\ServiceFormatParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServiceFormatParser::class)]
final class ServiceFormatParserTest extends TestCase
{
    public function testDefaultFormat(): void
    {
        $format = ServiceFormatParser::defaultFormat();

        $this->assertSame([
            ServiceCatalog::Configuration,
            ServiceCatalog::Game,
            ServiceCatalog::Kingdom,
            ServiceCatalog::Park,
        ], $format);
    }

    public function testParseNullOrBlankReturnsDefault(): void
    {
        $this->assertSame(ServiceFormatParser::defaultFormat(), ServiceFormatParser::parse(null));
        $this->assertSame(ServiceFormatParser::defaultFormat(), ServiceFormatParser::parse('   '));
    }

    public function testParseJsonArray(): void
    {
        $format = ServiceFormatParser::parse('["Configuration","Kingdom"]');

        $this->assertSame([ServiceCatalog::Configuration, ServiceCatalog::Kingdom], $format);
        $this->assertSame(['Configuration', 'Kingdom'], ServiceFormatParser::slotNames($format));
    }

    public function testParseList(): void
    {
        $format = ServiceFormatParser::parseList(['tenant-id', 'Kingdom']);

        $this->assertSame(['tenant-id', ServiceCatalog::Kingdom], $format);
        $this->assertSame(['tenant-id', 'Kingdom'], ServiceFormatParser::slotNames($format));
    }

    public function testParseRejectsNonArrayJson(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ServiceFormatParser::parse('{"not":"list"}');
    }

    public function testParseRejectsEmptyEntries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ServiceFormatParser::parse('["Configuration",""]');
    }

    public function testParseRejectsNonStringEntries(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ServiceFormatParser::parse('[1]');
    }

    public function testParseRejectsEmptyArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ServiceFormatParser::parse('[]');
    }
}
