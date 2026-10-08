<?php

declare(strict_types=1);

namespace Cg\Cms\Seo\Schema;

use Cg\Cms\Models\Entry;
use Cg\Cms\Seo\SeoData;

/**
 * Article, with the properties Google actually reads.
 *
 * `author`, `datePublished` and `dateModified` are the three that decide whether
 * a result shows a byline and a date. `image` is what decides whether it shows a
 * thumbnail. Everything else here is context for answer engines.
 */
final class ArticleBuilder implements SchemaBuilder
{
    public function build(Entry $entry, SeoData $seo, array $context): array
    {
        $node = [
            '@type' => 'Article',
            '@id' => $seo->canonical.'#article',
            'isPartOf' => ['@id' => $context['webpage'] ?? $seo->canonical],
            'mainEntityOfPage' => ['@id' => $context['webpage'] ?? $seo->canonical],
            'headline' => $entry->title,
            'name' => $entry->title,
            'url' => $seo->canonical,
        ];

        if ($seo->description !== '') {
            $node['description'] = $seo->description;
        }

        if (isset($context['person'])) {
            $node['author'] = ['@id' => $context['person']];
        }

        if (isset($context['organization'])) {
            $node['publisher'] = ['@id' => $context['organization']];
        }

        if ($seo->publishedAt !== null) {
            $node['datePublished'] = $seo->publishedAt;
        }

        // Falls back to the published date rather than being omitted. An
        // article with no dateModified is treated as never updated, which is
        // worse than saying it was last touched when it was written.
        $node['dateModified'] = $seo->modifiedAt ?? $seo->publishedAt;

        if ($seo->image !== null) {
            $node['image'] = ['@id' => $seo->canonical.'#primaryimage'];
        }

        $keywords = $entry->value('tags');

        if (is_array($keywords) && $keywords !== []) {
            $node['keywords'] = array_values(array_map('strval', $keywords));
        }

        return [array_filter($node, fn (mixed $value): bool => $value !== null)];
    }
}
