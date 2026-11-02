<?php

namespace Ibrohim238\Phash\Decoders;

use Ibrohim238\Phash\PhashException;
use Ibrohim238\Phash\GrayscaleImage;

/**
 * Autodesk FLI/FLC (FliImagePlugin.py, FliDecode.c): первый кадр — палитра из первого цветового подчанка
 * (4 или 11, по умолчанию серая), пиксели собираются подчанками SS2 (7), LC (12), BLACK (13),
 * BRUN (15) и COPY (16) поверх нулевого холста.
 */
final readonly class FliDecoder implements ImageDecoderInterface
{
    private static function accepts(string $contents): bool
    {
        return strlen($contents) >= 128
            && in_array(unpack('v', $contents, 4)[1], [0xAF11, 0xAF12], true)
            && in_array(unpack('v', $contents, 14)[1], [0, 3], true)
            && substr($contents, 20, 2) === "\0\0";
    }

    public function decode(string $contents): ?GrayscaleImage
    {
        if (!self::accepts($contents)) {
            return null;
        }

        $width  = unpack('v', $contents, 8)[1];
        $height = unpack('v', $contents, 10)[1];
        $offset = 128;

        if (unpack('v', $contents, $offset + 4)[1] === 0xF100) {
            $offset += unpack('V', $contents, $offset)[1];
        }

        $palette = self::palette($contents, $offset);
        $pixels  = array_fill(0, $height, str_repeat("\0", $width));

        if (unpack('v', $contents, $offset + 4)[1] !== 0xF1FA) {
            throw PhashException::unreadableImage();
        }

        $chunks   = unpack('v', $contents, $offset + 6)[1];
        $position = $offset + 16;

        for ($c = 0; $c < $chunks; $c++) {
            $advance = unpack('V', $contents, $position)[1];
            $type    = unpack('v', $contents, $position + 4)[1];
            $data    = $position + 6;

            match ($type) {
                7         => self::wordDelta($contents, $data, $pixels, $width, $height),
                12        => self::byteDelta($contents, $data, $pixels, $width, $height),
                13        => $pixels = array_fill(0, $height, str_repeat("\0", $width)),
                15        => self::byteRun($contents, $data, $pixels, $width, $height),
                16        => self::copy($contents, $data, $pixels, $width, $height),
                4, 11, 18 => null,
                default   => throw PhashException::unreadableImage(),
            };

            if ($advance <= 0) {
                throw PhashException::unreadableImage();
            }

            $position += $advance;
        }

        $indexes = implode('', array_map(chr(...), range(0, 255)));

        return new GrayscaleImage($width, $height, array_map(static fn(string $row): string => strtr($row, $indexes, $palette), $pixels));
    }

    /**
     * Яркость 256 индексов: серая палитра по умолчанию, переопределяется первым цветовым подчанком первого кадра.
     */
    private static function palette(string $contents, int $frameOffset): string
    {
        $rgb = [];

        for ($i = 0; $i < 256; $i++) {
            $rgb[$i] = [$i, $i, $i];
        }

        if (unpack('v', $contents, $frameOffset + 4)[1] === 0xF1FA) {
            $position = $frameOffset + 16;

            for ($n = unpack('v', $contents, $frameOffset + 6)[1]; $n > 0; $n--) {
                $size = unpack('V', $contents, $position)[1];
                $type = unpack('v', $contents, $position + 4)[1];

                if (in_array($type, [4, 11], true)) {
                    $shift = $type === 11 ? 2 : 0;
                    $at    = $position + 6;
                    $index = 0;

                    for ($e = unpack('v', $contents, $at)[1], $at += 2; $e > 0; $e--) {
                        $index += ord($contents[$at]);
                        $count = ord($contents[$at + 1]) ?: 256;
                        $at += 2;

                        for ($k = 0; $k < $count; $k++, $at += 3, $index++) {
                            $rgb[$index] = [ord($contents[$at]) << $shift, ord($contents[$at + 1]) << $shift, ord($contents[$at + 2]) << $shift];
                        }
                    }

                    break;
                }

                if (!$size) {
                    break;
                }

                $position += $size;
            }
        }

        $luma = '';

        foreach ($rgb as [$r, $g, $b]) {
            $luma .= chr(GrayscaleImage::luma($r & 0xFF, $g & 0xFF, $b & 0xFF));
        }

        return $luma;
    }

    /** @param array<int, string> $pixels */
    private static function wordDelta(string $c, int $data, array &$pixels, int $width, int $height): void
    {
        $lines = unpack('v', $c, $data)[1];
        $data += 2;

        for ($l = $y = 0; $l < $lines && $y < $height; $l++, $y++) {
            $packets = unpack('v', $c, $data)[1];
            $data += 2;

            while ($packets & 0x8000) {
                if ($packets & 0x4000) {
                    $y += 65536 - $packets;

                    if ($y >= $height) {
                        return;
                    }
                } else {
                    $pixels[$y][$width - 1] = chr($packets & 0xFF);
                }

                $packets = unpack('v', $c, $data)[1];
                $data += 2;
            }

            for ($p = $x = 0; $p < $packets; $p++) {
                $x += ord($c[$data]);

                if (ord($c[$data + 1]) >= 128) {
                    $i = 256 - ord($c[$data + 1]);

                    if ($x + $i + $i > $width) {
                        break;
                    }

                    $pixels[$y] = substr_replace($pixels[$y], str_repeat(substr($c, $data + 2, 2), $i), $x, $i * 2);
                    $x    += $i * 2;
                    $data += 4;
                } else {
                    $i = 2 * ord($c[$data + 1]);

                    if ($x + $i > $width) {
                        break;
                    }

                    $pixels[$y] = substr_replace($pixels[$y], substr($c, $data + 2, $i), $x, $i);
                    $data += 2 + $i;
                    $x    += $i;
                }
            }

            if ($p < $packets) {
                return;
            }
        }
    }

    /** @param array<int, string> $pixels */
    private static function byteDelta(string $c, int $data, array &$pixels, int $width, int $height): void
    {
        $y    = unpack('v', $c, $data)[1];
        $ymax = $y + unpack('v', $c, $data + 2)[1];
        $data += 4;

        for (; $y < $ymax && $y < $height; $y++) {
            $packets = ord($c[$data++]);

            for ($p = $x = 0; $p < $packets; $p++, $x += $i) {
                $x += ord($c[$data]);

                if (ord($c[$data + 1]) & 0x80) {
                    $i = 256 - ord($c[$data + 1]);

                    if ($x + $i > $width) {
                        break;
                    }

                    $pixels[$y] = substr_replace($pixels[$y], str_repeat($c[$data + 2], $i), $x, $i);
                    $data += 3;
                } else {
                    $i = ord($c[$data + 1]);

                    if ($x + $i > $width) {
                        break;
                    }

                    $pixels[$y] = substr_replace($pixels[$y], substr($c, $data + 2, $i), $x, $i);
                    $data += $i + 2;
                }
            }

            if ($p < $packets) {
                return;
            }
        }
    }

    /** @param array<int, string> $pixels */
    private static function byteRun(string $c, int $data, array &$pixels, int $width, int $height): void
    {
        for ($y = 0; $y < $height; $y++) {
            $data++;

            for ($x = 0; $x < $width; $x += $i) {
                if (ord($c[$data]) & 0x80) {
                    $i = 256 - ord($c[$data]);

                    if ($x + $i > $width) {
                        break;
                    }

                    $pixels[$y] = substr_replace($pixels[$y], substr($c, $data + 1, $i), $x, $i);
                    $data += $i + 1;
                } else {
                    $i = ord($c[$data]);

                    if ($x + $i > $width) {
                        break;
                    }

                    $pixels[$y] = substr_replace($pixels[$y], str_repeat($c[$data + 1], $i), $x, $i);
                    $data += 2;
                }
            }

            if ($x !== $width) {
                return;
            }
        }
    }

    /** @param array<int, string> $pixels */
    private static function copy(string $c, int $data, array &$pixels, int $width, int $height): void
    {
        for ($y = 0; $y < $height; $y++) {
            $pixels[$y] = substr($c, $data + $y * $width, $width);
        }
    }
}
