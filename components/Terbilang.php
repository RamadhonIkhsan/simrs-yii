<?php

declare(strict_types=1);

namespace app\components;

class Terbilang
{
    private static array $words = [
        '', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'
    ];

    public static function rupiah(string|float|int $amount): string
    {
        $rounded = (int)round((float)$amount);
        if ($rounded === 0) {
            return 'nol rupiah';
        }

        if ($rounded < 0) {
            return 'minus ' . self::convert(abs($rounded)) . ' rupiah';
        }

        return self::convert($rounded) . ' rupiah';
    }

    private static function convert(int $n): string
    {
        if ($n < 12) {
            return self::$words[$n];
        }
        if ($n < 20) {
            return self::convert($n - 10) . ' belas';
        }
        if ($n < 100) {
            $ten = (int)($n / 10);
            $rem = $n % 10;
            return self::convert($ten) . ' puluh' . ($rem > 0 ? ' ' . self::convert($rem) : '');
        }
        if ($n < 200) {
            $rem = $n - 100;
            return 'seratus' . ($rem > 0 ? ' ' . self::convert($rem) : '');
        }
        if ($n < 1000) {
            $hundred = (int)($n / 100);
            $rem = $n % 100;
            return self::convert($hundred) . ' ratus' . ($rem > 0 ? ' ' . self::convert($rem) : '');
        }
        if ($n < 2000) {
            $rem = $n - 1000;
            return 'seribu' . ($rem > 0 ? ' ' . self::convert($rem) : '');
        }
        if ($n < 1000000) {
            $thousand = (int)($n / 1000);
            $rem = $n % 1000;
            return self::convert($thousand) . ' ribu' . ($rem > 0 ? ' ' . self::convert($rem) : '');
        }
        if ($n < 1000000000) {
            $million = (int)($n / 1000000);
            $rem = $n % 1000000;
            return self::convert($million) . ' juta' . ($rem > 0 ? ' ' . self::convert($rem) : '');
        }
        if ($n < 1000000000000) {
            $billion = (int)($n / 1000000000);
            $rem = $n % 1000000000;
            return self::convert($billion) . ' miliar' . ($rem > 0 ? ' ' . self::convert($rem) : '');
        }

        $trillion = (int)($n / 1000000000000);
        $rem = $n % 1000000000000;
        return self::convert($trillion) . ' triliun' . ($rem > 0 ? ' ' . self::convert($rem) : '');
    }
}
