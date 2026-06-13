<?php

declare(strict_types=1);

namespace Amtgard\IdpClient\Tests\Iam;

use Amtgard\IAM\OrkServices;
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
            OrkServices::Configuration,
            OrkServices::Game,
            OrkServices::Kingdom,
            OrkServices::Park,
        ], $format);
    }

    public function testParseJsonArray(): void
    {
        $format = ServiceFormatParser::parse('["Configuration","Kingdom"]');

        $this->assertSame([OrkServices::Configuration, OrkServices::Kingdom], $format);
        $this->assertSame(['Configuration', 'Kingdom'], ServiceFormatParser::slotNames($format));
    }

    public function testParseList(): void
    {
        $format = ServiceFormatParser::parseList(['tenant-id', 'Kingdom']);

        $this->assertSame(['tenant-id', OrkServices::Kingdom], $format);
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
}
