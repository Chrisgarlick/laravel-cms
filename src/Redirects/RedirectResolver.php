<?php

declare(strict_types=1);

namespace Cg\Cms\Redirects;

use Cg\Cms\Models\Redirect;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a path to a redirect target.
 *
 * The whole table is held in one cached array, so a lookup is an array access
 * rather than a query. Two reasons that matters: exact matches are the common
 * case and should be O(1), and the resolver runs on every 404, which is
 * exactly when a crawler is hammering you.
 *
 * The cache is invalidated whenever a redirect is saved, and the nginx map
 * export (see plan 5.8) can front this entirely so most redirects never reach
 * PHP at all.
 */
final class RedirectResolver
{
    private const CACHE_KEY = 'cgcms:redirects';

    public function resolve(string $path): ?Redirect
    {
        $path = '/'.trim($path, '/');

        $map = $this->map();

        // 1. Exact match. The overwhelming majority.
        if (isset($map['exact'][$path])) {
            return $this->hydrate($map['exact'][$path]);
        }

        // 2. Prefix rules, longest first, so /blog/foo/bar beats /blog.
        foreach ($map['prefix'] as $rule) {
            if (str_starts_with($path, rtrim($rule['from'], '/').'/') || $path === rtrim($rule['from'], '/')) {
                $suffix = substr($path, strlen(rtrim($rule['from'], '/')));
                $rule['to'] = rtrim($rule['to'], '/').$suffix;

                return $this->hydrate($rule);
            }
        }

        // 3. Regex rules last, since they cost the most.
        foreach ($map['regex'] as $rule) {
            $pattern = '#'.str_replace('#', '\#', $rule['from']).'#';

            if (@preg_match($pattern, $path) === 1) {
                $rule['to'] = (string) preg_replace($pattern, $rule['to'], $path);

                return $this->hydrate($rule);
            }
        }

        return null;
    }

    /** Redirects flagged `force`, checked before routing. */
    public function forced(string $path): ?Redirect
    {
        $path = '/'.trim($path, '/');
        $rule = $this->map()['force'][$path] ?? null;

        return $rule === null ? null : $this->hydrate($rule);
    }

    /**
     * Count the hit without blocking the response.
     *
     * A raw increment rather than a model save: no events, no timestamps, one
     * statement. Redirect hit counts are useful for pruning dead rules, not
     * worth slowing a redirect for.
     */
    public function recordHit(Redirect $redirect): void
    {
        DB::table('redirects')
            ->where('id', $redirect->id)
            ->update([
                'hits' => DB::raw('hits + 1'),
                'last_hit_at' => now(),
            ]);
    }

    public static function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{
     *     exact: array<string, array<string, mixed>>,
     *     force: array<string, array<string, mixed>>,
     *     prefix: array<int, array<string, mixed>>,
     *     regex: array<int, array<string, mixed>>
     * }
     */
    private function map(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function (): array {
            $map = ['exact' => [], 'force' => [], 'prefix' => [], 'regex' => []];

            $rules = Redirect::query()
                ->orderByDesc('priority')
                ->orderByRaw('length("from") desc')
                ->get(['id', 'from', 'to', 'status', 'match_type', 'force']);

            foreach ($rules as $rule) {
                $row = [
                    'id' => $rule->id,
                    'from' => $rule->from,
                    'to' => $rule->to,
                    'status' => $rule->status,
                ];

                if ($rule->force) {
                    $map['force'][$rule->from] = $row;
                }

                match ($rule->match_type) {
                    'prefix' => $map['prefix'][] = $row,
                    'regex' => $map['regex'][] = $row,
                    default => $map['exact'][$rule->from] = $row,
                };
            }

            return $map;
        });
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Redirect
    {
        $redirect = new Redirect;
        $redirect->exists = true;
        $redirect->forceFill($row);

        return $redirect;
    }
}
