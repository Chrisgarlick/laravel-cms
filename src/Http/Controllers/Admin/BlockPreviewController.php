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
        ]);

        return response($preview->document($validated['blocks']), 200, [
            'Content-Type' => 'text/html; charset=utf-8',

            // Rendered inside the admin's own iframe only.
            'X-Frame-Options' => 'SAMEORIGIN',
            'Cache-Control' => 'no-store',
        ]);
    }
}
