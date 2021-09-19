<?php

declare(strict_types=1);

namespace hiqdev\IpTools\Tests\Unit;

use Closure;
use Generator;
use hiqdev\IpTools\Exception;
use hiqdev\IpTools\IpBlocksCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Class IpBlocksCalculatorTest
 *
 * @author Dmytro Naumenko <d.naumenko.a@gmail.com>
 * @covers \hiqdev\IpTools\IpBlocksCalculator
 */
class IpBlocksCalculatorTest extends TestCase
{
    protected IpBlocksCalculator $calculator;

    public function setUp(): void
    {
        parent::setUp();

        if (@inet_pton('::1') === false) {
            $this->markTestSkipped('IPv6 is not supported in current test suite');
        }

        $this->calculator = new IpBlocksCalculator();
    }

    /**
     * @dataProvider rangesDataProvider
     * @testdox Testing $description
     */
    public function testRanges(
        string $description,
        string $cidr,
        array $takenCidrs,
        array $expectedResult,
        Closure $configure = null
    ): void {
        $calculator = clone $this->calculator;
        if ($configure !== null) {
            $calculator = $configure($calculator);
        }

        $freeCidr = $calculator->__invoke($cidr, $takenCidrs);

        $this->assertSame($expectedResult, $freeCidr);
    }

