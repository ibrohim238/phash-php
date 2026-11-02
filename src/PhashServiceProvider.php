<?php

namespace Ibrohim238\Phash;

use Illuminate\Support\ServiceProvider;

/**
 * GrayscaleImageReader синглтоном потому, что на каждое создание собирает цепочку из пятнадцати декодеров.
 */
final class PhashServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PhashMathInterface::class, static fn(): PhashMath => PhashMath::load());
        $this->app->singleton(GrayscaleImageReader::class);
        $this->app->singleton(PhashCalculator::class);
    }
}
