<?php

namespace Ibrohim238\Phash;

use RuntimeException;

/**
 * Шаги расчёта pHash на C++ через расширение phashext (репозиторий phashext): яркость,
 * снятие фильтров PNG, Lanczos-ресайз Pillow, DCT pocketfft.
 */
final readonly class PhashMath implements PhashMathInterface
{
    public static function load(): self
    {
        if (!extension_loaded('phashext')) {
            throw new RuntimeException(
                'Для расчёта pHash нужно расширение phashext: подключите готовую сборку из репозитория phashext (см. README)',
            );
        }

        return new self();
    }

    public function luma(string $argb): string
    {
        return phashext_luma($argb);
    }

    public function resize(GrayscaleImage $image, int $width, int $height): GrayscaleImage
    {
        $pixels = phashext_resize(implode('', $image->rows), $image->width, $image->height, $width, $height);

        return new GrayscaleImage($width, $height, str_split($pixels, $width));
    }

    public function dct(GrayscaleImage $image, int $hashSize): array
    {
        return phashext_dct(implode('', $image->rows), $image->width, $hashSize);
    }

    public function pngUnfilter(string $data, int $rowBytes, int $rows, int $pixelBytes): ?string
    {
        return phashext_png_unfilter($data, $rowBytes, $rows, $pixelBytes);
    }

    public function pngLuma(string $data, int $rowBytes, int $rows, int $width, int $color, string $paletteLuma): string
    {
        return phashext_png_luma($data, $rowBytes, $rows, $width, $color, $paletteLuma);
    }
}
