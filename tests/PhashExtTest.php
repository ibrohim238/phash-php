<?php

namespace Ibrohim238\Phash\Tests;

use Exception;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Функции phashext доступны любому коду в процессе, поэтому размеры, не согласованные с длиной строки,
 * должны отклоняться исключением, а не уводить C-код за границу буфера.
 */
final class PhashExtTest extends TestCase
{
    #[Test]
    #[DataProvider('inconsistentCalls')]
    public function inconsistent_sizes_throw(callable $call): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('размеры не согласованы с данными');

        $call();
    }

    /**
     * @return iterable<string, array{callable}>
     */
    public static function inconsistentCalls(): iterable
    {
        yield 'unfilter: строк больше, чем данных' => [fn() => phashext_png_unfilter('x', 1073741824, 4, 4)];
        yield 'unfilter: отрицательная длина строки' => [fn() => phashext_png_unfilter("\0\0\0", -1, 1, 1)];
        yield 'unfilter: нулевой размер пикселя' => [fn() => phashext_png_unfilter("\0\0\0", 2, 1, 0)];
        yield 'luma: строк больше, чем данных' => [fn() => phashext_png_luma("\0\0\0", 2, 1000, 2, 0, '')];
        yield 'luma: пиксели шире строки' => [fn() => phashext_png_luma("\0\0\0", 2, 1, 1, 6, '')];
        yield 'resize: картинка больше данных' => [fn() => phashext_resize("\0\0\0\0", 1000, 1000, 8, 8)];
        yield 'resize: нулевая ширина результата' => [fn() => phashext_resize("\0\0\0\0", 2, 2, 0, 8)];
        yield 'resize: нулевая высота результата' => [fn() => phashext_resize("\0\0\0\0", 2, 2, 8, 0)];
        yield 'dct: картинка больше данных' => [fn() => phashext_dct("\0\0\0\0", 1000, 8)];
        yield 'dct: хеш больше картинки' => [fn() => phashext_dct("\0\0\0\0", 2, 8)];
    }

    #[Test]
    public function consistent_sizes_are_processed(): void
    {
        $pixels = str_repeat("\x80", 16);

        $resized = phashext_resize($pixels, 4, 4, 2, 2);

        $this->assertSame(str_repeat("\x80", 4), $resized);
    }
}
