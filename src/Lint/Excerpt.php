<?php

declare(strict_types=1);

namespace Cg\Cms\Lint;

/**
 * A short window of text around a match, for showing the author where it is.
 *
 * Offsets from preg_* are byte offsets, and every character these rules care
 * about (the em-dash, the en-dash, curly quotes) is multi-byte in UTF-8. Slicing
 * by bytes would cut one in half and print a replacement character in the very
 * message telling somebody they used it. mb_strcut takes byte positions but
 * refuses to split a character, which is exactly the combination needed here.
 */
final class Excerpt
{
    /** Bytes of context either side. Roughly a dozen words. */
    private const WINDOW = 40;

    public static function around(string $text, int $byteOffset, int $byteLength): string
    {
        $start = max(0, $byteOffset - self::WINDOW);
        $length = self::WINDOW + $byteLength + self::WINDOW;

        $snippet = mb_strcut($text, $start, $length);
        $snippet = trim(preg_replace('/\s+/u', ' ', $snippet) ?? $snippet);

        if ($start > 0) {
            $snippet = '...'.$snippet;
        }

        if ($start + $length < strlen($text)) {
            $snippet .= '...';
        }

        return $snippet;
    }
}
