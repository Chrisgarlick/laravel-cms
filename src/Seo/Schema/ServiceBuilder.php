<?php

declare(strict_types=1);

namespace Cg\Cms\Seo\Schema;

use Cg\Cms\Models\Entry;
use Cg\Cms\Seo\SeoData;

/**
 * Service, with the provider and the area it covers.
 *
 * `areaServed` is the property that makes a service page eligible for local and
 * regional intent, which is most of the intent behind "AI implementation for UK
 * law firms". It comes from config rather than the entry: the area a one-person
 * consultancy serves is a property of the business, not of each service.
 *
 * An offer is emitted only when a price is actually stored. Structured data
 * claiming a price the page does not show is a manual action waiting to happen.
 */
final class ServiceBuilder implements SchemaBuilder
{
    public function build(Entry $entry, SeoData $seo, array $context): array
    {
        $node = [
            '@type' => 'Service',
            '@id' => $seo->canonical.'#service',
            'name' => $entry->title,
            'url' => $seo->canonical,
            'serviceType' => $entry->title,
        ];

        if ($seo->description !== '') {
            $node['description'] = $seo->description;
        }

        if (isset($context['organization'])) {
            $node['provider'] = ['@id' => $context['organization']];
        }

        $area = $context['area_served'] ?? null;

        if (is_string($area) && $area !== '') {
            $node['areaServed'] = ['@type' => 'AdministrativeArea', 'name' => $area];
        }

        // Only while the collection still has a price field. A site that drops
        // pricing removes the field, and a value left behind in older content
        // must not keep advertising a price in the structured data.
        $price = $entry->schema()->field('starting_price') !== null
            ? $entry->value('starting_price')
            : null;

        if (is_string($price) && trim($price) !== '') {
            $node['offers'] = array_filter([
                '@type' => 'Offer',
                'availability' => 'https://schema.org/InStock',
                'priceCurrency' => $context['currency'] ?? null,

                // The stored value is authored copy ("from £500"), so it is
                // emitted as a specification rather than parsed into a number.
                // Guessing a numeric price out of prose is how you end up
                // advertising £0.
                'priceSpecification' => [
                    '@type' => 'PriceSpecification',
                    'description' => trim($price),
                ],
            ], fn (mixed $value): bool => $value !== null);
        }

        return [$node];
    }
}
