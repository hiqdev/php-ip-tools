<?php
declare(strict_types=1);

namespace hiqdev\IpTools;

use PhpIP\IP;
use PhpIP\IPBlock;
use PhpIP\IPv4;
use PhpIP\IPv4Block;
use PhpIP\IPv6;
use PhpIP\IPv6Block;

/**
 * Class IpBlocksCalculator computes the best possible aligned IP blocks within a given range,
 * excluding the taken blocks or IP addresses.
 *
 * The best block – is a block that has the most free IP addresses in it.
 *
 * @author Dmytro Naumenko <d.naumenko.a@gmail.com>
 */
class IpBlocksCalculator
{
    /**
     * {@withMinimumPrefixLength()}
     *
     * @var array<int, int>
     * @see withMinimumPrefixLength()
     */
    protected array $minimumPrefixLength = [
        IPv4::IP_VERSION => 8,
        IPv6::IP_VERSION => 30,
    ];

    /** @psalm-suppress MissingConstructor */
    private int $version;

    private int $splitEmptyRootCidrBySubBlockPrefix = 0;
    /**
     * When the whole $cidr passed into {@see __invoke()} is not split by $takenCidrs,
     * the resulting block should split by adding $prefix to the prefix.
     *
     * @example
     * - `192.168.0.0/24` and $prefix=0   => `192.168.0.0/24`
     * - `192.168.0.0/24` and $prefix=1   => `192.168.0.0/25, 192.168.0.128/25`
     * - `192.168.0.0/24` and $prefix=2   => `192.168.0.0/25, 192.168.0.64/25, 192.168.0.128/25, 192.168.0.192/25`
     *
     * @param int $prefix
     * @return self a copy of object
     */
    public function withSplitEmptyRootCidrBySubBlockPrefix(int $prefix): self
    {
        $self = clone $this;
        $self->splitEmptyRootCidrBySubBlockPrefix = $prefix;

        return $self;
    }

    /**
     * @param string $cidr an upper block in CIDR notation, where free IP blocks are being searched
     * @param list<string> $takenCidrs array of taken networks, e.g. `['192.168.0.64/26', '192.168.0.128/26']`
     * @return list<string> an ordered array of free CIDR IP blocks
     */
    public function __invoke(string $cidr, array $takenCidrs = []): array
    {
        $this->validateCidr($cidr);
        $this->version = $this->detectIpVersion($cidr);
        $block = $this->createBlock($cidr);

        $takenBlocks = $this->createBlocks($takenCidrs);
        /** @var list<IPBlock> $takenBlocksWithinParentBlock */
        $takenBlocksWithinParentBlock = array_filter($takenBlocks, static fn(IPBlock $takenBlock): bool => $block->contains($takenBlock));
        if (!empty($takenBlocksWithinParentBlock)) {
            $freeBlocks = [];
            foreach ($this->calculateFreeRanges($block, $takenBlocksWithinParentBlock) as $freeRange) {
                $minimumPrefixLength = max($block->getPrefix(), $this->minimumPrefixLength[$this->version]);
                $freeBlocks = [...$freeBlocks, ...$this->computeBestBlocks($minimumPrefixLength, $freeRange)];
            }
        } else {
            $freeBlocks = [$block];

            if ($this->splitEmptyRootCidrBySubBlockPrefix > 0) {
                $minimumPrefixLength = max($block->getPrefix() + $this->splitEmptyRootCidrBySubBlockPrefix, $this->minimumPrefixLength[$this->version]);
                $blocksIterator = $block->getSubBlocks((string)$minimumPrefixLength);
                $freeBlocks = iterator_to_array($blocksIterator, false);
            }
        }

        /** @var list<IPBlock> $freeBlocks */
        return array_map(fn(IPBlock $block): string => $block->__toString(), $freeBlocks);
    }

    /**
     * @param list<string> $cidrs
     */
    private function createBlocks(array $cidrs): array
    {
        $result = [];

        $maxMask = $this->version === IPv4::IP_VERSION ? 32 : 128;
        foreach ($cidrs as $cidr) {
            if (!str_contains($cidr, '/')) {
                $cidr .= '/' . $maxMask;
            }
            $this->validateCidr($cidr);
            $result[] = $this->createBlock($cidr);
        }

        return $result;
    }