    public function rangesDataProvider(): Generator
    {
        yield [
            'Taken /28 inside /24',
            '192.168.0.0/24',
            [
                '192.168.0.64/28',
            ],
            [
                '192.168.0.0/26',
                '192.168.0.80/28',
                '192.168.0.96/27',
                '192.168.0.128/25',
            ],
        ];

        yield [
            'Overlapping taken blocks #1',
            '10.0.1.0/23',
            [
                '10.0.1.0/28',
                '10.0.1.0/24',
                '10.0.1.0/25',
            ],
            [
                '10.0.0.0/24',
            ],
        ];

        yield [
            'Overlapping taken blocks #2',
            '10.0.1.0/23',
            [
                '10.0.1.0/24',
                '10.0.1.0/28',
                '10.0.1.0/25',
            ],
            [
                '10.0.0.0/24',
            ],
        ];

        yield [
            'Overlapping taken blocks #3',
            '10.0.1.0/23',
            [
                '10.0.1.0/24',
                '10.0.1.0/28',
            ],
            [
                '10.0.0.0/24',
            ],
        ];

        yield [
            'Empty root CIDR splitting test #1',
            '192.168.0.0/24',
            [],
            [
                '192.168.0.0/25',
                '192.168.0.128/25',
            ],
            function (IpBlocksCalculator $calculator) {
                return $calculator->withSplitEmptyRootCidrBySubBlockPrefix(1);
            },
        ];
        yield [
            'Empty root CIDR splitting test #2',
            '192.168.0.0/24',
            [],
            [
                '192.168.0.0/26',
                '192.168.0.64/26',
                '192.168.0.128/26',
                '192.168.0.192/26',
            ],
            function (IpBlocksCalculator $calculator) {
                return $calculator->withSplitEmptyRootCidrBySubBlockPrefix(2);
            },
        ];

        yield [
            'A block of a single IP address',
            '192.168.0.0/32',
            ['192.168.0.0/32'],
            [],
            function (IpBlocksCalculator $calculator) {
                return $calculator->withSplitEmptyRootCidrBySubBlockPrefix(2);
            },
        ];

        yield [
            'Single occupied IP inside a block',
            '192.168.0.128/25',
            ['192.168.0.129/32'],
            [
                '192.168.0.128/32',
                '192.168.0.130/31',
                '192.168.0.132/30',
                '192.168.0.136/29',
                '192.168.0.144/28',
                '192.168.0.160/27',
                '192.168.0.192/26',
            ],
        ];

        yield [
            'A few occupied prefixes inside a block',
            '192.168.0.128/25',
            [
                '192.168.0.128/29',
                '192.168.0.137/29',
            ],
            [
                '192.168.0.144/28',
                '192.168.0.160/27',
                '192.168.0.192/26',
            ],
        ];

        yield [
            'Test order does not affect a result',
            '192.168.0.128/25',
            [
                '192.168.0.137/29',
                '192.168.0.128/29',
                '192.168.0.160/28',
            ],
            [
                '192.168.0.144/28',
                '192.168.0.176/28',
                '192.168.0.192/26',
            ],
        ];

        yield [
            'An occupied range is not in the passed block',
            '192.168.0.0/25',
            ['10.0.0.0/21'],
            ['192.168.0.0/25'],
        ];

        yield [
            'An occupied range is greater than the passed block',
            '192.168.0.0/25',
            ['192.168.0.0/24'],
            ['192.168.0.0/25'],
        ];

        yield [
            'IPv6 simple test',
            '2a02:b48::/29',
            [
                '2a02:b48::/32',
                '2a02:b49::/32',
                '2a02:b4a::/32',
                '2a02:b4b::/32',
            ],
            [
                '2a02:b4c::/30',
            ],
        ];

        yield [
            'Free IPv6 block',
            '2a02:b48::/29',
            [],
            ['2a02:b48::/29'],
        ];

        yield [
            'Split free IPv6 block by 4',
            '2a02:b48::/29',
            [],
            [
                '2a02:b48::/31',
                '2a02:b4a::/31',
                '2a02:b4c::/31',
                '2a02:b4e::/31',
            ],
            function (IpBlocksCalculator $calculator) {
                return $calculator->withSplitEmptyRootCidrBySubBlockPrefix(2);
            },
        ];

        yield [
            'Suggest only free IP adresses, not blocks',
            '10.2.4.0/28',
            [
                '10.2.4.2',
                '10.2.4.3',
                '10.2.4.4',
                '10.2.4.9',
                '10.2.4.11',
            ],
            [
                '10.2.4.0/32',
                '10.2.4.1/32',
                '10.2.4.5/32',
                '10.2.4.6/32',
                '10.2.4.7/32',
                '10.2.4.8/32',
                '10.2.4.10/32',
                '10.2.4.12/32',
                '10.2.4.13/32',
                '10.2.4.14/32',
                '10.2.4.15/32',
            ],
            function (IpBlocksCalculator $calculator) {
                return $calculator->withMinimumPrefixLength(32);
            },
        ];

        yield [
            'A lot of occupied prefixes inside a block',
            '10.2.4.0/23',
            [
                '10.2.4.0/29',
                '10.2.4.8/29',
                '10.2.4.16/29',
                '10.2.4.24/29',
                '10.2.4.32/29',
                '10.2.4.40/29',
                '10.2.4.48/29',
                '10.2.4.56/29',
                '10.2.4.64/29',
                '10.2.4.72/29',
                '10.2.4.80/29',
                '10.2.4.88/29',
                '10.2.4.96/29',
                '10.2.4.104/29',
                '10.2.4.112/29',
                '10.2.4.120/29',
                '10.2.4.128/29',
                '10.2.4.136/29',
                '10.2.4.144/29',
                '10.2.4.152/29',
                '10.2.4.160/29',
                '10.2.4.168/29',
                '10.2.4.176/29',
                '10.2.4.184/29',
                '10.2.4.192/29',
                '10.2.4.200/29',
                '10.2.4.208/29',
                '10.2.4.216/29',
                '10.2.4.224/29',
                '10.2.4.232/29',
                '10.2.4.240/29',
                '10.2.4.248/29',
                '10.2.5.0/29',
                '10.2.5.8/29',
                '10.2.5.16/29',
                '10.2.5.24/29',
                '10.2.5.32/29',
                '10.2.5.40/29',
                '10.2.5.48/29',
                '10.2.5.56/29',
                '10.2.5.64/29',
                '10.2.5.72/29',
                '10.2.5.80/29',
                '10.2.5.88/29',
                '10.2.5.96/29',
                '10.2.5.104/29',
                '10.2.5.112/29',
            ],
            [
                '10.2.5.120/29',
                '10.2.5.128/25',
            ],
        ];

        yield [
            'IPv6 /48 prefix has a /80 inside of it',
            '2a0d:7c40:1001::/48',
            [
                '2a0d:7c40:1001:0:0100::/80',
            ],
            [
                '2a0d:7c40:1001::/72',
                '2a0d:7c40:1001:0:101::/80',
                '2a0d:7c40:1001:0:102::/79',
                '2a0d:7c40:1001:0:104::/78',
                '2a0d:7c40:1001:0:108::/77',
                '2a0d:7c40:1001:0:110::/76',
                '2a0d:7c40:1001:0:120::/75',
                '2a0d:7c40:1001:0:140::/74',
                '2a0d:7c40:1001:0:180::/73',
                '2a0d:7c40:1001:0:200::/71',
                '2a0d:7c40:1001:0:400::/70',
                '2a0d:7c40:1001:0:800::/69',
                '2a0d:7c40:1001:0:1000::/68',
                '2a0d:7c40:1001:0:2000::/67',
                '2a0d:7c40:1001:0:4000::/66',
                '2a0d:7c40:1001:0:8000::/65',
                '2a0d:7c40:1001:1::/64',
                '2a0d:7c40:1001:2::/63',
                '2a0d:7c40:1001:4::/62',
                '2a0d:7c40:1001:8::/61',
                '2a0d:7c40:1001:10::/60',
                '2a0d:7c40:1001:20::/59',
                '2a0d:7c40:1001:40::/58',
                '2a0d:7c40:1001:80::/57',
                '2a0d:7c40:1001:100::/56',
                '2a0d:7c40:1001:200::/55',
                '2a0d:7c40:1001:400::/54',
                '2a0d:7c40:1001:800::/53',
                '2a0d:7c40:1001:1000::/52',
                '2a0d:7c40:1001:2000::/51',
                '2a0d:7c40:1001:4000::/50',
                '2a0d:7c40:1001:8000::/49',
            ],
        ];

        yield [
            'IPv6 /48 prefix has a single IP inside of it occupied',
            '2a0d:7c40:1001::/48',
            [
                '2a0d:7c40:1001:0:0100::',
            ],
            [
                '2a0d:7c40:1001::/72',
                '2a0d:7c40:1001:0:100::1/128',
                '2a0d:7c40:1001:0:100::2/127',
                '2a0d:7c40:1001:0:100::4/126',
                '2a0d:7c40:1001:0:100::8/125',
                '2a0d:7c40:1001:0:100::10/124',
                '2a0d:7c40:1001:0:100::20/123',
                '2a0d:7c40:1001:0:100::40/122',
                '2a0d:7c40:1001:0:100::80/121',
                '2a0d:7c40:1001:0:100::100/120',
                '2a0d:7c40:1001:0:100::200/119',
                '2a0d:7c40:1001:0:100::400/118',
                '2a0d:7c40:1001:0:100::800/117',
                '2a0d:7c40:1001:0:100::1000/116',
                '2a0d:7c40:1001:0:100::2000/115',
                '2a0d:7c40:1001:0:100::4000/114',
                '2a0d:7c40:1001:0:100::8000/113',
                '2a0d:7c40:1001:0:100:0:1:0/112',
                '2a0d:7c40:1001:0:100:0:2:0/111',
                '2a0d:7c40:1001:0:100:0:4:0/110',
                '2a0d:7c40:1001:0:100:0:8:0/109',
                '2a0d:7c40:1001:0:100:0:10:0/108',
                '2a0d:7c40:1001:0:100:0:20:0/107',
                '2a0d:7c40:1001:0:100:0:40:0/106',
                '2a0d:7c40:1001:0:100:0:80:0/105',
                '2a0d:7c40:1001:0:100:0:100:0/104',
                '2a0d:7c40:1001:0:100:0:200:0/103',
                '2a0d:7c40:1001:0:100:0:400:0/102',
                '2a0d:7c40:1001:0:100:0:800:0/101',
                '2a0d:7c40:1001:0:100:0:1000:0/100',
                '2a0d:7c40:1001:0:100:0:2000:0/99',
                '2a0d:7c40:1001:0:100:0:4000:0/98',
                '2a0d:7c40:1001:0:100:0:8000:0/97',
                '2a0d:7c40:1001:0:100:1::/96',
                '2a0d:7c40:1001:0:100:2::/95',
                '2a0d:7c40:1001:0:100:4::/94',
                '2a0d:7c40:1001:0:100:8::/93',
                '2a0d:7c40:1001:0:100:10::/92',
                '2a0d:7c40:1001:0:100:20::/91',
                '2a0d:7c40:1001:0:100:40::/90',
                '2a0d:7c40:1001:0:100:80::/89',
                '2a0d:7c40:1001:0:100:100::/88',
                '2a0d:7c40:1001:0:100:200::/87',
                '2a0d:7c40:1001:0:100:400::/86',
                '2a0d:7c40:1001:0:100:800::/85',
                '2a0d:7c40:1001:0:100:1000::/84',
                '2a0d:7c40:1001:0:100:2000::/83',
                '2a0d:7c40:1001:0:100:4000::/82',
                '2a0d:7c40:1001:0:100:8000::/81',
                '2a0d:7c40:1001:0:101::/80',
                '2a0d:7c40:1001:0:102::/79',
                '2a0d:7c40:1001:0:104::/78',
                '2a0d:7c40:1001:0:108::/77',
                '2a0d:7c40:1001:0:110::/76',
                '2a0d:7c40:1001:0:120::/75',
                '2a0d:7c40:1001:0:140::/74',
                '2a0d:7c40:1001:0:180::/73',
                '2a0d:7c40:1001:0:200::/71',
                '2a0d:7c40:1001:0:400::/70',
                '2a0d:7c40:1001:0:800::/69',
                '2a0d:7c40:1001:0:1000::/68',
                '2a0d:7c40:1001:0:2000::/67',
                '2a0d:7c40:1001:0:4000::/66',
                '2a0d:7c40:1001:0:8000::/65',
                '2a0d:7c40:1001:1::/64',
                '2a0d:7c40:1001:2::/63',
                '2a0d:7c40:1001:4::/62',
                '2a0d:7c40:1001:8::/61',
                '2a0d:7c40:1001:10::/60',
                '2a0d:7c40:1001:20::/59',
                '2a0d:7c40:1001:40::/58',
                '2a0d:7c40:1001:80::/57',
                '2a0d:7c40:1001:100::/56',
                '2a0d:7c40:1001:200::/55',
                '2a0d:7c40:1001:400::/54',
                '2a0d:7c40:1001:800::/53',
                '2a0d:7c40:1001:1000::/52',
                '2a0d:7c40:1001:2000::/51',
                '2a0d:7c40:1001:4000::/50',
                '2a0d:7c40:1001:8000::/49',
            ]
        ];

        yield [
            'Test the whole block is occupied',
            '88.208.0.0/18',
            [
                '88.208.0.0/21',
                '88.208.8.0/24',
                '88.208.9.0/24',
                '88.208.10.0/24',
                '88.208.11.0/24',
                '88.208.12.0/26',
                '88.208.12.64/26',
                '88.208.12.128/26',
                '88.208.12.192/26',
                '88.208.13.0/24',
                '88.208.14.0/24',
                '88.208.15.0/24',
                '88.208.16.0/22',
                '88.208.20.0/24',
                '88.208.21.0/24',
                '88.208.22.0/23',
                '88.208.24.0/24',
                '88.208.25.0/24',
                '88.208.26.0/23',
                '88.208.28.0/23',
                '88.208.30.0/24',
                '88.208.31.0/24',
                '88.208.32.0/21',
                '88.208.40.0/21',
                '88.208.48.0/22',
                '88.208.52.0/22',
                '88.208.56.0/21',
            ],
            [],
        ];

        yield [
            'Test occupied IP addresses do not require mask',
            '192.168.17.0/24',
            [
                '192.168.17.5',
                '192.168.17.232',
            ],
            [
                '192.168.17.0/30',
                '192.168.17.4/32',
                '192.168.17.6/31',
                '192.168.17.8/29',
                '192.168.17.16/28',
                '192.168.17.32/27',
                '192.168.17.64/26',
                '192.168.17.128/26',
                '192.168.17.192/27',
                '192.168.17.224/29',
                '192.168.17.233/32',
                '192.168.17.234/31',
                '192.168.17.236/30',
                '192.168.17.240/28',
            ],
        ];

        yield [
            'A single occupied IP address',
            '192.168.17.0/24',
            [
                '192.168.17.5',
            ],
            [
                '192.168.17.0/30',
                '192.168.17.4/32',
                '192.168.17.6/31',
                '192.168.17.8/29',
                '192.168.17.16/28',
                '192.168.17.32/27',
                '192.168.17.64/26',
                '192.168.17.128/25',
            ],
        ];
    }

