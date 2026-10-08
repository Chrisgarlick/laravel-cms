<?php

declare(strict_types=1);

namespace Cg\Cms\Seo;

use Illuminate\Support\Facades\Route;

/**
 * Whether the application actually serves a URL pattern.
 *
 * The schema says what content exists; the route table says what is served.
 * They diverge whenever a collection is declared ahead of its templates, and
 * anything that trusts the schema alone (a sitemap, an audit) ends up
 * advertising or crawling pages that 404.
 */
final class ServedRoutes
{
    public static function serves(string $pattern): bool
    {
        $uri = trim($pattern, '/');

        foreach (Route::getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true) && trim($route->uri(), '/') === $uri) {
                return true;
            }
        }

        return false;
    }
}
