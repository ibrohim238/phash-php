<?php

namespace Ibrohim238\Phash\Decoders;

use Ibrohim238\Phash\PhashException;
use Ibrohim238\Phash\GrayscaleImage;

/**
 * Собственный формат Pillow «IM» (ImImagePlugin.py): текстовый заголовок «Ключ: значение» до байта 0x1A,
 * затем сырые пиксели первого кадра, строки идут снизу вверх. Поддержаны режимы, к которым
 * сводится convert('L'): серый, битовый, RGB (построчно чередующиеся плоскости), RGBA/RGBX, LA, палитра.
 */
final readonly class ImDecoder implements ImageDecoderInterface
{
    /** «Image type» → распаковщик (rawmode Pillow) */
    private const TYPES = [
        'Greyscale image' => 'L',
        'Grayscale image' => 'L',
        'L 8 image'       => 'L',
        '0 1 image'       => '1',
        'L 1 image'       => '1',
        'B1 image'        => '1',
        'RGB image'       => 'RGB;L',
        'X 24 image'      => 'RGB',
        'RGBA image'      => 'RGBA;L',
        'RGBX image'      => 'RGBA;L',
        'LA image'        => 'LA;L',
    ];

    private static function accepts(string $contents): bool
    {
        return (bool) preg_match('/^Image type: /', $contents);
    }

    public function decode(string $contents): ?GrayscaleImage
    {
        if (!self::accepts($contents)) {
            return null;
        }

        $end = strpos($contents, "\x1A");

        if ($end === false || !preg_match('/^Image type:[ \t]*(.*?)[ \t]*\r?$/m', substr($contents, 0, $end), $type)
                           || !preg_match('/^Image size \(x\*y\):[ \t]*([0-9]+)\*([0-9]+)/m', substr($contents, 0, $end), $size)) {
            throw PhashException::unreadableImage();
        }

        $rawMode = self::TYPES[$type[1]] ?? throw PhashException::unreadableImage();
        $width   = (int) $size[1];
        $height  = (int) $size[2];
        $offset  = $end + 1;
        $palette = null;

        if (preg_match('/^Lut:/m', substr($contents, 0, $end))) {
            $lut = substr($contents, $offset, 768);
            $offset += 768;
            $greyscale = true;

            for ($i = 0; $i < 256; $i++) {
                if ($lut[$i] !== $lut[$i + 256] || $lut[$i] !== $lut[$i + 512]) {
                    $greyscale = false;
                    break;
                }
            }

            if (!$greyscale && $rawMode === 'L') {
                $palette = '';

                for ($i = 0; $i < 256; $i++) {
                    $palette .= chr(GrayscaleImage::luma(ord($lut[$i]), ord($lut[$i + 256]), ord($lut[$i + 512])));
                }
            }
        }

        $stride = match ($rawMode) {
            '1'            => ($width + 7) >> 3,
            'L'            => $width,
            'LA;L'         => $width * 2,
            'RGB', 'RGB;L' => $width * 3,
            'RGBA;L'       => $width * 4,
        };
        $rows = array_fill(0, $height, str_repeat("\0", $width));

        for ($line = 0; $line < $height; $line++) {
            $data = substr($contents, $offset + $line * $stride, $stride);

            if (strlen($data) < $stride) {
                break;
            }

            $rows[$height - 1 - $line] = self::unpack($data, $width, $rawMode, $palette);
        }

        return new GrayscaleImage($width, $height, $rows);
    }

    private static function unpack(string $data, int $width, string $rawMode, ?string $palette): string
    {
        $row = '';

        switch ($rawMode) {
            case '1':
                for ($x = 0; $x < $width; $x++) {
                    $row .= (ord($data[$x >> 3]) >> (7 - ($x & 7))) & 1 ? "\xFF" : "\0";
                }
                break;
            case 'L':
                $row = $palette === null ? $data : strtr($data, implode('', array_map(chr(...), range(0, 255))), $palette);
                break;
            case 'LA;L':
                $row = substr($data, 0, $width);
                break;
            case 'RGB;L':
            case 'RGBA;L':
                for ($x = 0; $x < $width; $x++) {
                    $row .= chr(GrayscaleImage::luma(ord($data[$x]), ord($data[$x + $width]), ord($data[$x + 2 * $width])));
                }
                break;
            case 'RGB':
                for ($x = 0; $x < $width; $x++) {
                    $row .= chr(GrayscaleImage::luma(ord($data[$x * 3]), ord($data[$x * 3 + 1]), ord($data[$x * 3 + 2])));
                }
                break;
        }

        return $row;
    }
}
