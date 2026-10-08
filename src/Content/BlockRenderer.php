<?php

declare(strict_types=1);

namespace Cg\Cms\Content;

use Cg\Cms\Cache\CacheContext;
use Cg\Cms\Models\Entry;
use Cg\Cms\Schema\Block;
use Cg\Cms\Schema\BlockRegistry;
use Illuminate\Support\Facades\View;

/**
 * Renders an entry's ordered list of blocks.
 *
 * Three jobs, in this order:
 *
 *  1. Register each block's cache dependencies. A `blog-preview` block means
 *     the page depends on `collection:article`, so publishing an article
 *     purges this page. Skipping this is how block-built pages go stale.
 *  2. Resolve any content the block queries from other collections.
 *  3. Render the block's view, handing it the pre-rendered HTML for its own
 *     rich text fields.
 *
 * Data fetching and cache registration live here rather than in Blade, so a
 * template cannot forget to declare a dependency.
 */
final class BlockRenderer
{
    public function __construct(
        private readonly CacheContext $context,
        private readonly BlockRegistry $blocks,
    ) {}

    /**
     * @param  array<int, mixed>  $blocks  Authored block list from `data`
     * @param  array<int, mixed>  $rendered  Pre-rendered HTML from `rendered`
     */
    public function render(array $blocks, array $rendered = []): string
    {
        $html = '';

        foreach ($blocks as $index => $block) {
            if (! is_array($block)) {
                continue;
            }

            $handle = $block['type'] ?? $block['_type'] ?? null;

            if (! is_string($handle)) {
                continue;
            }

            $definition = $this->blocks->find($handle);

            if ($definition === null) {
                // An unknown block type is a content problem, not a reason to
                // fail the page. It renders as nothing and stays visible in
                // the admin.
                continue;
            }

            foreach ($definition->cacheDependencies() as $tag) {
                $this->context->registerTag($tag);
            }

            $view = $definition->viewName();

            if (! View::exists($view)) {
                continue;
            }

            $html .= View::make($view, [
                'block' => $definition,
                'data' => $this->normaliseData($block),
                'html' => (array) ($rendered[$index] ?? []),
                'items' => $this->resolveItems($definition, $this->normaliseData($block)),
                'theme' => data_get($block, 'data.theme') ?? data_get($block, 'theme') ?? 'light',
            ])->render();
        }

        return $html;
    }

    /**
     * Field values, whether stored flat or nested under a `data` key.
     *
     * The legacy CMS wrote both shapes at different times, so the import in
     * Phase 6 will produce a mix. Normalising here means no template has to
     * care.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private function normaliseData(array $block): array
    {
        $nested = $block['data'] ?? null;

        if (is_array($nested)) {
            return $nested;
        }

        return array_diff_key($block, ['type' => true, '_type' => true]);
    }

    /**
     * Content a block pulls from other collections.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function resolveItems(Block $definition, array $data): array
    {
        // The live defaults: four case studies, three posts.
        $default = $definition->handle === 'case-study-grid' ? 4 : 3;
        $limit = (int) ($data['limit'] ?? $default) ?: $default;

        return match ($definition->handle) {
            'blog-preview' => $this->latest('article', $limit, 'published_at'),
            'case-study-grid' => $this->latest('case_study', $limit, 'published_at'),
            'tools-teaser' => $this->latest('tool', $limit, 'sort_order', 'asc'),
            'proof-strip' => $this->latest('proof_metric', 12, 'sort_order', 'asc'),
            default => [],
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function latest(string $collection, int $limit, string $column, string $direction = 'desc'): array
    {
        return Entry::query()
            ->collection($collection)
            ->published()
            ->orderBy($column, $direction)
            ->limit($limit)
            ->get()
            ->map(fn (Entry $entry): array => [
                'title' => $entry->title,
                'slug' => $entry->slug,
                'url' => $entry->url(),
                'excerpt' => (string) $entry->value('excerpt', $entry->value('summary', '')),
                'text' => (string) $entry->value('text', ''),
                'result' => (string) $entry->value('result', ''),
                'category' => (string) $entry->value('category', ''),
                'description' => (string) $entry->value('description', ''),
                'date' => $entry->published_at?->format('j F Y'),
                'date_short' => $entry->published_at?->format('j M Y'),
                'year' => $entry->published_at?->format('Y'),
                'icon' => (string) $entry->value('icon', ''),
            ])
            ->all();
    }
}