    /**
     * @param list<IP> $range array of two elements:
     * 0 - block start IP address
     * 1 - block end IP address
     *
     * @param IPBlock $block a block being checked
     * @return bool whether a $range contains given $block
     */
    private function rangeContainsBlock(array $range, IPBlock $block): bool
    {
        return $range[0]->numeric() <= $block->getNetworkAddress()->numeric()
            && $range[1]->numeric() >= $block->getBroadcastAddress()->numeric();
    }

    /**
     * @param IPBlock $cidr
     * @param list<IPBlock> $takenBlocks
     * @return list<list<IP>>
     */
    private function calculateFreeRanges(IPBlock $cidr, array $takenBlocks): array
    {
        $this->sortBlocks($takenBlocks);
        $takenBLocks = $this->filterOverlappingBlocks($takenBlocks);

        /** @var list<list<IP>> $ranges */
        $ranges = [];

        $isFirst = true;
        $allFree = true;
        foreach ($takenBlocks as $i => $block) {
            if (!$cidr->overlaps($block)) {
                continue;
            }

            $allFree = false;
            if ($isFirst) {
                if ($block->getNetworkAddress() > $cidr->getNetworkAddress()) {
                    $ranges[] = [$cidr->getNetworkAddress(), $block->getNetworkAddress()->minus(1)];
                }
                $isFirst = false;
            }

            $upper = $cidr->getBroadcastAddress();
            if ($i < count($takenBlocks)-1) {
                $upper = min($upper, $takenBlocks[$i+1]->getNetworkAddress()->minus(1));
            }

            if ($block->getBroadcastAddress() <= $upper) {
                $ranges[] = [$block->getBroadcastAddress()->plus(1), $upper];
            }
        }

        if ($allFree) {
            $ranges[] = [$cidr->getNetworkAddress(), $cidr->getBroadcastAddress()];
        }

        return $ranges;
    }

    /**
     * @param string $ip
     * @return string
     * @deprecated Drop after https://github.com/rlanvin/php-ip/issues/57 is fixed
     */
    private function ipToNumeric(string $ip): string
    {
        /** @var class-string<IPv4>|class-string<IPv6> $ipClass */
        $ipClass = $this->version === IPv4::IP_VERSION
            ? IPv4::class
            : IPv6::class;

        return $ipClass::create(inet_pton($ip))->numeric();
    }

    /**
     * Creates a block with a strict IP version.
     *
     * The library being used treats IPv6 addresses, shorter than 4 bytes as IPv4 addresses.
     *
     * @param IP|string $cidr
     * @param int|null $prefixLength
     * @return IPBlock
     */
    private function createBlock($cidr, int $prefixLength = null): IPBlock
    {
        $blockClass = $this->version === IPv4::IP_VERSION
            ? IPv4Block::class
            : IPv6Block::class;

        if (is_object($cidr)) {
            return new $blockClass($cidr, $prefixLength);
        }

        [$network, $parsedPrefix] = explode('/', $cidr, 2);
        if ($this->version !== $this->detectIpVersion($network)) {
            throw new Exception(sprintf('Address "%s" is not a valid address', $cidr));
        }

        /** @psalm-suppress DeprecatedMethod */
        return new $blockClass($this->ipToNumeric($network), $prefixLength ?? $parsedPrefix);
    }

    /**
     * @param int $minPrefixLength
     * @param list<IP> $freeRange
     * @return list<IPBlock>
     */
    private function computeBestBlocks(int $minPrefixLength, array $freeRange): array
    {
        $bestPrefixLength = $minPrefixLength;
        $maxMask = $this->version === IPv4::IP_VERSION ? IPv4::NB_BITS : IPv6::NB_BITS;
        for ($i = $maxMask; $i >= $minPrefixLength; $i--) {
            $a = $this->createBlock($freeRange[0], $i);
            $b = $this->createBlock($freeRange[1], $i);

            if ($a->getNetworkAddress()->matches($b->getNetworkAddress(), $i)) {
                $bestPrefixLength = $i;
            }
        }

        $firstAddress = $freeRange[0];

        $result = [];
        $i = $bestPrefixLength;
        while ($i <= $maxMask && $firstAddress <= $freeRange[1]) {
            $blockGuess = $this->createBlock($firstAddress, $i);
            if ($this->rangeContainsBlock($freeRange, $blockGuess)) {
                $result[] = $blockGuess;
                $firstAddress = $blockGuess->getBroadcastAddress()->plus(1);
                $i = $bestPrefixLength;
            } else {
                $i++;
            }
        }

        return $result;
    }

