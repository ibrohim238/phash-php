<?php

namespace Ibrohim238\Phash\Decoders;

use Ibrohim238\Phash\PhashException;
use Ibrohim238\Phash\GrayscaleImage;

/**
 * SPIDER 2D (SpiderImagePlugin.py): заголовок из float32, порядок байтов определяется по валидности заголовка,
 * пиксели — float32; convert('L') из режима F обрезает до 0..255 и отбрасывает дробную часть.
 */
final readonly class SpiderDecoder implements ImageDecoderInterface
{
    private const FORMS = [1, 3, -11, -12, -21, -22];

    private static function accepts(string $contents): bool
    {
        return strlen($contents) >= 108 && self::header($contents) !== null;
    }

    public function decode(string $contents): ?GrayscaleImage
    {
        if (!self::accepts($contents)) {
            return null;
        }

        [$h, $bigEndian, $headerLength] = self::header($contents);

        if ((int) $h[5] !== 1) {
            throw PhashException::unreadableImage();
        }

        $width        = (int) $h[12];
        $height       = (int) $h[2];
        $stack        = (int) $h[24];
        $imgNumber    = (int) $h[27];
        $isFirstFrame = $imgNumber === 0;

        $offset = match (true) {
            $stack === 0 && $isFirstFrame => $headerLength,
            $stack > 0   && $isFirstFrame => $headerLength * 2,
            default                       => throw PhashException::unreadableImage(),
        };

        $format = ($bigEndian ? 'G' : 'g') . $width;
        $rows   = array_fill(0, $height, str_repeat("\0", $width));

        for ($y = 0; $y < $height; $y++) {
            $line = substr($contents, $offset + $y * $width * 4, $width * 4);

            if (strlen($line) < $width * 4) {
                break;
            }

            $row = '';

            foreach (unpack($format, $line) as $value) {
                $row .= chr($value <= 0.0 ? 0 : ($value >= 255.0 ? 255 : (int) $value));
            }

            $rows[$y] = $row;
        }

        return new GrayscaleImage($width, $height, $rows);
    }

    /**
     * @return array{array<int, float>, bool, int}|null Заголовок с индексацией с 1, big-endian, длина заголовка
     */
    private static function header(string $contents): ?array
    {
        foreach ([true, false] as $bigEndian) {
            $values = unpack(($bigEndian ? 'G' : 'g') . '27', $contents);
            $h      = [99.0, ...array_values($values)];
            $valid  = true;

            foreach ([1, 2, 5, 12, 13, 22, 23] as $index) {
                if (!is_finite($h[$index]) || $h[$index] !== floor($h[$index])) {
                    $valid = false;
                    break;
                }
            }

            if (!$valid || !in_array((int) $h[5], self::FORMS, true)) {
                continue;
            }

            $headerLength = (int) $h[22];

            if ($headerLength !== 0 && $headerLength === (int) $h[13] * (int) $h[23]) {
                return [$h, $bigEndian, $headerLength];
            }
        }

        return null;
    }
}
