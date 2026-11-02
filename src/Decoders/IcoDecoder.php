<?php

namespace Ibrohim238\Phash\Decoders;

use Ibrohim238\Phash\PhashException;
use Ibrohim238\Phash\GrayscaleImage;

/**
 * ICO по логике Pillow 9.4.0 (IcoImagePlugin.py): берётся самая большая по площади иконка
 * (при равной площади — с наибольшей глубиной цвета), PNG-кадр читается как PNG, иначе DIB с удвоенной высотой.
 * AND-маска даёт только альфу и на яркость не влияет.
 */
final readonly class IcoDecoder implements ImageDecoderInterface
{
    public function __construct(
        private GdDecoder $gd,
        private BmpDecoder $bmp,
    ) {
    }

    public function decode(string $contents): ?GrayscaleImage
    {
        if (!str_starts_with($contents, "\0\0\1\0")) {
            return null;
        }

        $entries = [];

        for ($i = 0, $count = unpack('v', $contents, 4)[1]; $i < $count; $i++) {
            $header = substr($contents, 6 + $i * 16, 16);
            $width  = ord($header[0]) ?: 256;
            $height = ord($header[1]) ?: 256;
            $colors = ord($header[2]);
            $bpp    = unpack('v', $header, 6)[1];

            $entries[] = [
                'order'  => $i,
                'depth'  => $bpp ?: ($colors !== 0 ? (int) ceil(log($colors, 2)) : 256),
                'square' => $width * $height,
                'size'   => unpack('V', $header, 8)[1],
                'offset' => unpack('V', $header, 12)[1],
            ];
        }

        // sorted(depth) → sorted(square) → reverse(): Pillow выбирает entry[0]
        usort($entries, static fn(array $a, array $b): int => [$b['square'], $b['depth'], $b['order']] <=> [$a['square'], $a['depth'], $a['order']]);
        $entry = $entries[0] ?? throw PhashException::unreadableImage();

        if (substr($contents, $entry['offset'], 8) === "\x89PNG\r\n\x1a\n") {
            return $this->gd->decode(substr($contents, $entry['offset'], $entry['size'])) ?? throw PhashException::unreadableImage();
        }

        return $this->bmp->decodeDib($contents, $entry['offset']);
    }
}