    /**
     * Sort blocks by network address
     *
     * @param list<IPBlock> $blocks
     */
    private function sortBlocks(array &$blocks): void
    {
        usort($blocks, static function(IPBlock $a, IPBlock $b): int {
            $aWeight = $a->getNetworkAddress()->numeric(36) . $a->getPrefix()*1000;
            $bWeight = $b->getNetworkAddress()->numeric(36) . $b->getPrefix()*1000;

            return $aWeight <=> $bWeight;
        });
    }

    /**
     * Exclude smaller networks if they are contained by larger ones
     *
     * @example
     *
     * ```php
     * filterOverlappingBlocks([
     *     IpBlock('192.168.0.0/23'),
     *     IpBlock('192.168.0.0/24'),
     * ]);
     *
     * // Gives [IpBlock('192.168.0.0/23')]
     * ```
     *
     * @param list<IPBlock> $blocks
     * @return list<IPBlock>
     */
    private function filterOverlappingBlocks(array &$blocks): array
    {
        /** @noinspection PhpConditionAlreadyCheckedInspection */
        do {
            $takenBlocksCopy = $blocks;
            $minNetMask = [];

            foreach ($blocks as $i => $block) {
                $netAddr = $block->getNetworkAddress()->numeric();
                $prefix = $block->getPrefix();

                if (!isset($minNetMask[$netAddr]) || $minNetMask[$netAddr] > $prefix) {
                    $minNetMask[$netAddr] = $prefix;
                }

                if ($prefix > $minNetMask[$netAddr]) {
                    unset($blocks[$i]);
                    break;
                }
            }
        } while ($takenBlocksCopy !== $blocks);

        return $takenBlocksCopy;
    }

    private function validateCidr(string $cidr): void
    {
        @[$network, $prefix] = explode('/', $cidr, 2);

        if (@inet_pton($network) === false) {
            throw new Exception("$network does not appear to be an IPv4 or IPv6 block");
        }
        if ($prefix === null) {
            throw new Exception("Address \"$cidr\" MUST be in CIDR notation");
        }
    }

    /**
     * @param string $ip
     * @return IPv6::IP_VERSION|IPv4::IP_VERSION
     */
    private function detectIpVersion(string $ip): int
    {
        return str_contains($ip, ':') ? IPv6::IP_VERSION : IPv4::IP_VERSION;
    }

    /**
     * Sets the minimum prefix length for free blocks.
     *
     * @param int $ipv4PrefixLength
     * @param int $ipv6PrefixLength
     * @return $this
     *
     * @example When there is a free IPv4 block `10.0.0.0-10.0.0.31`,
     * the calculator will group them into a single prefix `10.0.0.0/27` by default.
     * In case you want to return a list of `/30` IPv4 prefixes, call this method
     * and set the $ipv4Mask to `30`.
     *
     */
    public function withMinimumPrefixLength(int $ipv4PrefixLength = 8, int $ipv6PrefixLength = 30): self
    {
        if ($ipv4PrefixLength < 1 || $ipv4PrefixLength > IPv4::NB_BITS) {
            throw new Exception(sprintf('IPv4 prefix length "%s" is not valid', $ipv4PrefixLength));
        }
        if ($ipv6PrefixLength < 1 || $ipv6PrefixLength > IPv6::NB_BITS) {
            throw new Exception(sprintf('IPv6 prefix length "%s" is not valid', $ipv6PrefixLength));
        }

        $self = clone $this;
        $self->minimumPrefixLength = [
            IPv4::IP_VERSION => $ipv4PrefixLength,
            IPv6::IP_VERSION => $ipv6PrefixLength,
        ];

        return $self;
    }
}
