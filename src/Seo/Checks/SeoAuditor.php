<?php

declare(strict_types=1);

namespace Cg\Cms\Seo\Checks;

use Cg\Cms\Lint\LintIssue;
use Cg\Cms\Models\Entry;
use Cg\Cms\Models\Redirect;
use Cg\Cms\Models\SeoIssue;
use Cg\Cms\Redirects\NotFoundHandler;
use Cg\Cms\Schema\CollectionRegistry;
use Cg\Cms\Seo\ServedRoutes;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Renders every public page and checks what actually came out.
 *
 * The editor checks what an entry contributes; this checks the page, template
 * and all. It is the only place that can see an h1 in a layout, a link in the
 * navigation, a JSON-LD block that failed to encode, or a link that points at
 * an entry deleted last week.
 *
 * Pages are rendered in-process through the HTTP kernel rather than over the
 * network. It needs no web server, so it runs the same in a test, on a laptop
 * and on the droplet, and it never writes to the page cache, which only
 * happens in the middleware's terminate step and that is never called here.
 * Requests are marked uncounted, so probing a broken link adds nothing to the
 * 404 log and crossing a redirect adds no hits to it.
 */
final class SeoAuditor
{
    /** @var array<string, int> Status per path, so each target is probed once. */
    private array $statuses = [];

    public function __construct(
        private readonly HttpKernel $kernel,
        private readonly CollectionRegistry $collections,
        private readonly SeoChecks $checks,
        private readonly InternalLinks $links,
    ) {}

    /**
     * Run the audit and replace the stored results.
     *
     * @return array{pages: int, issues: int, links: int}
     */
    public function run(): array
    {
        $this->statuses = [];
        $originalRequest = app()->bound('request') ? app('request') : null;

        try {
            $result = $this->audit();
        } finally {
            // Rendering through the kernel rebinds the container's request.
            // When the audit runs inside another request (the admin's "run
            // now", on a sync queue) that request must get its own back, or
            // whatever runs next believes it is the last page audited.
            if ($originalRequest !== null) {
                app()->instance('request', $originalRequest);
                Facade::clearResolvedInstance('request');
            }
        }

        $now = now();

        DB::transaction(function () use ($result, $now): void {
            SeoIssue::query()->delete();
            DB::table('link_graph')->delete();

            foreach (array_chunk($result['issues'], 500) as $chunk) {
                SeoIssue::query()->insert(array_map(
                    fn (array $issue): array => [...$issue, 'found_at' => $now],
                    $chunk,
                ));
            }

            foreach (array_chunk($result['links'], 500) as $chunk) {
                DB::table('link_graph')->insert(array_map(
                    fn (array $link): array => [...$link, 'found_at' => $now],
                    $chunk,
                ));
            }
        });

        return [
            'pages' => $result['pages'],
            'issues' => count($result['issues']),
            'links' => count($result['links']),
        ];
    }

    /**
     * @return array{pages: int, issues: array<int, array<string, mixed>>, links: array<int, array<string, mixed>>}
     */
    private function audit(): array
    {
        $pages = $this->pages();
        $issues = [];
        $edges = [];
        $titles = [];
        $descriptions = [];

        foreach ($pages as $path => $entryId) {
            $response = $this->render($path);
            $this->statuses[$path] = $response->getStatusCode();

            if ($response->getStatusCode() !== 200) {
                $issues[] = $this->issue($path, $entryId, new LintIssue(
                    rule: 'page-error',
                    field: '',
                    message: "The page returned {$response->getStatusCode()}.",
                    severity: LintIssue::BLOCK,
                ));

                continue;
            }

            $page = new HtmlInspector((string) $response->getContent());

            // A page asking not to be indexed is not held to indexing rules,
            // but it still has links, so it stays in the graph.
            $indexable = ! str_contains((string) $page->robots(), 'noindex');

            if ($indexable) {
                foreach ($this->checks->check($this->facts($page, $path)) as $finding) {
                    $issues[] = $this->issue($path, $entryId, $finding);
                }

                $issues = [...$issues, ...$this->structuredData($page, $path, $entryId)];

                $titles[$page->title() ?? ''][] = $path;
                $descriptions[$page->metaDescription() ?? ''][] = $path;
            }

            foreach (array_unique($this->links->paths($page->links(everywhere: true))) as $target) {
                if ($target !== $path && ! $this->isExcluded($target)) {
                    $edges[] = ['source' => $path, 'target' => $target];
                }
            }
        }

        $edges = $this->withStatuses($edges);

        return [
            'pages' => count($pages),
            'issues' => [
                ...$issues,
                ...$this->brokenLinks($edges, $pages),
                ...$this->orphans($edges, $pages),
                ...$this->duplicates($titles, 'duplicate-title', 'title', $pages),
                ...$this->duplicates($descriptions, 'duplicate-description', 'description', $pages),
                ...$this->redirectChains(),
            ],
            'links' => $edges,
        ];
    }

