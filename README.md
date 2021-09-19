# PHP IP tools

[![GitHub Actions](https://github.com/hiqdev/php-ip-tools/workflows/Tests/badge.svg)](https://github.com/hiqdev/php-ip-tools/actions)
[![Infection MSI](https://badge.stryker-mutator.io/github.com/hiqdev/php-ip-tools/master)](https://infection.github.io)

This library provides tooling for IP address calculations based on [rlanvin/php-ip](https://github.com/rlanvin/php-ip)

### Free IP blocks calculation

Compute the best possible aligned IP blocks within a given range,
excluding the taken blocks or IP addresses.

Example:
```php
$block = '192.168.0.0/24';
$taken = ['192.168.0.64/28'];

$calculator = new IpBlocksCalculator();
$free = $calculator->__invoke($block, $taken);

var_dump($free);
/*
 *  [
 *    '192.168.0.0/26',
 *    '192.168.0.80/28',
 *    '192.168.0.96/27',
 *    '192.168.0.128/25',
 *   ]
 */
```

See more examples in [IpBlocksCalculatorTest](blob/master/tests/Unit/IpBlocksCalculatorTest.php).


### IP ranges parsing

Parse fuzzy IP address block definitions and unwrap it to an array:

Example:
```php
IpRangeParser::fromString('192.0.2.[1,2,100-250]/24');
// ['192.0.2.1/24', '192.0.2.2/24', '192.0.2.100/24' ... '192.0.2.250/24']
```

See more examples in [IpRangeParserTest](blob/master/tests/Unit/IpRangeParseTest.php).
