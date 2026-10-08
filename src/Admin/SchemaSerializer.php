<?php

declare(strict_types=1);

namespace Cg\Cms\Admin;

use Cg\Cms\Models\Entry;
use Cg\Cms\Schema\Collection as CollectionSchema;
use Cg\Cms\Schema\CollectionRegistry;
use Cg\Cms\Schema\Field;
use Illuminate\Support\Str;

/**
 * Turns a collection definition into the props an editor screen renders from.
 *
 * This is the seam the whole admin hangs off. The server owns what a field is;
 * the client owns what it looks like. One React component per `type` string,
 * resolved from a registry, means adding a field type is one PHP class and one
 * component rather than a change to every screen that might display it.
 *
 * The alternative, a hand-written form per collection, was how the old admin
 * worked and is why adding a field there meant touching the CMS.
 */
final class SchemaSerializer
{
    public function __construct(private readonly CollectionRegistry $collections) {}

    /**
     * Everything the editor needs to render a form for this collection.
     *
     * @return array<string, mixed>
     */
    public function forEditor(CollectionSchema $schema): array
    {
        return [
            'handle' => $schema->handle,
            'label' => $schema->labelText(),
            'singular' => $this->singular($schema),
            'routePattern' => $schema->routePattern(),
            'indexPath' => $schema->indexPath(),
            'fields' => $this->fields($schema),

            // Which fields the linter will actually run on, so the panel can
            // say nothing rather than imply a clean bill of health for a field
            // nobody checks.
            'lintedTypes' => ['text', 'textarea', 'richText', 'blocks'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fields(CollectionSchema $schema): array
    {
        return array_values(array_map(
            function (Field $field) use ($schema): array {
                $serialised = $field->toArray();

                // Native columns are stored on the row, everything else is a
                // jsonb key. The client needs to know because the two come
                // back from the server at different paths.
                $serialised['native'] = in_array(
                    $field->name,
                    CollectionSchema::NATIVE_COLUMNS,
                    true,
                );

                if ($field->type === 'relation') {
                    $serialised['choices'] = $this->choicesFor($field);
                }

                if ($field->name === 'slug') {
                    $serialised['preview'] = $schema->routePattern();
                }

                return $serialised;
            },
            $schema->allFields(),
        ));
    }

    /**
     * Options for a relation field.
     *
     * Slugs and titles of published entries in the target collection. Loaded
     * eagerly rather than searched over the wire because the largest
     * collection on this site is sixty articles: a search endpoint would be
     * more code, more latency and no better until there are thousands.
     *
     * When that changes, this is the one method to replace.
     *
     * @return array<int, array{value: string, label: string}>
     */
    private function choicesFor(Field $field): array
    {
        $target = $field->relatedCollection();

        if ($target === null || ! $this->collections->has($target)) {
            return [];
        }

        return Entry::query()
            ->collection($target)
            ->orderBy('title')
            ->get(['slug', 'title'])
            ->map(fn (Entry $entry): array => [
                'value' => $entry->slug,
                'label' => $entry->title,
            ])
            ->all();
    }

    /**
     * "Article", so a button can read "New article".
     *
     * Derived from the handle rather than from the label, because a label
     * names a list and does not always have a sensible singular: the `article`
     * collection is labelled "Writing", and "New Writing" is not what anyone
     * wants on a button.
     */
    private function singular(CollectionSchema $schema): string
    {
        return Str::headline(Str::singular($schema->handle));
    }
}
