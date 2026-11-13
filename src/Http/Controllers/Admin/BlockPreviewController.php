<?php

declare(strict_types=1);

namespace Cg\Cms\Http\Controllers\Admin;

use Cg\Cms\Content\BlockPreview;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Live block previews for the editor: unsaved blocks in, the site's own HTML
 * out, shown in a sandboxed iframe beside the fields.
 */
final class BlockPreviewController
{
    public function __invoke(Request $request, BlockPreview $preview): Response
    {
        $validated = $request->validate([
            'blocks' => ['present', 'array', 'max:100'],
            'blocks.*.type' => ['required', 'string'],
            'blocks.*.data' => ['nullable', 'array'],
            'fields' => ['sometimes', 'nullable', 'array', 'max:50'],
        ]);

        return response($preview->document($validated['blocks'], $this->simple($validated['fields'] ?? [])), 200, [
            'Content-Type' => 'text/html; charset=utf-8',

            // Rendered inside the admin's own iframe only.
            'X-Frame-Options' => 'SAMEORIGIN',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * The entry's other values, kept only where they are simple: a string of
     * at most 500 characters, a number, a boolean or null. Anything nested is
     * dropped rather than refused, so a preview never fails over a field the
     * document was never going to read.
     *
     * @param  array<array-key, mixed>  $fields
     * @return array<string, string|int|float|bool|null>
     */
    private function simple(array $fields): array
    {
        return array_filter(
            $fields,
            fn (mixed $value, int|string $name): bool => is_string($name)
                && ($value === null || is_bool($value) || is_int($value) || is_float($value)
                    || (is_string($value) && mb_strlen($value) <= 500)),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
