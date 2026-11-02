<?php

namespace Ibrohim238\Phash\Tests;

use Ibrohim238\Phash\PhashException;
use Ibrohim238\Phash\GrayscaleImageReader;
use Ibrohim238\Phash\PhashMath;
use Ibrohim238\Phash\PhashCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PhashCalculatorTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/Fixtures';

    /**
     * Ожидаемые значения получены от эталона на Python (imagehash 4.3.1 + Pillow 9.4.0) для тех же файлов.
     */
    #[Test]
    #[DataProvider('fixtures')]
    public function phash_matches_python_service(string $fileName, string $expected): void
    {
        $calculator = self::calculator();

        $phash = $calculator->calculate(file_get_contents(self::FIXTURES . '/' . $fileName));

        $this->assertSame($expected, $phash);
    }

    #[Test]
    #[DataProvider('parameters')]
    public function phash_matches_python_service_for_other_parameters(
        int $hashSize,
        int $highfreqFactor,
        string $fileName,
        string $expected,
    ): void {
        $calculator = self::calculator($hashSize, $highfreqFactor);

        $phash = $calculator->calculate(file_get_contents(self::FIXTURES . '/' . $fileName));

        $this->assertSame($expected, $phash);
    }

    #[Test]
    public function unreadable_contents_throw(): void
    {
        $calculator = self::calculator();

        $this->expectException(PhashException::class);

        $calculator->calculate('definitely not an image');
    }

    /**
     * Картинки из интернета, тест-сьюта Pillow и сгенерированные самой Pillow варианты форматов.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function fixtures(): iterable
    {
        foreach (json_decode(file_get_contents(self::FIXTURES . '/expected.json'), true) as $fileName => $phash) {
            yield $fileName => [$fileName, $phash];
        }
    }

    /**
     * @return iterable<string, array{int, int, string, string}>
     */
    public static function parameters(): iterable
    {
        foreach (json_decode(file_get_contents(self::FIXTURES . '/expected_params.json'), true) as $key => $phash) {
            [$size, $fileName]           = explode('/', $key, 2);
            [$hashSize, $highfreqFactor] = explode('x', $size, 2);

            yield $key => [(int) $hashSize, (int) $highfreqFactor, $fileName, $phash];
        }
    }

    private static function calculator(?int $hashSize = null, ?int $highfreqFactor = null): PhashCalculator
    {
        $math   = PhashMath::load();
        $reader = new GrayscaleImageReader($math);

        return $hashSize === null
            ? new PhashCalculator($reader, $math)
            : new PhashCalculator($reader, $math, $hashSize, $highfreqFactor);
    }
}
