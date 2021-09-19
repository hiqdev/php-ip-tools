<?php
declare(strict_types=1);

namespace hiqdev\IpTools\Tests\Unit;

use Generator;
use hiqdev\IpTools\Exception;
use hiqdev\IpTools\IpRangeParser;
use PHPUnit\Framework\TestCase;

/**
 * Class IpRangeParserTest
 *
 * @author Dmytro Naumenko <d.naumenko.a@gmail.com>
 * @covers \hiqdev\IpTools\IpRangeParser
 */
class IpRangeParserTest extends TestCase
{
    /**
     * @param string $range
     * @param string[] $expected
     * @dataProvider rangesDataProvider
     */
    public function testRangeParsing(string $range, array $expected): void
    {
        $family = str_contains($range, ':') ? 6 : 4;

        $result = IpRangeParser::fromString($range);
        $resultWithFamily = IpRangeParser::fromString($range, $family);

        $this->assertSame($expected, iterator_to_array($result));
        $this->assertSame($expected, iterator_to_array($resultWithFamily));
    }

    public function rangesDataProvider(): Generator
    {
        yield [
            '192.0.2.[1,2,100-102]/24',
            ['192.0.2.1/24', '192.0.2.2/24', '192.0.2.100/24', '192.0.2.101/24', '192.0.2.102/24']
        ];

        yield [
            '192.0.2.1',
            ['192.0.2.1']
        ];

        yield [
            '192.0.[1,2].[1,2,100-102]/24',
            [
                '192.0.1.1/24', '192.0.1.2/24', '192.0.1.100/24', '192.0.1.101/24', '192.0.1.102/24',
                '192.0.2.1/24', '192.0.2.2/24', '192.0.2.100/24', '192.0.2.101/24', '192.0.2.102/24',
            ]
        ];
        yield [
            '192.0.0.[1,2]/24',
            ['192.0.0.1/24', '192.0.0.2/24']
        ];
        yield [
            '2001:db8:0:[0,fd-ff]::/64',
            ['2001:db8:0:0::/64', '2001:db8:0:fd::/64', '2001:db8:0:fe::/64', '2001:db8:0:ff::/64']
        ];
    }

    public function testInvalidIdVersionIsProtected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid IP family version');

        $range = IpRangeParser::fromString('192.0.0.[1,2]/24', 2);
        $range->valid();
    }
}
