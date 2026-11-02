<?php

namespace Ibrohim238\Phash;

/**
 * Изображение в оттенках серого, полученное так же, как `PIL.Image.open(...).convert('L')`:
 * RGB → L по ITU-R 601-2 в целых числах, (R * 19595 + G * 38470 + B * 7471 + 0x8000) >> 16, альфа-канал игнорируется.
 */
final readonly class GrayscaleImage
{
    /**
     * @param array<int, string> $rows Строки пикселей, по одному байту (0..255) на пиксель
     */
    public function __construct(
        public int $width,
        public int $height,
        public array $rows,
    ) {
    }

    public static function luma(int $red, int $green, int $blue): int
    {
        return ($red * 19595 + $green * 38470 + $blue * 7471 + 0x8000) >> 16;
    }

    /**
     * Pillow: CMYK → RGB (Convert.c, cmyk2rgb) → L
     */
    public static function cmykLuma(int $cyan, int $magenta, int $yellow, int $key): int
    {
        $white = 255 - $key;

        return self::luma(
            $white - self::mulDiv255($cyan, $white),
            $white - self::mulDiv255($magenta, $white),
            $white - self::mulDiv255($yellow, $white),
        );
    }

    private static function mulDiv255(int $a, int $b): int
    {
        $product = $a * $b + 128;

        return (($product >> 8) + $product) >> 8;
    }
}
