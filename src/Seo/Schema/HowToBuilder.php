<?php

declare(strict_types=1);

namespace Cg\Cms\Seo\Schema;

use Cg\Cms\Models\Entry;
use Cg\Cms\Seo\SeoData;

/**
 * HowTo, for guides that genuinely are a sequence of steps.
 *
 * Emits nothing unless the entry stores a `steps` array. That restraint is the
 * point: HowTo on a page that is not a procedure is exactly the kind of
 * structured data that earns a manual action, and "where relevant" is a judgment
 * no builder can make from a collection handle.
 *
 * Dormant until a collection stores steps. It costs nothing to carry and means
 * the day guide content exists, the markup is already correct.
 */
final class HowToBuilder implements SchemaBuilder
{
    public function build(Entry $entry, SeoData $seo, array $context): array
    {
        $steps = $entry->value('steps');

        if (! is_array($steps) || $steps === []) {
            return [];
        }

        $elements = [];
        $position = 1;

        foreach ($steps as $step) {
            $name = is_array($step) ? ($step['name'] ?? $step['title'] ?? null) : $step;
            $text = is_array($step) ? ($step['text'] ?? $step['body'] ?? null) : null;

            if (! is_string($name) || trim($name) === '') {
                continue;
            }

            $elements[] = array_filter([
                '@type' => 'HowToStep',
                'position' => $position++,
                'name' => trim($name),
                'text' => is_string($text) && trim($text) !== '' ? trim($text) : null,
            ], fn (mixed $value): bool => $value !== null);
        }

        if ($elements === []) {
            return [];
        }

        return [array_filter([
            '@type' => 'HowTo',
            '@id' => $seo->canonical.'#howto',
            'name' => $entry->title,
            'description' => $seo->description !== '' ? $seo->description : null,
            'step' => $elements,
        ], fn (mixed $value): bool => $value !== null)];
    }
}
