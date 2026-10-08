<?php

declare(strict_types=1);

namespace Cg\Cms\Seo\Checks;

use Cg\Cms\Content\BlockRenderer;
use Cg\Cms\Lint\LintIssue;
use Cg\Cms\Media\MediaResolver;
use Cg\Cms\Models\Entry;
use Cg\Cms\Seo\SeoResolver;
use Illuminate\Support\Facades\DB;

/**
 * The SEO gates for one entry, saved or not.
 *
 * Builds the page an entry will produce from the parts the CMS owns: the
 * resolved head, the pre-rendered rich text and the rendered blocks. It cannot
 * see the template around them, which is why `impliedH1` exists, and why the
 * nightly audit, which can, is the final word on anything structural.
 *
 * Expects rich text already rendered into `rendered`, which the observer does
 * on save and the preview endpoint does explicitly.
 */
final class EntrySeoChecks
{
    public function __construct(
        private readonly SeoChecks $checks,
        private readonly BlockRenderer $blocks,
        private readonly InternalLinks $links,
    ) {}

    /** @return array<int, LintIssue> */
    public function check(Entry $entry): array
    {
        if (! $this->checks->enabled() || $entry->routeless()) {
            return [];
        }

        return [
            ...$this->checks->check($this->facts($entry)),
            ...array_map($this->checks->stamp(...), [
                ...$this->duplicates($entry),
                ...$this->linksIn($entry),
            ]),
        ];
    }

    /**
     * Only the findings that stop this entry being published.
     *
     * @return array<int, LintIssue>
     */
    public function blockingPublish(Entry $entry): array
    {
        return array_values(array_filter(
            $this->check($entry),
            fn (LintIssue $issue): bool => $issue->blocking(),
        ));
    }

    public function facts(Entry $entry): PageFacts
    {
        $schema = $entry->schema();
        $seo = app(SeoResolver::class)->forEntry($entry);

        $html = '';
        $contentField = null;

        foreach ($schema->allFields() as $field) {
            if ($field->type === 'richText') {
                $html .= $entry->html($field->name);
                $contentField ??= $field->name;
            }

            if ($field->type === 'blocks') {
                $html .= $this->blocks->render(
                    (array) $entry->value($field->name, []),
                    (array) data_get($entry->rendered, $field->name, []),
                );
                $contentField ??= $field->name;
            }
        }

        $page = new HtmlInspector($html);

        return new PageFacts(
            title: $seo->title,
            description: $seo->description,
            headings: $page->headings(),
            images: [...$page->images(), ...$this->mediaFieldImages($entry)],
            internalLinks: array_values(array_filter(
                $this->links->paths($page->links()),
                fn (string $path): bool => $path !== $entry->url(),
            )),
            text: $page->text(),
            keyword: $seo->keywords[0] ?? null,

            // A collection built from blocks puts its h1 in a block, usually
            // the hero. Everything else renders the title as the h1 in its
            // template, where the content cannot see it.
            impliedH1: $schema->blockFields() === [] ? 1 : 0,
            contentField: $contentField ?? 'title',
        );
    }

    /**
     * Top-level media fields, as images, so a missing alt is caught here too.
     *
     * @return array<int, array{src: string, alt: string|null}>
     */
    private function mediaFieldImages(Entry $entry): array
    {
        $images = [];
        $resolver = app(MediaResolver::class);

        foreach ($entry->schema()->allFields() as $field) {
            if ($field->type !== 'media') {
                continue;
            }

            $image = $resolver->resolve($entry->value($field->name), 'full');

            if ($image !== null) {
                $images[] = ['src' => $image->src, 'alt' => $image->alt];
            }
        }

        return $images;
    }

    /**
     * Another published entry with the same title or the same description.
     *
     * Compares what was typed rather than what was resolved: resolving every
     * other entry on each keystroke is the audit's job. The audit compares the
     * real head of every page and catches the duplicates this misses, like two
     * entries whose descriptions both fall back to the site default.
     *
     * @return array<int, LintIssue>
     */
    private function duplicates(Entry $entry): array
    {
        $issues = [];
        $others = Entry::query()->published()->when($entry->exists, fn ($q) => $q->whereKeyNot($entry->id));

        $title = trim((string) $entry->title);

        if ($title !== '') {
            $match = (clone $others)
                ->where('collection', $entry->collection)
                ->whereRaw('lower(title) = ?', [mb_strtolower($title)])
                ->value('title');

            if ($match !== null) {
                $issues[] = new LintIssue(
                    rule: 'duplicate-title',
                    field: 'title',
                    message: 'Another published entry has the same title.',
                    excerpt: (string) $match,
                    suggestion: 'two pages competing for one search are both weaker for it.',
                );
            }
        }

        $description = trim((string) data_get($entry->seo, 'description', ''));

        if ($description !== '') {
            $match = (clone $others)->whereRaw("seo->>'description' = ?", [$description])->value('title');

            if ($match !== null) {
                $issues[] = new LintIssue(
                    rule: 'duplicate-description',
                    field: 'seo.description',
                    message: 'Another published entry has the same description.',
                    excerpt: (string) $match,
                );
            }
        }

        return $issues;
    }

    /**
     * No page links here, as of the last audit.
     *
     * Silent until an audit has run. An empty link graph means "not measured",
     * and reporting every page as an orphan on a fresh install would teach
     * people to ignore this panel on day one.
     *
     * @return array<int, LintIssue>
     */
    private function linksIn(Entry $entry): array
    {
        if (! $entry->exists || $entry->status !== 'published' || ! DB::table('link_graph')->exists()) {
            return [];
        }

        $inbound = DB::table('link_graph')
            ->where('target', $entry->url())
            ->where('source', '!=', $entry->url())
            ->exists();

        if ($inbound) {
            return [];
        }

        return [new LintIssue(
            rule: 'orphan',
            field: 'title',
            message: 'No other page links here, as of the last audit.',
            suggestion: 'link to it from a related article or a listing.',
        )];
    }
}
