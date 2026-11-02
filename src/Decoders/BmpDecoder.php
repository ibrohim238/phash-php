<?php

namespace Ibrohim238\Phash\Decoders;

use Ibrohim238\Phash\PhashException;
use Ibrohim238\Phash\GrayscaleImage;

/**
 * BMP/DIB по логике Pillow 9.4.0 (BmpImagePlugin.py, Unpack.c): палитровые 1/4/8 бит, 16 бит (555/565),
 * 24/32 бита с масками каналов, RLE4/RLE8, порядок строк снизу вверх или сверху вниз.
 * GD и ImageMagick читают 16-битные и RLE4-варианты иначе, поэтому формат разбирается вручную.
 */
final readonly class BmpDecoder implements ImageDecoderInterface
{
    private const COMPRESSION_RAW = 0;

    private const COMPRESSION_RLE8 = 1;

    private const COMPRESSION_RLE4 = 2;

    private const COMPRESSION_BITFIELDS = 3;

    /** Поддерживаемые Pillow раскладки масок для BI_BITFIELDS → порядок байтов пикселя */
    private const MASK_MODES = [
        32 => [
            '16711680,65280,255,0'          => 'BGRX',
            '4278190080,16711680,65280,0'   => 'XBGR',
            '4278190080,16711680,65280,255' => 'ABGR',
            '255,65280,16711680,4278190080' => 'RGBA',
            '16711680,65280,255,4278190080' => 'BGRA',
            '0,0,0,0'                       => 'BGRA',
        ],
        24 => ['16711680,65280,255' => 'BGR'],
        16 => ['63488,2016,31' => 'BGR;16', '31744,992,31' => 'BGR;15'],
    ];

    public function decode(string $contents): ?GrayscaleImage
    {
        if (!str_starts_with($contents, 'BM')) {
            return null;
        }

        return self::decodeBitmap($contents, 14, self::u32($contents, 10));
    }

    /**
     * DIB внутри ICO: заголовок без 14-байтного префикса файла, высота удвоена (XOR-битмап + AND-маска).
     */
    public function decodeDib(string $contents, int $start): GrayscaleImage
    {
        return self::decodeBitmap($contents, $start, 0, true);
    }

    private static function decodeBitmap(string $contents, int $headerStart, int $dataOffset, bool $halveHeight = false): GrayscaleImage
    {
        $headerSize = self::u32($contents, $headerStart);
        $header     = substr($contents, $headerStart + 4, $headerSize - 4);
        $position   = $headerStart + $headerSize;
        $direction  = -1;
        $masks      = null;

        if ($headerSize === 12) {
            $width          = self::u16($header, 0);
            $height         = self::u16($header, 2);
            $bits           = self::u16($header, 6);
            $compression    = self::COMPRESSION_RAW;
            $colors         = 0;
            $palettePadding = 3;
        } elseif (in_array($headerSize, [40, 64, 108, 124], true)) {
            $yFlip          = ord($header[7]) === 0xFF;
            $direction      = $yFlip ? 1 : -1;
            $width          = self::u32($header, 0);
            $height         = $yFlip ? 2 ** 32 - self::u32($header, 4) : self::u32($header, 4);
            $bits           = self::u16($header, 10);
            $compression    = self::u32($header, 12);
            $colors         = self::u32($header, 28);
            $palettePadding = 4;

            if ($compression === self::COMPRESSION_BITFIELDS) {
                if (strlen($header) >= 52) {
                    $masks = [self::u32($header, 36), self::u32($header, 40), self::u32($header, 44), self::u32($header, 48)];
                } else {
                    $masks = [self::u32($contents, $position), self::u32($contents, $position + 4), self::u32($contents, $position + 8), 0];
                    $position += 12;
                }
            }
        } else {
            throw PhashException::unreadableImage();
        }

        $colors = $colors ?: 1 << $bits;

        if ($dataOffset === 14 + $headerSize && $bits <= 8) {
            $dataOffset += 4 * $colors;
        }

        $rawMode = match ($bits) {
            1       => 'P;1',
            4       => 'P;4',
            8       => 'P',
            16      => 'BGR;15',
            24      => 'BGR',
            32      => 'BGRX',
            default => throw PhashException::unreadableImage(),
        };

        if ($compression === self::COMPRESSION_BITFIELDS) {
            $key     = implode(',', $bits === 32 ? $masks : array_slice($masks, 0, 3));
            $rawMode = self::MASK_MODES[$bits][$key] ?? throw PhashException::unreadableImage();
        } elseif (!in_array($compression, [self::COMPRESSION_RAW, self::COMPRESSION_RLE8, self::COMPRESSION_RLE4], true)) {
            throw PhashException::unreadableImage();
        }

        $paletteLuma = null;

        if ($bits <= 8) {
            if ($colors < 1 || $colors > 65536) {
                throw PhashException::unreadableImage();
            }

            $palette = substr($contents, $position, $palettePadding * $colors);
            $position += $palettePadding * $colors;
            $paletteLuma = self::paletteLuma($palette, $palettePadding, $colors, $rawMode);
        }

        if ($halveHeight) {
            $height = intdiv($height, 2);
        }

        $dataOffset = $dataOffset ?: $position;

        if (in_array($compression, [self::COMPRESSION_RLE8, self::COMPRESSION_RLE4], true)) {
            $indexes = self::decodeRle($contents, $dataOffset, $width, $height, $compression === self::COMPRESSION_RLE4);
            $rows    = self::rowsFromIndexes($indexes, $width, $height, $direction, $paletteLuma);
        } else {
            $stride = (($width * $bits + 31) >> 3) & ~3;
            $rows   = self::rowsFromRaw($contents, $dataOffset, $width, $height, $stride, $direction, $rawMode, $paletteLuma);
        }

        return new GrayscaleImage($width, $height, $rows);
    }

    /**
     * Палитра BMP: если все записи серые (i, i, i), Pillow читает пиксели как яркость напрямую (режимы «1» и «L»),
     * иначе как индексы палитры. Возвращает таблицу «индекс → яркость» (256 байт) либо null для прямой яркости.
     */
    private static function paletteLuma(string $palette, int $padding, int $colors, string &$rawMode): ?string
    {
        $indexes   = $colors === 2 ? [0, 255] : range(0, $colors - 1);
        $greyscale = true;

        foreach ($indexes as $index => $value) {
            if (substr($palette, $index * $padding, 3) !== str_repeat(chr($value), 3)) {
                $greyscale = false;
                break;
            }
        }

        if ($greyscale) {
            $rawMode = $colors === 2 ? '1' : 'L';

            return null;
        }

        $luma = str_repeat("\0", 256);

        for ($index = 0; $index < min($colors, 256); $index++) {
            $blue         = ord($palette[$index * $padding]);
            $green        = ord($palette[$index * $padding + 1]);
            $red          = ord($palette[$index * $padding + 2]);
            $luma[$index] = chr(GrayscaleImage::luma($red, $green, $blue));
        }

        return $luma;
    }

    /**
     * @return array<int, string>
     */
    private static function rowsFromRaw(string $contents, int $offset, int $width, int $height, int $stride, int $direction, string $rawMode, ?string $paletteLuma): array
    {
        $rows = array_fill(0, $height, str_repeat("\0", $width));

        for ($line = 0; $line < $height; $line++) {
            $data = substr($contents, $offset + $line * $stride, $stride);

            if (strlen($data) < $stride) {
                break;
            }

            $y        = $direction === 1 ? $line : $height - 1 - $line;
            $rows[$y] = self::unpackRow($data, $width, $rawMode, $paletteLuma);
        }

        return $rows;
    }

    private static function unpackRow(string $data, int $width, string $rawMode, ?string $paletteLuma): string
    {
        $row = '';

        switch ($rawMode) {
            case '1':
            case 'P;1':
                for ($x = 0; $x < $width; $x++) {
                    $bit = (ord($data[$x >> 3]) >> (7 - ($x & 7))) & 1;
                    $row .= $rawMode === '1' ? ($bit ? "\xFF" : "\0") : $paletteLuma[$bit];
                }
                break;
            case 'P;4':
                for ($x = 0; $x < $width; $x++) {
                    $byte = ord($data[$x >> 1]);
                    $row .= $paletteLuma[($x & 1) ? $byte & 15 : ($byte >> 4) & 15];
                }
                break;
            case 'L':
                $row = substr($data, 0, $width);
                break;
            case 'P':
                $row = strtr(substr($data, 0, $width), self::indexBytes(), $paletteLuma);
                break;
            case 'BGR;15':
            case 'BGR;16':
                for ($x = 0; $x < $width; $x++) {
                    $pixel = ord($data[$x * 2]) + (ord($data[$x * 2 + 1]) << 8);
                    $blue  = intdiv(($pixel & 31) * 255, 31);

                    if ($rawMode === 'BGR;16') {
                        $green = intdiv((($pixel >> 5) & 63) * 255, 63);
                        $red   = intdiv((($pixel >> 11) & 31) * 255, 31);
                    } else {
                        $green = intdiv((($pixel >> 5) & 31) * 255, 31);
                        $red   = intdiv((($pixel >> 10) & 31) * 255, 31);
                    }

                    $row .= chr(GrayscaleImage::luma($red, $green, $blue));
                }
                break;
            default:
                [$size, $redAt, $greenAt, $blueAt] = match ($rawMode) {
                    'BGR'  => [3, 2, 1, 0],
                    'BGRX' => [4, 2, 1, 0],
                    'BGRA' => [4, 2, 1, 0],
                    'XBGR' => [4, 3, 2, 1],
                    'ABGR' => [4, 3, 2, 1],
                    'RGBA' => [4, 0, 1, 2],
                };

                for ($x = 0; $x < $width; $x++) {
                    $base = $x * $size;
                    $row .= chr(GrayscaleImage::luma(ord($data[$base + $redAt]), ord($data[$base + $greenAt]), ord($data[$base + $blueAt])));
                }
        }

        return $row;
    }

    /**
     * BmpRleDecoder из Pillow: возвращает индексы пикселей (по байту на пиксель) подряд, без выравнивания строк;
     * недостающие пиксели остаются нулями, как в непроинициализированном изображении Pillow.
     */
    private static function decodeRle(string $contents, int $offset, int $width, int $height, bool $rle4): string
    {
        $total    = $width * $height;
        $data     = '';
        $x        = 0;
        $position = $offset;
        $length   = strlen($contents);

        while (strlen($data) < $total) {
            if ($position + 2 > $length) {
                break;
            }

            $pixels = ord($contents[$position]);
            $byte   = ord($contents[$position + 1]);
            $position += 2;

            if ($pixels) {
                if ($x + $pixels > $width) {
                    $pixels = max(0, $width - $x);
                }

                if ($rle4) {
                    $pair = chr($byte >> 4) . chr($byte & 0x0F);
                    $data .= substr(str_repeat($pair, intdiv($pixels + 1, 2)), 0, $pixels);
                } else {
                    $data .= str_repeat(chr($byte), $pixels);
                }

                $x += $pixels;
            } elseif ($byte === 0) {
                if (strlen($data) % $width !== 0) {
                    $data .= str_repeat("\0", $width - strlen($data) % $width);
                }

                $x = 0;
            } elseif ($byte === 1) {
                break;
            } elseif ($byte === 2) {
                // Pillow 9.4 читает дельту дважды: первые два байта отбрасываются, смещение берётся из следующих
                if ($position + 4 > $length) {
                    break;
                }

                $right = ord($contents[$position + 2]);
                $up    = ord($contents[$position + 3]);
                $position += 4;
                $data .= str_repeat("\0", $right + $up * $width);
                $x = strlen($data) % $width;
            } else {
                $byteCount = $rle4 ? intdiv($byte, 2) : $byte;
                $chunk     = substr($contents, $position, $byteCount);
                $position += strlen($chunk);

                if ($rle4) {
                    foreach (str_split($chunk) as $read) {
                        $data .= chr(ord($read) >> 4) . chr(ord($read) & 0x0F);
                    }
                } else {
                    $data .= $chunk;
                }

                if (strlen($chunk) < $byteCount) {
                    break;
                }

                $x += $byte;

                if ($position % 2 !== 0) {
                    $position++;
                }
            }
        }

        return substr(str_pad($data, $total, "\0"), 0, $total);
    }

    /**
     * @return array<int, string>
     */
    private static function rowsFromIndexes(string $indexes, int $width, int $height, int $direction, ?string $paletteLuma): array
    {
        $rows = [];

        for ($line = 0; $line < $height; $line++) {
            $data                                                 = substr($indexes, $line * $width, $width);
            $rows[$direction === 1 ? $line : $height - 1 - $line] = $paletteLuma === null ? $data : strtr($data, self::indexBytes(), $paletteLuma);
        }

        ksort($rows);

        return array_values($rows);
    }

    private static function indexBytes(): string
    {
        return implode('', array_map(chr(...), range(0, 255)));
    }

    private static function u16(string $data, int $offset): int
    {
        return unpack('v', $data, $offset)[1];
    }

    private static function u32(string $data, int $offset): int
    {
        return unpack('V', $data, $offset)[1];
    }
}
