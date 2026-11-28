<?php

namespace App\Domain\Keyword;

use Illuminate\Support\Str;

/** Shared "does this text cover this search phrase?" logic, used by recommendations and the keyword advisor. */
final class TopicMatch
{
    /** "add", "create", "set up"... describe the same task; they do not decide whether a page covers a topic. */
    public const ACTION_WORDS = ['add', 'adding', 'create', 'creating', 'make', 'making', 'set', 'setup', 'build', 'use', 'using', 'get', 'enable', 'install', 'start'];

    /** Meaningful words of a phrase: no stopwords, platform word (e.g. "shopify"), years or action words. */
    public static function coreWords(string $keyword, ?string $context): array
    {
        return collect(SiteProfile::words($keyword))
            ->reject(fn ($w) => in_array($w, SiteProfile::STOPWORDS, true) || $w === $context || SiteProfile::isNoise($w)
                || in_array($w, self::ACTION_WORDS, true))
            ->values()->all();
    }

    public static function covers(string $text, string $keyword, ?string $context): bool
    {
        $text = ' '.Str::lower(str_replace(['-', '–', '&'], ' ', $text)).' ';
        $words = self::coreWords($keyword, $context);
        if (! $words) {
            return true;
        }
        foreach ($words as $w) {
            if (! str_contains($text, ' '.preg_replace('/(ies|s)$/', '', $w))) {
                return false;
            }
        }

        return true;
    }
}
