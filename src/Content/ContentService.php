<?php

declare(strict_types=1);

namespace Cg\Cms\Content;

use Cg\Cms\Admin\EntryPayload;
use Cg\Cms\Lint\Linter;
use Cg\Cms\Lint\LintIssue;
use Cg\Cms\Models\Entry;
use Cg\Cms\Models\EntryRevision;
use Cg\Cms\Schema\Collection;
use Cg\Cms\Schema\CollectionRegistry;
use Cg\Cms\Schema\Field;
use Cg\Cms\Seo\Checks\EntrySeoChecks;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Every content action available outside the admin, in one place.
 *
 * The CLI uses it now; the JSON API and the MCP server will be thin doors
 * over the same methods, so they cannot disagree about the rules. And the
 * rules are the admin's: schema validation, the brand voice linter and the
 * SEO checks all run, because writes go through the same model and observer.
 *
 * Two rules of its own, from claude_integration_plan.md: nothing here
 * publishes, and nothing here changes a live entry. New entries are always
 * drafts. A change to a published entry is stored as a proposal the editor
 * shows for review, never applied.
 */
final class ContentService
{
    public const PROPOSAL_LABEL = 'proposed';

    /** Keys that only a person in the admin may set. */
    private const PUBLISHING_KEYS = ['status', 'published_at'];

    public function __construct(
        private readonly CollectionRegistry $collections,
        private readonly EntryPayload $payload,
        private readonly Linter $linter,
        private readonly EntrySeoChecks $seoChecks,
        private readonly MarkdownToTiptap $markdown,
    ) {}

    /** @return array<int, array<string, mixed>> */
    public function collections(): array
    {
        return array_values(array_map(fn (Collection $collection): array => [
            'handle' => $collection->handle,
            'label' => $collection->labelText(),
            'url' => $collection->routePattern(),
            'fields' => array_values(array_map(fn (Field $field): array => array_filter([
                'name' => $field->name,
                'type' => $field->type,
                'required' => $field->isRequired() ?: null,
                'options' => $field->selectOptions() ?: null,
                'to' => $field->relatedCollection(),
                'help' => $field->helpText(),
                'markdown' => $field->type === 'richText' ? 'Accepts markdown' : null,
            ], fn (mixed $value): bool => $value !== null), array_filter(
                $collection->allFields(),
                fn (Field $field): bool => ! in_array($field->name, self::PUBLISHING_KEYS, true),
            ))),
        ], $this->collections->all()));
    }

