<?php

namespace Ibrohim238\Phash;

/**
 * Считает pHash так же, как `imagehash.phash(img, hash_size, highfreq_factor)`: изображение переводится
 * в оттенки серого, сжимается фильтром Lanczos до стороны `hashSize * highfreqFactor`, по столбцам
 * и строкам берётся DCT-II, из левого верхнего блока `hashSize × hashSize` биты ставятся по сравнению
 * с медианой. При значениях по умолчанию — 144×144, блок 12×12 и 36 hex-символов на выходе.
 */
final readonly class PhashCalculator
{
    /**
     * По умолчанию 12 и 12; у самого imagehash это 8 и 4.
     */
    public function __construct(
        private GrayscaleImageReader $reader,
        private PhashMathInterface $math,
        private int $hashSize = 12,
        private int $highfreqFactor = 12,
    ) {
    }

    public function calculate(string $imageContents): string
    {
        $image          = $this->resample($this->reader->read($imageContents));
        $lowFrequencies = $this->lowFrequencies($image);
        $median         = $this->median(array_merge(...$lowFrequencies));
        $bits           = '';

        foreach ($lowFrequencies as $row) {
            foreach ($row as $value) {
                $bits .= $value > $median ? '1' : '0';
            }
        }

        // imagehash переводит всю строку бит в одно число и печатает hex шириной ceil(бит/4),
        // то есть дополняет нулями слева; при нечётном hashSize резать на четвёрки слева нельзя
        $bits = str_pad($bits, (int) ceil(strlen($bits) / 4) * 4, '0', STR_PAD_LEFT);

        return implode('', array_map(static fn(string $nibble): string => dechex(bindec($nibble)), str_split($bits, 4)));
    }

    public function resample(GrayscaleImage $image): GrayscaleImage
    {
        $size = $this->hashSize * $this->highfreqFactor;

        return $this->math->resize($image, $size, $size);
    }

    /**
     * Блок hashSize×hashSize низких частот двумерного DCT-II (сначала по столбцам, затем по строкам).
     *
     * @return array<int, array<int, float>>
     */
    public function lowFrequencies(GrayscaleImage $image): array
    {
        return $this->math->dct($image, $this->hashSize);
    }

    /**
     * @param array<int, float> $values
     */
    private function median(array $values): float
    {
        sort($values);
        $count = count($values);

        if ($count % 2 === 1) {
            return $values[intdiv($count, 2)];
        }

        return ($values[intdiv($count, 2) - 1] + $values[intdiv($count, 2)]) / 2;
    }
}
