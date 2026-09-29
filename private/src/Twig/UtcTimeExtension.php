<?php

declare(strict_types=1);

namespace App\Twig;

use App\Support\UtcTime;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class UtcTimeExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('utc_time', [UtcTime::class, 'markup'], ['is_safe' => ['html']]),
        ];
    }
}
