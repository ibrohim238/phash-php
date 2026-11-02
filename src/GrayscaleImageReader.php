<?php

namespace Ibrohim238\Phash;

use Ibrohim238\Phash\Decoders\BmpDecoder;
use Ibrohim238\Phash\Decoders\FliDecoder;
use Ibrohim238\Phash\Decoders\GdDecoder;
use Ibrohim238\Phash\Decoders\IcoDecoder;
use Ibrohim238\Phash\Decoders\ImageDecoderInterface;
use Ibrohim238\Phash\Decoders\ImagickDecoder;
use Ibrohim238\Phash\Decoders\ImDecoder;
use Ibrohim238\Phash\Decoders\MspDecoder;
use Ibrohim238\Phash\Decoders\PixarDecoder;
use Ibrohim238\Phash\Decoders\SpiderDecoder;
use Ibrohim238\Phash\Decoders\TgaDecoder;
use Ibrohim238\Phash\Decoders\TiffDecoder;
use Ibrohim238\Phash\Decoders\TruncatedPngDecoder;
use Ibrohim238\Phash\Decoders\XbmDecoder;
use Ibrohim238\Phash\Decoders\XpmDecoder;
use Ibrohim238\Phash\Decoders\XvThumbDecoder;

/**
 * Читает файл изображения в оттенки серого цепочкой декодеров: первым идут форматы, которые GD и ImageMagick
 * читают не так, как Pillow, или не читают вовсе, затем GD для основных форматов, последним — ImageMagick.
 */
final readonly class GrayscaleImageReader
{
    /** @var array<int, ImageDecoderInterface> */
    private array $decoders;

    /**
     * @param array<int, ImageDecoderInterface>|null $decoders Своя цепочка; null — стандартная из defaultDecoders()
     */
    public function __construct(PhashMathInterface $math, ?array $decoders = null)
    {
        $this->decoders = $decoders ?? self::defaultDecoders($math);
    }

    /**
     * Порядок значим: сначала те, кто знает тонкости формата и совпадает с Pillow,
     * затем GD для основных форматов, последним ImageMagick — «прочитай хоть как-нибудь».
     *
     * @return array<int, ImageDecoderInterface>
     */
    public static function defaultDecoders(PhashMathInterface $math): array
    {
        $gd  = new GdDecoder($math);
        $bmp = new BmpDecoder();

        return [
            $bmp,
            new IcoDecoder($gd, $bmp),
            new XbmDecoder(),
            new XpmDecoder(),
            new ImDecoder(),
            new MspDecoder(),
            new XvThumbDecoder(),
            new PixarDecoder(),
            new FliDecoder(),
            new TiffDecoder($gd),
            $gd,
            new TruncatedPngDecoder($math),
            new TgaDecoder(),
            new SpiderDecoder(),
            new ImagickDecoder(),
        ];
    }

    /**
     * @throws PhashException
     */
    public function read(string $contents): GrayscaleImage
    {
        foreach ($this->decoders as $decoder) {
            $image = $decoder->decode($contents);

            if ($image !== null) {
                return $image;
            }
        }

        throw PhashException::unreadableImage();
    }
}
