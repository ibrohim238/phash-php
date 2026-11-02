<?php

namespace Ibrohim238\Phash\Decoders;

use Ibrohim238\Phash\PhashException;
use Ibrohim238\Phash\GrayscaleImage;

/**
 * Windows Paint 1.x/2.x (MspImagePlugin.py): битовое изображение, версия «DanM» хранится как есть,
 * «LinS» — построчный RLE с картой длин строк; установленный бит — белый.
 */
final readonly class MspDecoder implements ImageDecoderInterface
{
    private static function accepts(string $contents): bool
    {
        return str_starts_with($contents, 'DanM') || str_starts_with($contents, 'LinS');
    }

    public function decode(string $contents): ?GrayscaleImage
    {
        if (!self::accepts($contents)) {
            return null;
        }

        $checksum = 0;

        for ($i = 0; $i < 32; $i += 2) {
            $checksum ^= unpack('v', $contents, $i)[1];
        }

        if ($checksum !== 0) {
            throw PhashException::unreadableImage();
        }

        $width  = unpack('v', $contents, 4)[1];
        $height = unpack('v', $contents, 6)[1];
        $stride = ($width + 7) >> 3;
        $bits   = str_starts_with($contents, 'DanM') ? substr($contents, 32) : self::decodeRle($contents, $height, $stride);
        $rows   = array_fill(0, $height, str_repeat("\0", $width));

        for ($y = 0; $y < $height; $y++) {
            $line = substr($bits, $y * $stride, $stride);

            if (strlen($line) < $stride) {
                break;
            }

            $row = '';

            for ($x = 0; $x < $width; $x++) {
                $row .= (ord($line[$x >> 3]) >> (7 - ($x & 7))) & 1 ? "\xFF" : "\0";
            }

            $rows[$y] = $row;
        }

        return new GrayscaleImage($width, $height, $rows);
    }

    private static function decodeRle(string $contents, int $height, int $stride): string
    {
        $rowMap   = array_values(unpack("v$height", $contents, 32));
        $position = 32 + $height * 2;
        $bits     = '';

        foreach ($rowMap as $rowLength) {
            if ($rowLength === 0) {
                $bits .= str_repeat("\xFF", $stride);
                continue;
            }

            $row = substr($contents, $position, $rowLength);
            $position += $rowLength;

            if (strlen($row) !== $rowLength) {
                throw PhashException::unreadableImage();
            }

            for ($i = 0; $i < $rowLength;) {
                $runType = ord($row[$i++]);

                if ($runType === 0) {
                    $bits .= str_repeat($row[$i + 1], ord($row[$i]));
                    $i += 2;
                } else {
                    $bits .= substr($row, $i, $runType);
                    $i += $runType;
                }
            }
        }

        return $bits;
    }
}
