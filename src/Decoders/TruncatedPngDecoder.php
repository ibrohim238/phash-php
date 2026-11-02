<?php

namespace Ibrohim238\Phash\Decoders;

use Ibrohim238\Phash\PhashException;
use Ibrohim238\Phash\GrayscaleImage;
use Ibrohim238\Phash\PhashMathInterface;

/**
 * Обрезанный PNG по логике Pillow с LOAD_TRUNCATED_IMAGES: распаковываются все доступные данные IDAT,
 * заполняются только полностью восстановленные строки, остальное изображение остаётся нулями
 * (для палитровых — индекс 0). Нужен только для файлов, на которых libpng (GD, Imagick) отказывает.
 * Без чересстрочной развёртки.
 */
final readonly class TruncatedPngDecoder implements ImageDecoderInterface
{
    private const SIGNATURE = "\x89PNG\r\n\x1a\n";

    public function __construct(
        private PhashMathInterface $math,
    ) {
    }

    /**
     * Обрезанный файл узнаётся по отсутствию завершающего чанка IEND
     */
    public static function accepts(string $contents): bool
    {
        return str_starts_with($contents, self::SIGNATURE) && !str_contains($contents, 'IEND');
    }

    public function decode(string $contents): ?GrayscaleImage
    {
        if (!self::accepts($contents)) {
            return null;
        }

        $position = 8;
        $length   = strlen($contents);
        $palette  = '';
        $idat     = '';
        $header   = null;

        while ($position + 8 <= $length) {
            $size = unpack('N', $contents, $position)[1];
            $type = substr($contents, $position + 4, 4);
            $data = substr($contents, $position + 8, $size);

            match ($type) {
                'IHDR'  => $header  = unpack('Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', $data),
                'PLTE'  => $palette = $data,
                'IDAT'  => $idat .= $data,
                default => null,
            };

            if ($type === 'IEND') {
                break;
            }

            $position += 12 + $size;
        }

        if ($header === null || $header['interlace'] !== 0) {
            throw PhashException::unreadableImage();
        }

        ['width' => $width, 'height' => $height, 'depth' => $depth, 'color' => $color] = $header;
        $channels                                                                      = match ($color) {
            0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4, default => throw PhashException::unreadableImage()
        };
        $bitsPerPixel = $channels * $depth;
        $rowBytes     = intdiv($width * $bitsPerPixel + 7, 8);
        $pixelBytes   = max(1, intdiv($bitsPerPixel, 8));
        $inflated     = self::inflatePartial($idat);
        $available    = intdiv(strlen($inflated), $rowBytes + 1);
        $paletteLuma  = $color === 3 ? self::paletteLuma($palette) : null;
        $blank        = str_repeat($paletteLuma[0] ?? "\0", $width);
        $rows         = array_fill(0, $height, $blank);
        $count        = min($height, $available);

        if ($count === 0) {
            return new GrayscaleImage($width, $height, $rows);
        }

        $unfiltered = $this->math->pngUnfilter(substr($inflated, 0, $count * ($rowBytes + 1)), $rowBytes, $count, $pixelBytes)
            ?? throw PhashException::unreadableImage();

        if ($depth === 8) {
            $luma = $this->math->pngLuma($unfiltered, $rowBytes, $count, $width, $color, $paletteLuma ?? '');

            for ($y = 0; $y < $count; $y++) {
                $rows[$y] = substr($luma, $y * $width, $width);
            }

            return new GrayscaleImage($width, $height, $rows);
        }

        for ($y = 0; $y < $count; $y++) {
            $rows[$y] = self::luma(substr($unfiltered, $y * ($rowBytes + 1) + 1, $rowBytes), $width, $depth, $color, $paletteLuma);
        }

        return new GrayscaleImage($width, $height, $rows);
    }

    private static function inflatePartial(string $data): string
    {
        $context = inflate_init(ZLIB_ENCODING_DEFLATE);
        $output  = @inflate_add($context, $data, ZLIB_SYNC_FLUSH);

        return $output === false ? '' : $output;
    }

    private static function paletteLuma(string $palette): string
    {
        $luma = str_repeat("\0", 256);

        for ($index = 0, $count = intdiv(strlen($palette), 3); $index < $count; $index++) {
            $luma[$index] = chr(GrayscaleImage::luma(ord($palette[$index * 3]), ord($palette[$index * 3 + 1]), ord($palette[$index * 3 + 2])));
        }

        return $luma;
    }

    /**
     * Строка яркости как у Pillow: 16-битный серый — режим I с обрезкой до 255, остальные 16-битные —
     * старший байт, серый 1/2/4 бита масштабируется до 0..255, палитровые — через палитру.
     */
    private static function luma(string $line, int $width, int $depth, int $color, ?string $paletteLuma): string
    {
        $row = '';

        if ($depth < 8) {
            $perByte = intdiv(8, $depth);
            $mask    = (1 << $depth) - 1;
            $scale   = $color === 3 ? 1 : intdiv(255, $mask);

            for ($x = 0; $x < $width; $x++) {
                $value = (ord($line[intdiv($x, $perByte)]) >> (8 - $depth * ($x % $perByte + 1))) & $mask;
                $row .= $color === 3 ? $paletteLuma[$value] : chr($value * $scale);
            }

            return $row;
        }

        $step = intdiv($depth, 8);

        for ($x = 0; $x < $width; $x++) {
            $base = $x * $step * ($color === 2 ? 3 : ($color === 4 ? 2 : ($color === 6 ? 4 : 1)));

            $row .= match ($color) {
                0       => $depth === 16 ? chr(min(255, unpack('n', $line, $base)[1])) : $line[$base],
                4       => $line[$base],
                3       => $paletteLuma[ord($line[$base])],
                default => chr(GrayscaleImage::luma(ord($line[$base]), ord($line[$base + $step]), ord($line[$base + 2 * $step]))),
            };
        }

        return $row;
    }
}
