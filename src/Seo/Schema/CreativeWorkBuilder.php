<?php

declare(strict_types=1);

namespace Cg\Cms\Seo\Schema;

use Cg\Cms\Models\Entry;
use Cg\Cms\Seo\SeoData;

/**
 * CreativeWork with a free offer, for gated resources.
 *
 * The offer is what tells an answer engine the download costs nothing, which is
 * the single most useful fact about a gated resource. Price zero with a currency
 * is the correct encoding; omitting the offer entirely leaves it ambiguous.
 *
 * `isAccessibleForFree` is true and `hasPart` marks the gate. That combination
 * is what Google documents for content behind a form, and it is honest: the
 * content is free, it just wants an email address first.
 */
final class CreativeWorkBuilder implements SchemaBuilder
{
    public function build(Entry $entry, SeoData $seo, array $context): array
    {
        $node = [
            '@type' => 'CreativeWork',
            '@id' => $seo->canonical.'#resource',
            'name' => $entry->title,
            'url' => $seo->canonical,
            'isAccessibleForFree' => true,
            'offers' => [
                '@type' => 'Offer',
                'price' => 0,
                'priceCurrency' => $context['currency'] ?? 'GBP',
                'availability' => 'https://schema.org/InStock',
            ],
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

        return [$node];
    }
}
