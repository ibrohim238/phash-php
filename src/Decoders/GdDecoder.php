<?php

namespace Ibrohim238\Phash\Decoders;

use Ibrohim238\Phash\GrayscaleImage;
use Ibrohim238\Phash\PhashMathInterface;
use GdImage;

/**
 * JPEG, PNG, GIF, WebP и WBMP через GD: для них GD читает пиксели так же, как Pillow.
 * Исключения отдаются дальше по цепочке: 16-битный серый PNG и CMYK JPEG (GD теряет данные),
 * обрезанный PNG и анимированный WebP (GD их не открывает).
 */
final readonly class GdDecoder implements ImageDecoderInterface
{
    private const IMAGE_TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP, IMAGETYPE_WBMP];

    public function __construct(
        private PhashMathInterface $math,
    ) {
    }

    public function decode(string $contents): ?GrayscaleImage
    {
        $info = getimagesizefromstring($contents);

        if ($info === false || !in_array($info[2], self::IMAGE_TYPES, true)
                            || self::isSixteenBitGrayscalePng($contents) || self::isCmykJpeg($info) || TruncatedPngDecoder::accepts($contents)) {
            return null;
        }

        $image = @imagecreatefromstring($contents);

        if (!$image instanceof GdImage) {
            return null;
        }

        [$frame, $paletteLuma] = $this->fromGd($image);

        return $info[2] === IMAGETYPE_GIF ? self::onGifCanvas($frame, $paletteLuma, $contents) : $frame;
    }

    /**
     * Пиксели читаются одним куском через формат .gd (`imagegd`): после заголовка идут строки
     * изображения — 4 байта ARGB на пиксель для truecolor и 1 байт индекса палитры для палитровых.
     *
     * @return array{GrayscaleImage, string} Изображение и яркость 256 индексов палитры (пустая строка для truecolor)
     */
    private function fromGd(GdImage $image): array
    {
        $width       = imagesx($image);
        $height      = imagesy($image);
        $isTrueColor = imageistruecolor($image);

        ob_start();
        imagegd($image);
        $data = ob_get_clean();

        $stride = $width * ($isTrueColor ? 4 : 1);
        $pixels = substr($data, -$stride * $height);

        if ($isTrueColor) {
            return [new GrayscaleImage($width, $height, str_split($this->math->luma($pixels), $width)), ''];
        }

        $indexes     = implode('', array_map(chr(...), range(0, 255)));
        $lumaByIndex = str_repeat("\0", 256);

        for ($index = 0, $total = imagecolorstotal($image); $index < $total; $index++) {
            $rgb                 = imagecolorsforindex($image, $index);
            $lumaByIndex[$index] = chr(GrayscaleImage::luma($rgb['red'], $rgb['green'], $rgb['blue']));
        }

        $rows = [];

        for ($y = 0; $y < $height; $y++) {
            $rows[] = strtr(substr($pixels, $y * $stride, $stride), $indexes, $lumaByIndex);
        }

        return [new GrayscaleImage($width, $height, $rows), $lumaByIndex];
    }

    /**
     * GD отдаёт первый кадр GIF в его собственном размере, Pillow — холст размером с логический экран
     * (или больше, если кадр выходит за него), залитый индексом прозрачности кадра либо 0, с кадром по смещению.
     */
    private static function onGifCanvas(GrayscaleImage $frame, string $paletteLuma, string $contents): GrayscaleImage
    {
        ['width' => $screenWidth, 'height' => $screenHeight] = unpack('vwidth/vheight', $contents, 6);
        $flags                                               = ord($contents[10]);
        $position                                            = 13 + (($flags & 0x80) ? 3 << (($flags & 7) + 1) : 0);
        $transparency                                        = null;

        while ($contents[$position] === '!') {
            $label = ord($contents[$position + 1]);
            $position += 2;

            if ($label === 0xF9 && (ord($contents[$position + 1]) & 1)) {
                $transparency = ord($contents[$position + 4]);
            }

            while (($size = ord($contents[$position])) !== 0) {
                $position += $size + 1;
            }

            $position++;
        }

        ['left' => $left, 'top' => $top] = unpack('vleft/vtop', $contents, $position + 1);
        $width                           = max($left + $frame->width, $screenWidth);
        $height                          = max($top + $frame->height, $screenHeight);

        if ($width === $frame->width && $height === $frame->height) {
            return $frame;
        }

        $fill = $paletteLuma[$transparency ?? 0];
        $rows = array_fill(0, $height, str_repeat($fill, $width));

        foreach ($frame->rows as $y => $row) {
            $rows[$top + $y] = substr_replace($rows[$top + $y], $row, $left, $frame->width);
        }

        return new GrayscaleImage($width, $height, $rows);
    }

    private static function isSixteenBitGrayscalePng(string $contents): bool
    {
        return str_starts_with($contents, "\x89PNG\r\n\x1a\n")
            && strlen($contents) > 25
            && ord($contents[24]) === 16
            && ord($contents[25]) === 0;
    }

    /**
     * @param array<int|string, mixed> $info Результат getimagesizefromstring()
     */
    private static function isCmykJpeg(array $info): bool
    {
        return $info[2] === IMAGETYPE_JPEG && ($info['channels'] ?? null) === 4;
    }
}
