<?php

namespace Ibrohim238\Phash\Decoders;

use Ibrohim238\Phash\PhashException;
use Ibrohim238\Phash\GrayscaleImage;
use Imagick;
use ImagickException;

/**
 * Разбор TIFF для случаев, где ImageMagick расходится с Pillow (TiffImagePlugin.py, Unpack.c):
 *  - серый / RGB / RGBA без сжатия или LZW, 8 и 16 бит, с горизонтальным предиктором; 16-битная
 *    премультиплицированная альфа (ExtraSamples = 1) делится на альфу по старшим байтам, как «RGBa;16»;
 *  - тайлы и полосы, сжатые JPEG (таблицы из тега JPEGTables), в CMYK, RGB и сером.
 * Остальные варианты остаются ImageMagick (decode() возвращает null).
 */
final readonly class TiffDecoder implements ImageDecoderInterface
{
    private const TAGS = [256 => 'width', 257 => 'height', 258 => 'bits', 259 => 'compression', 262 => 'photometric',
        273                   => 'stripOffsets', 277 => 'samples', 278 => 'rowsPerStrip', 279 => 'stripByteCounts', 284 => 'planar',
        317                   => 'predictor', 322 => 'tileWidth', 323 => 'tileLength', 324 => 'tileOffsets', 325 => 'tileByteCounts',
        338                   => 'extraSamples', 347 => 'jpegTables'];

    private const TYPE_SIZES = [1 => 1, 2 => 1, 3 => 2, 4 => 4, 6 => 1, 7 => 1, 8 => 2, 9 => 4, 11 => 4, 12 => 8, 16 => 8];

    private static function accepts(string $contents): bool
    {
        return str_starts_with($contents, "II*\0") || str_starts_with($contents, "MM\0*");
    }

    public function __construct(
        private GdDecoder $gd,
    ) {
    }

    public function decode(string $contents): ?GrayscaleImage
    {
        if (!self::accepts($contents)) {
            return null;
        }

        $bigEndian = str_starts_with($contents, 'MM');
        $tags      = self::tags($contents, $bigEndian);
        $bits      = (array) ($tags['bits'] ?? [1]);
        $samples   = $tags['samples'] ?? 1;
        $extra     = (array) ($tags['extraSamples'] ?? []);

        if (!isset($tags['width'], $tags['height'], $tags['photometric']) || ($tags['planar'] ?? 1)     !== 1
                                                                          || count(array_unique($bits)) !== 1 || count($bits) !== $samples) {
            return null;
        }

        $width        = $tags['width'];
        $height       = $tags['height'];
        $depth        = $bits[0];
        $compression  = $tags['compression'] ?? 1;
        $photometric  = $tags['photometric'];
        $colorSamples = $samples - count($extra);

        if ($compression === 7) {
            return $this->decodeJpeg($contents, $tags, $width, $height, $photometric);
        }

        if (!in_array($compression, [1, 5], true) || !in_array($depth, [8, 16], true) || !isset($tags['stripOffsets'])
                                                  || !in_array($photometric, [0, 1, 2], true) || !in_array($colorSamples, [1, 3], true) || count($extra) > 1) {
            return null;
        }

        $stride    = $width * $samples * intdiv($depth, 8);
        $rows      = array_fill(0, $height, str_repeat("\0", $width));
        $offsets   = (array) $tags['stripOffsets'];
        $counts    = (array) $tags['stripByteCounts'];
        $perStrip  = $tags['rowsPerStrip'] ?? $height;
        $predictor = $tags['predictor']    ?? 1;
        $y         = 0;

        foreach ($offsets as $index => $offset) {
            $strip = substr($contents, $offset, $counts[$index]);

            if ($compression === 5) {
                $strip = self::lzw($strip);
            }

            for ($line = 0; $line < $perStrip && $y < $height; $line++, $y++) {
                $bytes = substr($strip, $line * $stride, $stride);

                if (strlen($bytes) < $stride) {
                    break 2;
                }

                if ($predictor === 2) {
                    $bytes = self::undoPredictor($bytes, $width, $samples, $depth, $bigEndian);
                }

                $rows[$y] = self::unpack($bytes, $width, $samples, $depth, $photometric, $extra[0] ?? 0, $bigEndian);
            }
        }

        return new GrayscaleImage($width, $height, $rows);
    }

    private static function unpack(string $bytes, int $width, int $samples, int $depth, int $photometric, int $alphaKind, bool $bigEndian): string
    {
        $row  = '';
        $size = intdiv($depth, 8);
        $high = $bigEndian ? 0 : 1; // смещение старшего байта 16-битного семпла

        for ($x = 0; $x < $width; $x++) {
            $base = $x * $samples * $size;

            if ($photometric !== 2) {
                if ($depth === 16) {
                    // Pillow: режим I;16, convert('L') обрезает до 255
                    $value = $bigEndian ? unpack('n', $bytes, $base)[1] : unpack('v', $bytes, $base)[1];
                    $row .= chr(min($value, 255));
                } else {
                    $row .= $photometric === 0 ? chr(255 - ord($bytes[$base])) : $bytes[$base];
                }

                continue;
            }

            if ($depth === 8) {
                $row .= chr(GrayscaleImage::luma(ord($bytes[$base]), ord($bytes[$base + 1]), ord($bytes[$base + 2])));
                continue;
            }

            $r = ord($bytes[$base + $high]);
            $g = ord($bytes[$base + 2 + $high]);
            $b = ord($bytes[$base + 4 + $high]);

            if ($alphaKind === 1) {
                // RGBa;16: премультиплицированная альфа делится по старшим байтам, нулевая альфа даёт чёрный
                $a = ord($bytes[$base + 6 + $high]);

                if ($a === 0) {
                    [$r, $g, $b] = [0, 0, 0];
                } elseif ($a !== 255) {
                    $r = min(255, intdiv($r * 255, $a));
                    $g = min(255, intdiv($g * 255, $a));
                    $b = min(255, intdiv($b * 255, $a));
                }
            }

            $row .= chr(GrayscaleImage::luma($r, $g, $b));
        }

        return $row;
    }

    private static function undoPredictor(string $bytes, int $width, int $samples, int $depth, bool $bigEndian): string
    {
        if ($depth === 8) {
            for ($i = $samples, $n = strlen($bytes); $i < $n; $i++) {
                $bytes[$i] = chr((ord($bytes[$i]) + ord($bytes[$i - $samples])) & 0xFF);
            }

            return $bytes;
        }

        $format = $bigEndian ? 'n' : 'v';
        $values = array_values(unpack($format . '*', $bytes));

        for ($i = $samples, $n = count($values); $i < $n; $i++) {
            $values[$i] = ($values[$i] + $values[$i - $samples]) & 0xFFFF;
        }

        return pack($format . '*', ...$values);
    }

    /**
     * TIFF LZW: коды от старшего бита, 9..12 бит, переключение ширины на код раньше на единицу («early change»).
     */
    private static function lzw(string $data): string
    {
        $output    = '';
        $bitPos    = 0;
        $totalBits = strlen($data) * 8;
        $width     = 9;
        $table     = [];
        $next      = 258;
        $previous  = null;

        while ($bitPos + $width <= $totalBits) {
            $code = 0;

            for ($i = 0; $i < $width; $i++, $bitPos++) {
                $code = ($code << 1) | ((ord($data[$bitPos >> 3]) >> (7 - ($bitPos & 7))) & 1);
            }

            if ($code === 256) {
                $width    = 9;
                $next     = 258;
                $previous = null;
                $table    = [];
                continue;
            }

            if ($code === 257) {
                break;
            }

            if ($previous === null) {
                $entry = chr($code);
            } elseif ($code < 256) {
                $entry = chr($code);
            } elseif (isset($table[$code])) {
                $entry = $table[$code];
            } elseif ($code === $next) {
                $entry = $previous . $previous[0];
            } else {
                throw PhashException::unreadableImage();
            }

            $output .= $entry;

            if ($previous !== null) {
                $table[$next++] = $previous . $entry[0];

                if ($next + 1 >= (1 << $width) && $width < 12) {
                    $width++;
                }
            }

            $previous = $entry;
        }

        return $output;
    }

    /**
     * JPEG-сжатие: каждый тайл/полоса — усечённый JPEG без таблиц, таблицы берутся из тега JPEGTables.
     * CMYK декодируется ImageMagick (значения инвертируются обратно, libtiff инверсии не делает), RGB/серый — GD.
     */
    private function decodeJpeg(string $contents, array $tags, int $width, int $height, int $photometric): ?GrayscaleImage
    {
        $tiled = isset($tags['tileOffsets']);

        if (!$tiled && !isset($tags['stripOffsets'])) {
            return null;
        }

        if (!in_array($photometric, [1, 2, 5, 6], true)) {
            return null;
        }

        $offsets    = (array) ($tiled ? $tags['tileOffsets'] : $tags['stripOffsets']);
        $counts     = (array) ($tiled ? $tags['tileByteCounts'] : $tags['stripByteCounts']);
        $tileWidth  = $tiled ? $tags['tileWidth'] : $width;
        $tileLength = $tiled ? $tags['tileLength'] : ($tags['rowsPerStrip'] ?? $height);
        $across     = intdiv($width + $tileWidth - 1, $tileWidth);
        $tables     = isset($tags['jpegTables']) ? substr($tags['jpegTables'], 2, -2) : '';
        $rows       = array_fill(0, $height, str_repeat("\0", $width));

        foreach ($offsets as $index => $offset) {
            $jpeg = substr($contents, $offset, $counts[$index]);
            $jpeg = "\xFF\xD8" . $tables . (str_starts_with($jpeg, "\xFF\xD8") ? substr($jpeg, 2) : $jpeg);
            $tile = $photometric === 5 ? self::cmykTile($jpeg) : $this->gd->decode($jpeg);

            if ($tile === null) {
                return null;
            }

            $left = ($index % $across)      * $tileWidth;
            $top  = intdiv($index, $across) * $tileLength;

            foreach ($tile->rows as $y => $row) {
                if ($top + $y >= $height) {
                    break;
                }

                $rows[$top + $y] = substr_replace($rows[$top + $y], substr($row, 0, min($tileWidth, $width - $left)), $left, min($tileWidth, $width - $left));
            }
        }

        return new GrayscaleImage($width, $height, $rows);
    }

    private static function cmykTile(string $jpeg): ?GrayscaleImage
    {
        $imagick = new Imagick();

        try {
            $imagick->readImageBlob($jpeg);
        } catch (ImagickException) {
            return null;
        }

        $width    = $imagick->getImageWidth();
        $height   = $imagick->getImageHeight();
        $channels = $imagick->exportImagePixels(0, 0, $width, $height, 'CMYK', Imagick::PIXEL_CHAR);
        $rows     = [];

        for ($y = 0; $y < $height; $y++) {
            $row = '';

            for ($x = 0; $x < $width; $x++) {
                $i = ($y * $width + $x) * 4;
                $row .= chr(GrayscaleImage::cmykLuma(255 - $channels[$i], 255 - $channels[$i + 1], 255 - $channels[$i + 2], 255 - $channels[$i + 3]));
            }

            $rows[] = $row;
        }

        return new GrayscaleImage($width, $height, $rows);
    }

    /**
     * @return array<string, mixed> Значения тегов первого IFD: скаляр либо список
     */
    private static function tags(string $contents, bool $bigEndian): array
    {
        $u16  = $bigEndian ? 'n' : 'v';
        $u32  = $bigEndian ? 'N' : 'V';
        $ifd  = unpack($u32, $contents, 4)[1];
        $tags = [];

        for ($i = 0, $count = unpack($u16, $contents, $ifd)[1]; $i < $count; $i++) {
            $entry = $ifd + 2 + $i * 12;
            $tag   = unpack($u16, $contents, $entry)[1];
            $type  = unpack($u16, $contents, $entry + 2)[1];
            $n     = unpack($u32, $contents, $entry + 4)[1];

            if (!isset(self::TAGS[$tag], self::TYPE_SIZES[$type])) {
                continue;
            }

            $size = self::TYPE_SIZES[$type] * $n;
            $data = $size <= 4 ? substr($contents, $entry + 8, $size) : substr($contents, unpack($u32, $contents, $entry + 8)[1], $size);
            $name = self::TAGS[$tag];

            if ($name === 'jpegTables') {
                $tags[$name] = $data;
                continue;
            }

            $values = match ($type) {
                1, 7    => array_values(unpack('C*', $data)),
                3       => array_values(unpack($u16 . '*', $data)),
                4       => array_values(unpack($u32 . '*', $data)),
                default => null,
            };

            if ($values !== null) {
                $tags[$name] = count($values) === 1 ? $values[0] : $values;
            }
        }

        return $tags;
    }
}