    /**
     * @param string $cidr
     * @param array $takenCidrs
     * @param string $expectedExceptionMessage
     *
     * @dataProvider wrongInputProvider
     */
    public function testFailureInputs(string $cidr, array $takenCidrs, string $expectedExceptionMessage): void
    {
        $this->expectExceptionMessage($expectedExceptionMessage);
        $freeCidr = $this->calculator->__invoke($cidr, $takenCidrs);
    }

    public function wrongInputProvider()
    {
        yield [
            'foobar',
            [],
            'foobar does not appear to be an IPv4 or IPv6 block',
        ];

        yield [
            '127.0.0.1/24',
            ['foobar'],
            'foobar does not appear to be an IPv4 or IPv6 block',
        ];

        yield [
            '127.0.0.1/24',
            ['cafe:babe::/64'],
            'Address "cafe:babe::/64" is not a valid address',
        ];

        yield [
            'cafe:babe::/64',
            ['127.0.0.1/32'],
            'Address "127.0.0.1/32" is not a valid address',
        ];

        yield [
            'cafe:babe::/64',
            ['127.0.0.1/32'],
            'Address "127.0.0.1/32" is not a valid address',
        ];

        yield [
            '192.168.0.0',
            [],
            'Address "192.168.0.0" MUST be in CIDR notation',
        ];
    }

    public function minimumPrefixLengthDataProvider(): Generator
    {
        yield [33, 128, 'IPv4 prefix length "33" is not valid'];
        yield [0, 128, 'IPv4 prefix length "0" is not valid'];
        yield [32, 133, 'IPv6 prefix length "133" is not valid'];
        yield [32, 0, 'IPv6 prefix length "0" is not valid'];
    }

    /**
     * @dataProvider minimumPrefixLengthDataProvider
     */
    public function testWithMinimumPrefixLengthValidation($ipv4, $ipv6, $expectedException): void
    {
        $calc = clone $this->calculator;
        $this->expectException(Exception::class);
        $this->expectExceptionMessage($expectedException);
        $calc->withMinimumPrefixLength($ipv4, $ipv6);
    }

    public function testOptionsDoNotModifyObject(): void
    {
        $calculator = new IpBlocksCalculator();
        $newCalculator = $calculator->withSplitEmptyRootCidrBySubBlockPrefix(10);
        $this->assertNotSame($calculator, $newCalculator);

        $calculator = new IpBlocksCalculator();
        $newCalculator = $calculator->withMinimumPrefixLength(16, 64);
        $this->assertNotSame($calculator, $newCalculator);
    }
}
