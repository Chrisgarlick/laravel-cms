<?php

declare(strict_types=1);

namespace Cg\Cms\Http\Controllers\Admin;

use Cg\Cms\Admin\SchemaLocator;
use Cg\Cms\Models\Entry;
use Cg\Cms\Schema\Block;
use Cg\Cms\Schema\BlockRegistry;
use Cg\Cms\Schema\Collection;
use Cg\Cms\Schema\CollectionRegistry;
use Cg\Cms\Schema\Field;
use Cg\Cms\Schema\FieldGroup;
use Illuminate\Support\Facades\View;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The content model, read only.
 *
 * Generated from the same registries the editor and the validator use, so it
 * cannot drift from what the site actually does. Editing stays in code
 * (`app/Cms/schema.php`), which is why every item says where it is defined.
 */
final class SchemaController
{
    public function __invoke(CollectionRegistry $collections, BlockRegistry $blocks): Response
    {
        $locator = new SchemaLocator((string) config('cg-cms.schema_path'));

        $counts = Entry::query()
            ->selectRaw('collection, count(*) as total')
            ->groupBy('collection')
            ->pluck('total', 'collection');

        $usage = $this->blockUsage($collections);

        return Inertia::render('Schema/Index', [
            'schemaPath' => $this->relativeSchemaPath(),
            'collections' => array_values(array_map(
                fn (Collection $collection): array => [
                    'handle' => $collection->handle,
                    'label' => $collection->labelText(),
                    'route' => $collection->routePattern(),
                    'indexPath' => $collection->indexPath(),
                    'order' => $collection->orderColumn().' '.$collection->orderDirection(),
                    'seoTitle' => $collection->seoTitleTemplate(),
                    'entries' => (int) ($counts[$collection->handle] ?? 0),
                    'definedAt' => $locator->collection($collection->handle),
                    'fields' => array_map(
                        fn (Field $field): array => [
                            ...$this->describe($field),
                            'definedAt' => $locator->collectionField($collection->handle, $field->type, $field->name),
                        ],
                        $collection->allFields(),
                    ),
                ],
                $collections->all(),
            )),
            'blocks' => array_values(array_map(
                fn (Block $block): array => [
                    'handle' => $block->handle,
                    'label' => $block->label,
                    'view' => $block->viewName(),
                    'viewExists' => View::exists($block->viewName()),
                    'dependsOn' => array_map(fn (string $tag): string => str_replace('collection:', '', $tag), $block->cacheDependencies()),
                    'usedIn' => $usage[$block->handle] ?? [],
                    'definedAt' => $locator->block($block->handle),
                    'fields' => array_map(fn (Field $field): array => $this->describe($field), $block->allFields()),
                ],
                $blocks->all(),
            )),
            'groups' => array_map(
                fn (string $name): array => [
                    'name' => $name,
                    'definedAt' => $locator->fieldGroup($name),
                    'fields' => array_map(fn (Field $field): array => $this->describe($field), FieldGroup::fields($name)),
                    'usedIn' => $this->groupUsage($collections, $blocks, $name),
                ],
                FieldGroup::names(),
            ),
        ]);
    }

    /**
     * What the screen shows about a field, nested fields included.
     *
     * @return array<string, mixed>
     */
    private function describe(Field $field): array
    {
        $schema = $field->toArray();

        return [
            'name' => $field->name,
            'type' => $field->type,
            'label' => $schema['label'],
            'help' => $schema['help'],
            'required' => $schema['required'],
            'maxLength' => $schema['maxLength'],
            'default' => $schema['default'],
            'options' => $schema['options'],
            'lint' => $schema['lint'],
            'relatedCollection' => $schema['relatedCollection'],
            'many' => $schema['many'],
            'requireAlt' => $schema['requireAlt'],
            'preset' => $schema['preset'],
            'slugSource' => $schema['slugSource'],
            'tab' => $schema['tab'],
            'sidebar' => $schema['sidebar'],
            'condition' => $schema['condition'],
            'uses' => $schema['uses'],
            'min' => $schema['min'],
            'max' => $schema['max'],
            'blocks' => array_map(fn (Block $block): string => $block->handle, $field->allowedBlocks()),
            'fields' => array_map(fn (Field $sub): array => $this->describe($sub), $field->subFields()),
        ];
    }

    /** @return array<string, array<int, string>> block handle => collection labels */
    private function blockUsage(CollectionRegistry $collections): array
    {
        $usage = [];

        foreach ($collections->all() as $collection) {
            foreach ($collection->blockFields() as $field) {
                foreach ($field->allowedBlocks() as $block) {
                    $usage[$block->handle][] = $collection->labelText();
                }
            }
        }

        return array_map(fn (array $labels): array => array_values(array_unique($labels)), $usage);
    }

    /** @return array<int, string> */
    private function groupUsage(CollectionRegistry $collections, BlockRegistry $blocks, string $name): array
    {
        $used = [];

        $walk = function (array $fields, string $owner) use (&$walk, &$used, $name): void {
            foreach ($fields as $field) {
                if ($field->usedGroup() === $name) {
                    $used[] = "{$owner} → {$field->name}";
                }

                $walk($field->subFields(), $owner);
            }
        };

        foreach ($collections->all() as $collection) {
            $walk($collection->allFields(), $collection->labelText());
        }

        foreach ($blocks->all() as $block) {
            $walk($block->allFields(), "{$block->label} block");
        }

        return $used;
    }

    private function relativeSchemaPath(): string
    {
        $path = (string) config('cg-cms.schema_path');
        $base = rtrim(base_path(), '/').'/';

        return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
    }
}
