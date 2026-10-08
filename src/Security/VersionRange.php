<?php

declare(strict_types=1);

namespace Cg\Cms\Security;

/**
 * Whether a version falls inside an advisory's affected range.
 *
 * Packagist writes ranges like ">=1.3.0,<=2.10.1|>=3.0.0,<3.0.4": groups
 * joined by | (any may match), each a list of comparisons joined by commas
 * or spaces (all must match). That small grammar is all advisories use, so
 * it is matched here rather than pulling composer/semver into production.
 */
final class VersionRange
{
    public static function matches(string $version, string $range): bool
    {
        $version = self::normalise($version);

        if ($version === '' || trim($range) === '') {
            return false;
        }

        foreach (preg_split('/\s*\|\|?\s*/', trim($range)) ?: [] as $group) {
            $constraints = preg_split('/\s*,\s*|\s+/', trim($group)) ?: [];
            $all = $constraints !== [];

            foreach ($constraints as $constraint) {
                if (! self::satisfies($version, $constraint)) {
                    $all = false;
                    break;
                }
            }

            if ($all) {
                return true;
            }
        }

        return false;
    }

    private static function satisfies(string $version, string $constraint): bool
    {
        if ($constraint === '*' || $constraint === '') {
            return true;
        }

        if (preg_match('/^(>=|<=|>|<|==|=|!=)?\s*(.+)$/', $constraint, $match) !== 1) {
            return false;
        }

        $operator = $match[1] === '' || $match[1] === '=' ? '==' : $match[1];
        $target = self::normalise($match[2]);

        // A wildcard such as 2.1.* means "this prefix".
        if (str_ends_with($target, '.*')) {
            $prefix = substr($target, 0, -1);
            $inside = str_starts_with($version.'.', $prefix);

            return $operator === '!=' ? ! $inside : $inside;
        }

        return version_compare($version, $target, $operator);
    }

    private static function normalise(string $version): string
    {
        $version = ltrim(trim($version), 'vV');

        return (string) preg_replace('/\+.*$/', '', $version);
    }
}
