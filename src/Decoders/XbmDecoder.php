<?php

namespace Ibrohim238\Phash\Decoders;

use Ibrohim238\Phash\GrayscaleImage;

/**
 * XBM по логике Pillow 9.4.0 (XbmImagePlugin.py, XbmDecode.c): байты берутся после каждого символа «x»,
 * биты идут от младшего к старшему, установленный бит — белый (Pillow читает XBM «инвертированно»,
 * в отличие от X11 и ImageMagick).
 */
final readonly class XbmDecoder implements ImageDecoderInterface
{
    /** Заголовок ищется от начала и только в первых байтах — как `xbm_head.match(self.fp.read(512))` в Pillow */
    private const HEADER_BYTES = 512;

    private const HEADER = '/\s*#define[ \t]+.*_width[ \t]+(?<width>[0-9]+)[\r\n]+#define[ \t]+.*_height[ \t]+(?<height>[0-9]+)[\r\n]+(#define[ \t]+.*_x_hot[ \t]+[0-9]+[\r\n]+#define[ \t]+.*_y_hot[ \t]+[0-9]+[\r\n]+)?[\x00-\xff]*_bits\[\]/A';

    public function decode(string $contents): ?GrayscaleImage
    {
        if (!preg_match(self::HEADER, substr($contents, 0, self::HEADER_BYTES), $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $width        = (int) $match['width'][0];
        $height       = (int) $match['height'][0];
        $bytesPerLine = ($width + 7) >> 3;
        $rows         = array_fill(0, $height, str_repeat("\0", $width));
        $line         = '';
        $y            = 0;
        $position     = $match[0][1] + strlen($match[0][0]);

        while ($y < $height && ($position = strpos($contents, 'x', $position)) !== false && $position + 3 <= strlen($contents)) {
            $line .= chr((self::hex($contents[$position + 1]) << 4) + self::hex($contents[$position + 2]));
            $position += 3;

            if (strlen($line) === $bytesPerLine) {
                $rows[$y++] = self::unpackLsbFirst($line, $width);
                $line       = '';
            }
        }

        return new GrayscaleImage($width, $height, $rows);
    }

    private static function unpackLsbFirst(string $line, int $width): string
    {
        $row = '';

        for ($x = 0; $x < $width; $x++) {
            $row .= (ord($line[$x >> 3]) >> ($x & 7)) & 1 ? "\xFF" : "\0";
        }

        return $row;
    }

    private static function hex(string $char): int
    {
        return match (true) {
            $char >= '0' && $char <= '9' => ord($char) - ord('0'),
            $char >= 'a' && $char <= 'f' => ord($char) - ord('a') + 10,
            $char >= 'A' && $char <= 'F' => ord($char) - ord('A') + 10,
            default                      => 0,
        };
    }
}
