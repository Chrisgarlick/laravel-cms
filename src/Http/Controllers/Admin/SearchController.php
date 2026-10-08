<?php

declare(strict_types=1);

namespace Cg\Cms\Http\Controllers\Admin;

use Cg\Cms\Models\Entry;
use Cg\Cms\Models\Media;
use Cg\Cms\Schema\CollectionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Backs the ⌘K command palette.
 *
 * JSON, not an Inertia response: the palette fetches as you type and must not
 * touch the page's props or history. Entries match on title or slug, media on
 * filename or alt text. With no query it returns the most recently edited
 * entries, which is what the palette shows the moment it opens.
 */
final class SearchController
{
    private const LIMIT = 8;

    public function __invoke(Request $request, CollectionRegistry $collections): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));
        $prefix = '/'.ltrim((string) config('cg-cms.admin.prefix', 'admin'), '/');

        $entries = Entry::query()
            ->whereIn('collection', $collections->handles())
            ->when($term !== '', function ($query) use ($term): void {
                $like = '%'.$this->escapeLike($term).'%';
                $query->where(fn ($inner) => $inner->where('title', 'ilike', $like)->orWhere('slug', 'ilike', $like));
            })
            ->orderByDesc('updated_at')
            ->limit(self::LIMIT)
            ->get(['id', 'collection', 'title', 'slug', 'status'])
            ->map(fn (Entry $entry): array => [
                'id' => $entry->id,
                'title' => $entry->title !== '' ? $entry->title : $entry->slug,
                'collection' => $collections->get($entry->collection)->labelText(),
                'status' => $entry->status,
                'href' => "{$prefix}/{$entry->collection}/{$entry->id}/edit",
            ])
            ->all();

        $media = $term === '' ? [] : Media::query()
            ->where(function ($query) use ($term): void {
                $like = '%'.$this->escapeLike($term).'%';
                $query->where('filename', 'ilike', $like)->orWhere('alt', 'ilike', $like);
            })
            ->latest()
            ->limit(5)
            ->get()
            ->map(fn (Media $item): array => [
                'id' => $item->id,
                'title' => $item->filename,
                'alt' => $item->alt,
                'href' => "{$prefix}/media?selected={$item->id}",
            ])
            ->all();

        return response()->json(['entries' => $entries, 'media' => $media]);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
