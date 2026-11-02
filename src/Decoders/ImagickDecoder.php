<?php

namespace Ibrohim238\Phash\Decoders;

use Ibrohim238\Phash\PhashException;
use Ibrohim238\Phash\GrayscaleImage;
use Imagick;
use ImagickException;

/**
 * Последнее звено цепочки: всё, что не разобрали остальные декодеры (TIFF, PSD, анимированный WebP,
 * CMYK JPEG, 16-битные PNG и т.д.), читает ImageMagick — первый кадр, как Pillow.
 */
final readonly class ImagickDecoder implements ImageDecoderInterface
{
    public function decode(string $contents): ?GrayscaleImage
    {
        $imagick = new Imagick();

        try {
            $imagick->readImageBlob($contents);
        } catch (ImagickException) {
            throw PhashException::unreadableImage();
        }

        $imagick->setFirstIterator();
        $width  = $imagick->getImageWidth();
        $height = $imagick->getImageHeight();

        if ($imagick->getImageColorspace() === Imagick::COLORSPACE_CMYK) {
            $channels = $imagick->exportImagePixels(0, 0, $width, $height, 'CMYK', Imagick::PIXEL_CHAR);
            $luma     = static fn(int $i): int => GrayscaleImage::cmykLuma(
                $channels[$i * 4],
                $channels[$i * 4 + 1],
                $channels[$i * 4 + 2],
                $channels[$i * 4 + 3],
            );
        } elseif ($imagick->getImageDepth() > 8 && $imagick->getImageType() === Imagick::IMGTYPE_GRAYSCALE) {
            // Pillow открывает серый глубже 8 бит как режим I / I;16, а convert('L') обрезает значения до 255;
            // ImageMagick масштабирует 9..15-битные значения до 16 бит — возвращаем исходную глубину
            $maximum  = (1 << $imagick->getImageDepth()) - 1;
            $channels = $imagick->exportImagePixels(0, 0, $width, $height, 'I', Imagick::PIXEL_SHORT);
            $luma     = static fn(int $i): int => min((int) round($channels[$i] * $maximum / 65535), 255);
        } elseif ($imagick->getImageDepth() === 16) {
            // 16-битный RGB Pillow читает, оставляя от каждого канала старший байт
            $channels = $imagick->exportImagePixels(0, 0, $width, $height, 'RGB', Imagick::PIXEL_SHORT);
            $luma     = static fn(int $i): int => GrayscaleImage::luma($channels[$i * 3] >> 8, $channels[$i * 3 + 1] >> 8, $channels[$i * 3 + 2] >> 8);
        } else {
            $channels = $imagick->exportImagePixels(0, 0, $width, $height, 'RGB', Imagick::PIXEL_CHAR);
            $luma     = static fn(int $i): int => GrayscaleImage::luma($channels[$i * 3], $channels[$i * 3 + 1], $channels[$i * 3 + 2]);
        }

        $imagick->clear();
        $rows = [];

        for ($y = 0; $y < $height; $y++) {
            $row = [];

            for ($x = 0; $x < $width; $x++) {
                $row[] = $luma($y * $width + $x);
            }

            $rows[] = pack('C*', ...$row);
        }

        return new GrayscaleImage($width, $height, $rows);
    }
}