    /**
     * Every public page: collection indexes and published entries.
     *
     * The same source cms:warm uses, so the audit and the cache agree about
     * what the site is.
     *
     * @return array<string, int|null> path => entry id, null for an index page
     */
    private function pages(): array
    {
        $pages = [];

        foreach ($this->collections->routable() as $handle => $collection) {
            // A collection declared ahead of its templates has no pages yet.
            // Auditing them would report 404s for URLs nothing links to.
            if (! ServedRoutes::serves((string) $collection->routePattern())) {
                continue;
            }

            if ($collection->indexPath() !== null && ServedRoutes::serves($collection->indexPath())) {
                $pages[$collection->indexPath()] = null;
            }

            Entry::query()
                ->collection($handle)
                ->published()
                ->orderBy('id')
                ->each(function (Entry $entry) use (&$pages): void {
                    $pages[$entry->url()] = $entry->id;
                });
        }

        return $pages;
    }

    private function render(string $path): Response
    {
        $base = (string) config('app.url');

        $request = Request::create(rtrim($base, '/').$path, 'GET');
        $request->attributes->set(NotFoundHandler::UNCOUNTED, true);

        try {
            return $this->kernel->handle($request);
        } catch (Throwable) {
            return new Response('', 500);
        }
    }

    private function facts(HtmlInspector $page, string $path): PageFacts
    {
        return new PageFacts(
            title: $page->title() ?? '',
            description: $page->metaDescription() ?? '',
            headings: $page->headings(),
            images: $page->images(),
            internalLinks: array_values(array_filter(
                $this->links->paths($page->links()),
                fn (string $target): bool => $target !== $path,
            )),
            text: $page->text(),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function structuredData(HtmlInspector $page, string $path, ?int $entryId): array
    {
        $blocks = $page->jsonLd();

        if ($blocks === []) {
            return [$this->issue($path, $entryId, new LintIssue(
                rule: 'structured-data-missing',
                field: '',
                message: 'The page has no JSON-LD.',
                severity: LintIssue::WARN,
            ))];
        }

        if (in_array(false, $blocks, true)) {
            return [$this->issue($path, $entryId, new LintIssue(
                rule: 'structured-data-invalid',
                field: '',
                message: 'A JSON-LD block on the page is not valid JSON, so search engines ignore all of it.',
                severity: LintIssue::BLOCK,
            ))];
        }

        return [];
    }

    /**
     * Attach a status to every link target, probing the ones not yet seen.
     *
     * A target that is a file in public/ (the sitemap, a feed, an image) is
     * fine without asking PHP. Anything else is rendered once and remembered.
     *
     * @param  array<int, array{source: string, target: string}>  $edges
     * @return array<int, array{source: string, target: string, status: int}>
     */
    private function withStatuses(array $edges): array
    {
        foreach ($edges as $index => $edge) {
            $target = $edge['target'];

            if (! isset($this->statuses[$target])) {
                $this->statuses[$target] = is_file(public_path(ltrim($target, '/')))
                    ? 200
                    : $this->render($target)->getStatusCode();
            }

            $edges[$index]['status'] = $this->statuses[$target];
        }

        return $edges;
    }

    /**
     * Links that 404, and links that go through a redirect.
     *
     * One finding per page per target, on the page with the link, because
     * that is where it gets fixed.
     *
     * @param  array<int, array{source: string, target: string, status: int}>  $edges
     * @param  array<string, int|null>  $pages
     * @return array<int, array<string, mixed>>
     */
    private function brokenLinks(array $edges, array $pages): array
    {
        $issues = [];

        foreach ($edges as $edge) {
            $status = $edge['status'];

            if ($status >= 400) {
                $issues[] = $this->issue($edge['source'], $pages[$edge['source']] ?? null, new LintIssue(
                    rule: 'broken-link',
                    field: '',
                    message: "Links to {$edge['target']}, which returns {$status}.",
                    excerpt: $edge['target'],
                    severity: LintIssue::BLOCK,
                ));
            } elseif ($status >= 300) {
                $issues[] = $this->issue($edge['source'], $pages[$edge['source']] ?? null, new LintIssue(
                    rule: 'links-to-redirect',
                    field: '',
                    message: "Links to {$edge['target']}, which redirects.",
                    excerpt: $edge['target'],
                    suggestion: 'link to where it ends up instead, and save a round trip.',
                    severity: LintIssue::WARN,
                ));
            }
        }

        return $issues;
    }

    /**
     * Entry pages nothing links to.
     *
     * Index pages are exempt: they are reached from the navigation by
     * definition, and a listing with no inbound links is a navigation bug the
     * person reading this report will have noticed already.
     *
     * @param  array<int, array{source: string, target: string, status: int}>  $edges
     * @param  array<string, int|null>  $pages
     * @return array<int, array<string, mixed>>
     */
    private function orphans(array $edges, array $pages): array
    {
        $linked = array_flip(array_column($edges, 'target'));
        $issues = [];

        foreach ($pages as $path => $entryId) {
            if ($entryId === null || isset($linked[$path]) || ($this->statuses[$path] ?? 0) !== 200) {
                continue;
            }

            $issues[] = $this->issue($path, $entryId, new LintIssue(
                rule: 'orphan',
                field: '',
                message: 'No other page links here.',
                suggestion: 'link to it from a related article or a listing.',
                severity: LintIssue::WARN,
            ));
        }

        return $issues;
    }

    /**
     * @param  array<string, array<int, string>>  $groups  value => paths
     * @param  array<string, int|null>  $pages
     * @return array<int, array<string, mixed>>
     */
    private function duplicates(array $groups, string $rule, string $label, array $pages): array
    {
        $issues = [];

        foreach ($groups as $value => $paths) {
            if ($value === '' || count($paths) < 2) {
                continue;
            }

            foreach ($paths as $path) {
                $others = array_values(array_diff($paths, [$path]));

                $issues[] = $this->issue($path, $pages[$path] ?? null, new LintIssue(
                    rule: $rule,
                    field: '',
                    message: sprintf(
                        'Same %s as %s%s.',
                        $label,
                        $others[0],
                        count($others) > 1 ? ' and '.(count($others) - 1).' more' : '',
                    ),
                    excerpt: (string) $value,
                    severity: LintIssue::WARN,
                ));
            }
        }

        return $issues;
    }

    /**
     * Redirects whose destination is itself redirected.
     *
     * Every hop is a round trip for a visitor and a diluted signal for a
     * crawler. The observer re-points old redirects when a slug changes, so
     * chains here were created by hand or by an import.
     *
     * @return array<int, array<string, mixed>>
     */
    private function redirectChains(): array
    {
        $exact = Redirect::query()->where('match_type', 'exact')->pluck('to', 'from')->all();
        $issues = [];

        foreach ($exact as $from => $to) {
            if (isset($exact[$to])) {
                $issues[] = $this->issue((string) $from, null, new LintIssue(
                    rule: 'redirect-chain',
                    field: '',
                    message: "Redirects to {$to}, which redirects again to {$exact[$to]}.",
                    suggestion: 'point it straight at '.$exact[$to].'.',
                    severity: LintIssue::WARN,
                ));
            }
        }

        return $issues;
    }

    /**
     * A path the site deliberately keeps out of search: the admin, the API,
     * previews, thank-you pages (cg-cms.seo.exclude).
     *
     * Not part of the link graph. A gated download link answers 401 to
     * anyone without the lead's cookie, which is correct, and reporting it
     * as a broken link would be a finding nobody should act on.
     */
    private function isExcluded(string $path): bool
    {
        $patterns = (array) config('cg-cms.seo.exclude', []);

        return $patterns !== [] && Str::is($patterns, ltrim($path, '/'));
    }

    /** @return array<string, mixed> */
    private function issue(string $path, ?int $entryId, LintIssue $finding): array
    {
        $detail = trim($finding->excerpt.($finding->suggestion !== null ? ' Try: '.$finding->suggestion : ''));

        return [
            'url' => $path,
            'entry_id' => $entryId,
            'rule' => $finding->rule,
            'severity' => $finding->severity,
            'message' => $finding->message,
            'detail' => $detail === '' ? null : $detail,
        ];
    }
}
