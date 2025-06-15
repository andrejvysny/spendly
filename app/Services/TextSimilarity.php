<?php

declare(strict_types=1);

namespace App\Services;

class TextSimilarity
{
    public static function preprocess(string $text): string
    {
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        $text = strtolower($text);
        $text = preg_replace('/[\p{P}]/u', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    public static function similarity(string $a, string $b): float
    {
        $a = self::preprocess($a);
        $b = self::preprocess($b);

        if ($a === '' && $b === '') {
            return 1.0;
        }

        $maxLen = max(strlen($a), strlen($b));
        if ($maxLen === 0) {
            return 0.0;
        }

        $distance = levenshtein($a, $b);

        return 1 - ($distance / $maxLen);
    }
}
