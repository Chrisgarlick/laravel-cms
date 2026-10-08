<?php

declare(strict_types=1);

namespace Cg\Cms\Seo\Schema;

use Cg\Cms\Models\Entry;
use Cg\Cms\Seo\SeoData;

/**
 * Builds the schema.org node for one collection.
 *
 * Builders, not string templates. A template that interpolates a title into
 * JSON produces invalid JSON the first time somebody writes a quotation mark,
 * and invalid structured data fails silently: the page still renders, the rich
 * result just never appears.
 *
 * A builder returns an array of nodes rather than one, because some types
 * genuinely need two (an article and its image object, say). Returning an empty
 * array is the correct answer when the entry does not carry what the type needs,
 * and is what keeps the dormant builders honest.
 */
interface SchemaBuilder
{
    /**
     * @param  array<string, mixed>  $context  Shared @id values: organization, person, website, webpage.
     * @return array<int, array<string, mixed>>
     */
    public function build(Entry $entry, SeoData $seo, array $context): array;
}
