<?php

namespace Panda\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Panda\BlindIndex\BlindIndexer;

/**
 * Unit tests for BlindIndexer.
 *
 * The first group is the one that matters. A blind index exists so that one
 * system can find a record another system wrote, so the only thing that makes
 * it correct is agreeing with the other implementations. Those tests pin the
 * exact values panda-go, panda-node and panda-py produce for the same input
 * and key — verified by running all four.
 *
 * They are here because this SDK used to disagree with the other three, and
 * nothing noticed. trim() removes ASCII whitespace only, so a trailing
 * non-breaking space survived normalisation here and was removed everywhere
 * else; and strtolower() lowercases ASCII only, so a non-ASCII letter was
 * left alone. Both produce a different index, which means a lookup that
 * returns nothing — no error, no warning, just an empty result that looks
 * exactly like a record that is not there.
 */
class BlindIndexerTest extends TestCase
{
    /**
     * The key used to produce the reference values below.
     */
    private const KEY = 'kunci-indeks-uji';

    /**
     * Values every implementation must produce.
     *
     * Regenerate only by running all four SDKs and confirming they still
     * agree — a value changed here to match one implementation that drifted
     * would defeat the point of the test.
     */
    public static function crossLanguageVectors(): array
    {
        return [
            'a NIK carrying a trailing non-breaking space' => [
                "3201012501990001\u{00A0}",
                'lowercase',
                '96Yf1v9Somcu3yTQFdRRtcX3bZvwmheCGHOOGw3ht5g=',
            ],
            'an email surrounded by spaces and mixed case' => [
                '  Budi@Example.COM  ',
                'lowercase',
                '5iCEQ5ebxDzOUXBpRNwicf+Adfa4k//34AgSscgutto=',
            ],
            'a NIK written with separators, under the NIK rule' => [
                '3201-0125-0199-0001',
                'nik',
                '96Yf1v9Somcu3yTQFdRRtcX3bZvwmheCGHOOGw3ht5g=',
            ],
            'a value with non-ASCII letters' => [
                "\u{00C5}NGSTR\u{00D6}M",
                'lowercase',
                'ev56RPz76690ucDvrXYACPYTwUDTkxAH7hEE2ntnna0=',
            ],
            'a value padded with ideographic spaces' => [
                "\u{3000}nilai\u{3000}",
                'trim',
                'yNZ52k0OhAvMyGR59i951H8WTr03eJJe+B14U4yKOIU=',
            ],
        ];
    }

    /**
     * @dataProvider crossLanguageVectors
     */
    public function testAgreesWithTheOtherImplementations(
        string $value,
        string $normalization,
        string $expected
    ): void {
        $this->assertSame(
            $expected,
            BlindIndexer::generate($value, self::KEY, $normalization),
            'this SDK produced a different index to panda-go, panda-node and panda-py'
        );
    }

    public function testTheSameNIKIndexesTheSameWhetherSeparatedOrNot(): void
    {
        // The reason the NIK rule exists. A value typed with separators and the
        // same value typed without must be one person to the database.
        $this->assertSame(
            BlindIndexer::generate('3201012501990001', self::KEY, 'nik'),
            BlindIndexer::generate('3201-0125-0199-0001', self::KEY, 'nik')
        );
    }

    public function testIsDeterministic(): void
    {
        $this->assertSame(
            BlindIndexer::generate('3201012501990001', self::KEY, 'lowercase'),
            BlindIndexer::generate('3201012501990001', self::KEY, 'lowercase')
        );
    }

    public function testDifferentKeysProduceDifferentIndexes(): void
    {
        $this->assertNotSame(
            BlindIndexer::generate('3201012501990001', self::KEY, 'lowercase'),
            BlindIndexer::generate('3201012501990001', 'kunci-lain', 'lowercase')
        );
    }

    public function testIsBase64OfThirtyTwoBytes(): void
    {
        $index = BlindIndexer::generate('3201012501990001', self::KEY, 'lowercase');

        // 44 characters ending in '=', and the column it goes in is sized for
        // it. A value of another width means the column truncated it.
        $this->assertSame(44, strlen($index));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/]{43}=$/', $index);
        $this->assertSame(32, strlen(base64_decode($index, true)));
    }

    public function testLowercaseIsTheDefault(): void
    {
        $this->assertSame(
            BlindIndexer::generate('  Budi@Example.COM  ', self::KEY, 'lowercase'),
            BlindIndexer::generate('  Budi@Example.COM  ', self::KEY)
        );
    }

    /**
     * The NIK rule removes everything that is not a digit, so a value with no
     * digits becomes the empty string — and every value that does shares one
     * index.
     *
     * Documented as a test rather than a comment because it is the reason the
     * rule is not the default: applied to a name or an email, this endpoint
     * returns the same index for every row in the column.
     */
    public function testTheNIKRuleCollapsesValuesWithoutDigits(): void
    {
        $first = BlindIndexer::generate('Budi Santoso', self::KEY, 'nik');
        $second = BlindIndexer::generate('Siti Rahayu', self::KEY, 'nik');

        $this->assertSame(
            $first,
            $second,
            'the NIK rule no longer collapses non-numeric values; the warning in ' .
            'panda-spec/blind-index-format.md needs revisiting if this is deliberate'
        );
    }

    /**
     * And the default must not do that.
     */
    public function testTheDefaultDoesNotCollapseValuesWithoutDigits(): void
    {
        $this->assertNotSame(
            BlindIndexer::generate('Budi Santoso', self::KEY),
            BlindIndexer::generate('Siti Rahayu', self::KEY)
        );
    }

    public function testNormalizeExposesWhatWasHashed(): void
    {
        $this->assertSame('3201012501990001', BlindIndexer::normalize("3201012501990001\u{00A0}", 'trim'));
        $this->assertSame('3201012501990001', BlindIndexer::normalize('3201-0125-0199-0001', 'nik'));
        $this->assertSame('budi@example.com', BlindIndexer::normalize('  Budi@Example.COM  ', 'lowercase'));
        $this->assertSame('  spaced  ', BlindIndexer::normalize('  spaced  ', 'none'));
    }
}
