<?php
declare(strict_types=1);

namespace hiqdev\IpTools;

use Generator;
use PhpIP\IPv4;
use PhpIP\IPv6;

/**
 * Class IpRangeParser parses fuzzy IP address block definitions.
 *
 * Example:
 * ```
 * IpRangeParser::fromString('192.0.2.[1,2,100-250]/24');
 * // ['192.0.2.1/24', '192.0.2.2/24', '192.0.2.100/24' ... '192.0.2.250/24']
 * ```
 *
 * @author Dmytro Naumenko <d.naumenko.a@gmail.com>
 */
final class IpRangeParser
{
    private const IP4_EXPANSION_PATTERN = '/(\[(?:\d{1,3}[?:,-])+\d{1,3}\])/';
    private const IP6_EXPANSION_PATTERN = '/(\[(?:[\da-f]{1,4}[?:,-])+[\da-f]{1,4}\])/';

    /**
     * Expand an IP address pattern into a list of strings. Examples:
     *
     * - '192.0.2.[1,2,100-250]/24' => ['192.0.2.1/24', '192.0.2.2/24', '192.0.2.100/24' ... '192.0.2.250/24']
     * - '2001:db8:0:[0,fd-ff]::/64' => ['2001:db8:0:0::/64', '2001:db8:0:fd::/64', ... '2001:db8:0:ff::/64']
     *
     * @param string $rangeString
     * @param int $family
     * @psalm-param IPv4::IP_VERSION|IPv6::IP_VERSION $family
     * @return Generator<int, string, mixed, void>
     */
    public static function fromString(string $rangeString, int $family = null): Generator
    {
        if ($family === null) {
            $family = !str_contains($rangeString, '.') ? IPv6::IP_VERSION : IPv4::IP_VERSION;
        }

        switch ($family) {
            case IPv4::IP_VERSION:
                $pattern = self::IP4_EXPANSION_PATTERN;
                $base = 10;
                break;
            case IPv6::IP_VERSION:
                $pattern = self::IP6_EXPANSION_PATTERN;
                $base = 16;
                break;
            default: throw new Exception('Invalid IP family version');
        }

        $matches = preg_split($pattern, $rangeString, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (count($matches) === 1) {
            yield $rangeString;
            return;
        }

        $left = array_shift($matches);
        $match = array_shift($matches);
        $right = implode('', $matches);

        foreach (self::parseNumericRange(trim($match, '[]'), $base) as $value) {
            if (preg_match($pattern, $right) === 1) {
                foreach (self::fromString($right, $family) as $string) {
                    yield $left . $value . $string;
                }
            } else {
                yield $left . $value . $right;
            }
        }
    }

    /**
     *
     * Expand a numeric range string (continuous or not) into a decimal or
     * hexadecimal list, as specified by the base parameter:
     *  - '0-3,5' => [0, 1, 2, 3, 5]
     *  - '2,8-b,d,f' => [2, 8, 9, a, b, d, f]
     *
     * @param string $string
     * @param int $base
     * @return list<string>
     */
    private static function parseNumericRange(string $string, int $base): array
    {
        $result = [];
        foreach (explode(',', $string) as $dashRange) {
            if (str_contains($dashRange, '-')) {
                [$min, $max] = explode('-', $dashRange, 2);
                $min = base_convert($min, $base, 10);
                $max = base_convert($max, $base, 10);

                $items = array_map(fn($num) => base_convert($num, 10, $base), range($min, $max));
                $result = array_merge($result, $items);
            } else {
                $result[] = $dashRange;
            }
        }

        return $result;
    }
}
