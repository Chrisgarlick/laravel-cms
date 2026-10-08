<?php

declare(strict_types=1);

namespace Cg\Cms\Validation;

use Cg\Cms\Schema\Block;
use Cg\Cms\Schema\Field;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;

/**
 * Validates a list of blocks against whichever schema each item declares.
 *
 * A wildcard rule key cannot express this. `content.*.data.heading` assumes
 * every item has a heading, when in fact a `hero` has one, a `rich-text` does
 * not, and the rules that apply to item 3 depend on a string stored inside
 * item 3.
 *
 * Two failures this exists to prevent, both of which fail quietly otherwise:
 *
 *   - **An unknown block type.** BlockRenderer skips a block it does not
 *     recognise, so a typo in a handle produces a page that renders without
 *     that section and reports nothing. Rejecting it on save is the only
 *     point at which somebody is looking.
 *   - **A missing required subfield.** A hero with no heading renders an empty
 *     banner. The block declared the field as required; nothing was enforcing
 *     it until the content reached a template.
 *
 * Messages name the position and the block, because "the content field is
 * invalid" on a page of fourteen blocks is not a message anybody can act on.
 */
final class ValidBlocks implements ValidationRule
{
    public function __construct(private readonly Field $field) {}

    /**
     * @param  Closure(string): void  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        $allowed = $this->allowed();

        foreach ($value as $index => $item) {
            $position = (int) $index + 1;

            if (! is_array($item)) {
                $fail("Block {$position} is not valid content.");

                continue;
            }

            $handle = $item['type'] ?? $item['_type'] ?? null;

            if (! is_string($handle) || ! isset($allowed[$handle])) {
                $fail(sprintf(
                    'Block %d has an unknown type [%s]. Allowed: %s.',
                    $position,
                    is_string($handle) ? $handle : 'none',
                    implode(', ', array_keys($allowed)),
                ));

                continue;
            }

            $this->validateItem($allowed[$handle], $item, $position, $fail);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Closure(string): void  $fail
     */
    private function validateItem(Block $block, array $item, int $position, Closure $fail): void
    {
        // Both storage shapes, for the same reason BlockRenderer normalises
        // them: the legacy CMS wrote flat and nested at different times and
        // the Phase 6 import will produce a mix.
        $data = is_array($item['data'] ?? null)
            ? $item['data']
            : array_diff_key($item, ['type' => true, '_type' => true]);

        $rules = [];

        foreach ($block->allFields() as $field) {
            $rules[$field->name] = $field->validationRules();
            $rules = [...$rules, ...$field->nestedValidationRules()];
        }

        $validator = Validator::make($data, $rules);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $fail(sprintf('Block %d (%s): %s', $position, $block->label, lcfirst($message)));
            }
        }
    }

    /** @return array<string, Block> */
    private function allowed(): array
    {
        $allowed = [];

        foreach ($this->field->allowedBlocks() as $block) {
            $allowed[$block->handle] = $block;
        }

        return $allowed;
    }
}