    /** @return array<int, array<string, mixed>> */
    public function search(?string $collection = null, ?string $query = null, ?string $status = null, int $limit = 20): array
    {
        if ($collection !== null) {
            $this->schema($collection);
        }

        return Entry::query()
            ->whereIn('collection', $collection !== null ? [$collection] : $this->collections->handles())
            ->when($status === 'published', fn ($q) => $q->where('status', 'published'))
            ->when($status === 'draft', fn ($q) => $q->where('status', '!=', 'published'))
            ->when(filled($query), function ($q) use ($query): void {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], (string) $query).'%';
                $q->where(fn ($inner) => $inner->where('title', 'ilike', $like)->orWhere('slug', 'ilike', $like));
            })
            ->orderByDesc('updated_at')
            ->limit(max(1, min($limit, 200)))
            ->get()
            ->map(fn (Entry $entry): array => $this->summary($entry))
            ->all();
    }

    /**
     * An entry by `collection/slug` or `collection/id`.
     */
    public function find(string $reference): Entry
    {
        if (! str_contains($reference, '/')) {
            throw new ContentException("Use collection/slug or collection/id, for example article/{$reference}.");
        }

        [$collection, $key] = explode('/', $reference, 2);
        $this->schema($collection);

        $entry = Entry::query()
            ->collection($collection)
            ->where(fn ($q) => ctype_digit($key) ? $q->where('id', (int) $key)->orWhere('slug', $key) : $q->where('slug', $key))
            ->first();

        return $entry ?? throw new ContentException("No {$collection} entry [{$key}].");
    }

    /** @return array<string, mixed> */
    public function describe(Entry $entry): array
    {
        $schema = $entry->schema();
        $fields = [];

        foreach ($schema->allFields() as $field) {
            if ($field->isSeo() || in_array($field->name, ['title', 'slug', ...self::PUBLISHING_KEYS], true)) {
                continue;
            }

            // Rich text as its rendered HTML: readable, where the stored
            // TipTap JSON is not. Writes take markdown.
            // Blocks stay structured: each block's type and fields.
            $fields[$field->name] = $field->type === 'richText'
                ? (string) data_get($entry->rendered, $field->name, '')
                : $entry->value($field->name);
        }

        $proposal = $this->proposal($entry);

        return [
            ...$this->summary($entry),
            'fields' => $fields,
            'seo' => (array) $entry->seo,
            'checks' => $this->checks($entry),
            'proposal' => $proposal === null ? null : ['createdAt' => $proposal->created_at?->toIso8601String()],
        ];
    }

    /**
     * Lint and SEO findings for an entry as it stands.
     *
     * @return array{blocking: bool, issues: array<int, array<string, mixed>>}
     */
    public function checks(Entry $entry): array
    {
        app(EntryObserver::class)->prerender($entry);

        $issues = [
            ...array_map(fn (LintIssue $issue): array => ['kind' => 'voice', ...$issue->toArray()], $this->linter->lint($entry)),
            ...array_map(fn (LintIssue $issue): array => ['kind' => 'seo', ...$issue->toArray()], $this->seoChecks->check($entry)),
        ];

        return [
            'blocking' => collect($issues)->contains(fn (array $issue): bool => $issue['severity'] === 'block'),
            'issues' => $issues,
        ];
    }

    /**
     * A new draft. Never published, whatever the input says.
     *
     * @param  array<string, mixed>  $input  title, slug, seo, and any field by name; rich text as markdown
     * @return array<string, mixed>
     */
    public function createDraft(string $collection, array $input, ?int $userId = null): array
    {
        $schema = $this->schema($collection);
        $values = $this->prepare($schema, $input);

        if (! filled($values['title'] ?? null)) {
            throw new ContentException('A title is required.', ['title' => ['A title is required.']]);
        }

        $this->validate($schema, $values);

        $entry = new Entry([
            'collection' => $collection,
            ...$this->payload->toAttributes($schema, $values),
            'status' => 'draft',
            'published_at' => null,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        $this->save($entry);

        return ['action' => 'created', ...$this->describe($entry->refresh())];
    }

    /**
     * Change an entry. A draft is updated in place; a published or scheduled
     * entry is left alone and the change stored as a proposal for review.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(Entry $entry, array $input, ?int $userId = null): array
    {
        $schema = $entry->schema();
        $values = $this->prepare($schema, $input);

        if ($values === []) {
            throw new ContentException('Nothing to change.');
        }

        $this->validatePartial($schema, $values);
        $attributes = $this->payload->merged($entry, $this->payload->toAttributes($schema, $values));

        if ($entry->status === 'draft') {
            $entry->fill([...$attributes, 'updated_by' => $userId]);
            $this->save($entry);

            return ['action' => 'updated', ...$this->describe($entry->refresh())];
        }

        // Live: propose, do not apply. Checked as if applied, so the review
        // shows what the change would do, then thrown away unsaved.
        $candidate = (clone $entry)->fill($attributes);
        $checks = $this->checks($candidate);

        EntryRevision::query()->upsert(
            [[
                'entry_id' => $entry->id,
                'label' => self::PROPOSAL_LABEL,
                'snapshot' => json_encode([
                    'title' => $candidate->title,
                    'slug' => $candidate->slug,
                    'status' => $entry->status,
                    'data' => $candidate->data,
                    'seo' => $candidate->seo,
                ], JSON_THROW_ON_ERROR),
                'created_by' => $userId,
                'created_at' => now(),
            ]],
            uniqueBy: ['entry_id', 'label'],
            update: ['snapshot', 'created_by', 'created_at'],
        );

        return [
            'action' => 'proposed',
            'message' => 'This entry is live, so the change was saved as a proposal. Review it in the editor, where it can be applied or discarded.',
            ...$this->summary($entry),
            'checks' => $checks,
        ];
    }

    /**
     * Update an entry in place, live or not.
     *
     * For commands a person runs to install known content, such as a deploy
     * step. The content CLI Claude uses never calls this: it goes through
     * update(), which turns a change to a live entry into a proposal.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function apply(Entry $entry, array $input, ?int $userId = null): array
    {
        $schema = $entry->schema();
        $values = $this->prepare($schema, $input);

        if ($values === []) {
            throw new ContentException('Nothing to change.');
        }

        $this->validatePartial($schema, $values);
        $entry->fill([...$this->payload->merged($entry, $this->payload->toAttributes($schema, $values)), 'updated_by' => $userId]);
        $this->save($entry);

        // Applying supersedes anything proposed against the old version.
        EntryRevision::query()->where('entry_id', $entry->id)->where('label', self::PROPOSAL_LABEL)->delete();

        return ['action' => 'applied', ...$this->describe($entry->refresh())];
    }

    /**
     * Publish an entry, through the same publish gate as the admin.
     *
     * Only for commands a person runs (see apply()); the content CLI refuses
     * to publish by design.
     *
     * @return array<string, mixed>
     */
    public function publish(Entry $entry, ?int $userId = null): array
    {
        if ($entry->status === 'published') {
            return ['action' => 'unchanged', ...$this->describe($entry)];
        }

        $entry->fill([
            'status' => 'published',
            'published_at' => $entry->published_at ?? now(),
            'updated_by' => $userId,
        ]);
        $this->save($entry);

        return ['action' => 'published', ...$this->describe($entry->refresh())];
    }

    public function proposal(Entry $entry): ?EntryRevision
    {
        return $entry->revisions()->where('label', self::PROPOSAL_LABEL)->first();
    }

    /** @return array<string, mixed> */
    private function summary(Entry $entry): array
    {
        $prefix = '/'.trim((string) config('cg-cms.admin.prefix', 'admin'), '/');

        return [
            'id' => $entry->id,
            'collection' => $entry->collection,
            'title' => $entry->title,
            'slug' => $entry->slug,
            'status' => $entry->status,
            'url' => $entry->status === 'published' && ! $entry->routeless() ? $entry->url() : null,
            'editUrl' => url("{$prefix}/{$entry->collection}/{$entry->id}/edit"),
            'updatedAt' => $entry->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Input to editor-shaped values: known fields only, markdown converted,
     * publishing refused.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function prepare(Collection $schema, array $input): array
    {
        $refused = array_values(array_intersect(array_keys($input), self::PUBLISHING_KEYS));

        if ($refused !== []) {
            throw new ContentException('Publishing is done by a person in the admin; this cannot set '.implode(' or ', $refused).'. Leave it out and the entry stays a draft.');
        }

        $known = array_map(fn (Field $field): string => $field->name, $schema->allFields());
        $unknown = array_values(array_diff(array_keys($input), $known));

        if ($unknown !== []) {
            throw new ContentException(sprintf(
                'Unknown field%s for %s: %s. Fields: %s.',
                count($unknown) === 1 ? '' : 's',
                $schema->handle,
                implode(', ', $unknown),
                implode(', ', array_diff($known, self::PUBLISHING_KEYS)),
            ));
        }

        $values = [];

        foreach ($input as $name => $value) {
            $field = $schema->field($name);

            $values[$name] = $field?->type === 'richText' && is_string($value)
                ? $this->markdown->convert($value)
                : $value;
        }

        if (isset($values['slug']) && is_string($values['slug'])) {
            $values['slug'] = Str::slug($values['slug']);
        }

        return $values;
    }

    /** @param  array<string, mixed>  $values */
    private function validate(Collection $schema, array $values): void
    {
        $validator = Validator::make($values, [...$schema->validationRules(), 'seo' => ['sometimes', 'array']]);

        if ($validator->fails()) {
            throw new ContentException('The content does not fit the schema.', $validator->errors()->toArray());
        }
    }

    /**
     * Only the rules for the fields being changed, so updating one field of
     * an entry that predates a new required field does not fail on the other.
     *
     * @param  array<string, mixed>  $values
     */
    private function validatePartial(Collection $schema, array $values): void
    {
        $rules = array_filter(
            [...$schema->validationRules(), 'seo' => ['sometimes', 'array']],
            fn (string $key): bool => array_key_exists(explode('.', $key)[0], $values),
            ARRAY_FILTER_USE_KEY,
        );

        $validator = Validator::make($values, $rules);

        if ($validator->fails()) {
            throw new ContentException('The content does not fit the schema.', $validator->errors()->toArray());
        }
    }

    /** The observer's brand voice and publish gate, reported rather than thrown. */
    private function save(Entry $entry): void
    {
        try {
            $entry->save();
        } catch (ValidationException $e) {
            throw new ContentException('Not saved: the brand voice rules or the publish gate refused it.', $e->errors());
        }
    }

    private function schema(string $handle): Collection
    {
        if (! $this->collections->has($handle)) {
            throw new ContentException("Unknown collection [{$handle}]. Collections: ".implode(', ', $this->collections->handles()).'.');
        }

        return $this->collections->get($handle);
    }
}
