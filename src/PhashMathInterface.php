<?php

namespace Ibrohim238\Phash;

/**
 * Тяжёлые шаги расчёта pHash: перевод пикселей в яркость, Lanczos-ресайз как в Pillow, DCT-II как в pocketfft.
 * Любая реализация обязана давать результат, совпадающий с эталоном на Python бит в бит.
 */
interface PhashMathInterface
{
    /**
     * @param string $argb Пиксели формата .gd (imagegd): 4 байта big-endian A R G B на пиксель
     * @return string По одному байту яркости на пиксель
     */
    public function luma(string $argb): string;

    public function resize(GrayscaleImage $image, int $width, int $height): GrayscaleImage;

    /**
     * Блок hashSize×hashSize низких частот двумерного DCT-II квадратного изображения.
     *
     * @return array<int, array<int, float>>
     */
    public function dct(GrayscaleImage $image, int $hashSize): array;

    /**
     * Снимает построчные фильтры PNG (RFC 2083, 6).
     *
     * @param string $data Распакованные строки подряд, по (1 + $rowBytes) байт: первый байт строки — тип фильтра
     * @return string|null Те же строки без фильтров; null — неизвестный тип фильтра
     */
    public function pngUnfilter(string $data, int $rowBytes, int $rows, int $pixelBytes): ?string;

    /**
     * Яркость строк 8-битного PNG.
     *
     * @param string $data Строки после pngUnfilter()
     * @param int $color Тип цвета PNG: 0 серый, 2 RGB, 3 палитра, 4 серый с альфой, 6 RGBA
     * @param string $paletteLuma Для палитровых — яркость каждого из 256 индексов палитры
     * @return string По одному байту яркости на пиксель, $width байт на строку
     */
    public function pngLuma(string $data, int $rowBytes, int $rows, int $width, int $color, string $paletteLuma): string;
}
