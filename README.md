# ibrohim238/phash

Перцептивный хеш изображений на PHP, совпадающий **бит в бит** с эталоном на Python:

```python
imagehash.phash(Image.open(path), hash_size=12, highfreq_factor=12)
```

Эталон — `imagehash` 4.3.1 поверх Pillow 9.4.0 и SciPy 1.10.0. Совпадение проверяется на 75 фикстурах в `tests/Fixtures`.

## Зачем

Библиотека считает тот же хеш на PHP, локально: без Python и без сети.

## Установка

```bash
composer require ibrohim238/phash
```

Нужны расширения `gd`, `imagick` и `phashext`. Первые два ставятся как обычно, `phashext` — собственное расширение из репозитория `phashext`. Собранный файл нужно подключить до `composer install`:

```bash
cp phashext-<версия>-8.5-nts-x86_64.so "$(php-config --extension-dir)/phashext.so"
echo 'extension=phashext.so' > "$(php-config --ini-dir)/99-phashext.ini"
```

Пакет требует `ext-phashext` в `require`, поэтому composer сам проверит, что расширение подключено и его версия подходит.

## Нативная часть

Тяжёлые шаги (яркость, снятие фильтров PNG, Lanczos-ресайз, DCT) вынесены в C++: на PHP они были в 15–100 раз медленнее. Исходники и сборка — в репозитории `phashext`; здесь только PHP-обёртка `PhashMath` над его функциями и тесты, которые гоняют расширение на фикстурах.

## Использование

```php
use Ibrohim238\Phash\GrayscaleImageReader;
use Ibrohim238\Phash\PhashCalculator;
use Ibrohim238\Phash\PhashMath;

$math       = PhashMath::load();
$calculator = new PhashCalculator(new GrayscaleImageReader($math), $math);

$hash = $calculator->calculate(file_get_contents('image.jpg')); // 36 hex-символов
```

При нечитаемом файле бросается `Ibrohim238\Phash\PhashException`.

### Что можно настроить

Параметры хеша — аргументы конструктора, по умолчанию 12 и 12 (у самого `imagehash` это 8 и 4):

```php
new PhashCalculator($reader, $math, hashSize: 8, highfreqFactor: 4);
```

Цепочку декодеров тоже можно задать свою — например, добавить собственный формат:

```php
new GrayscaleImageReader($math, [
    new MyFormatDecoder(),
    ...GrayscaleImageReader::defaultDecoders($math),
]);
```

Порядок значим: чем раньше стоит декодер, тем выше его приоритет.

### Laravel

`PhashServiceProvider` подключается сам через composer-дискавери, регистрировать его руками не нужно. Он связывает синглтонами `PhashMathInterface`, `GrayscaleImageReader` и `PhashCalculator`, так что калькулятор достаточно попросить в конструкторе:

```php
public function __construct(
    private PhashCalculator $calculator,
) {
}
```

`GrayscaleImageReader` синглтоном не для красоты: на каждое создание он собирает цепочку из пятнадцати декодеров.

## Как это работает

1. **Декодирование.** Файл превращается в яркостную карту так же, как `PIL.Image.open(...).convert('L')`.
2. **Ресайз** до 144×144 фильтром Lanczos — портирован из `Resample.c` Pillow.
3. **DCT-II** по столбцам и строкам, как `scipy.fftpack.dct` (pocketfft).
4. Из левого верхнего блока 12×12 биты ставятся по сравнению с медианой.

### Выбор декодера

Формат определяется **по содержимому**, а не по расширению или MIME-типу. Декодеры пробуются по очереди, каждый смотрит сигнатуру и отдаёт `null`, если формат не его.

| Декодер | Зачем свой |
|---|---|
| `GdDecoder` | JPEG, PNG, GIF, WebP, WBMP — GD читает их так же, как Pillow |
| `ImagickDecoder` | последнее звено: CMYK JPEG, 16-битный серый PNG, анимированный WebP, PSD |
| `TruncatedPngDecoder` | PNG без чанка `IEND`: libpng отказывается, Pillow с `LOAD_TRUNCATED_IMAGES` отдаёт восстановленную часть |
| `BmpDecoder`, `TiffDecoder`, `IcoDecoder`, `XbmDecoder` | GD и ImageMagick читают, но **иначе**, чем Pillow |
| `XpmDecoder`, `ImDecoder`, `MspDecoder`, `XvThumbDecoder`, `PixarDecoder`, `FliDecoder`, `SpiderDecoder`, `TgaDecoder` | ни GD, ни ImageMagick этих форматов не читают |

Порядок в `GrayscaleImageReader` значим: сначала те, кто знает тонкости формата, `ImagickDecoder` — последним.

### Известные ограничения

- TIFF со сжатием **packbits** разбирается не своим кодом, а `ImagickDecoder` (результат совпадает с Pillow).
- Чересстрочные (interlaced) обрезанные PNG не поддерживаются.

### Проверено

75 фикстур на 26 комбинациях `hashSize × highfreqFactor` — 1924 сравнения с живым Pillow, расхождений нет. В наборе есть нечётные `hashSize` (3, 5, 7, 9, 11), стороны с множителями 5, 7, 11, 13, 17, 19, 23, границы (`hashSize = 2`, `highfreqFactor = 1`) и крупные размеры вплоть до 512 и 1024-битного хеша.

## Тесты

```bash
composer install
php -d extension=/path/to/phashext/build/phashext-<версия>-8.5-nts-x86_64.so vendor/bin/phpunit
```

`PhashExtTest` проверяет само расширение: несогласованные с данными размеры должны отклоняться исключением, а не уводить C-код за границу буфера.

Ожидаемые хеши в `tests/Fixtures/expected.json` получены от эталона на Python.
