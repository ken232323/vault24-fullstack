<?php
declare(strict_types=1);

namespace Vault24\Support;

/** Money is always an integer number of minor units (2 decimals). No floating point anywhere. */
final class Money
{
    public static function format(int $minor): string
    {
        $sign = $minor < 0 ? '-' : '';
        $abs = abs($minor);
        return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
