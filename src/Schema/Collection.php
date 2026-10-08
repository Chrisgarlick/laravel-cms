<?php

declare(strict_types=1);

namespace Cg\Cms\Schema;

use Cg\Cms\Validation\ValidBlocks;
use Closure;
use Illuminate\Support\Str;

/**
 * A content type.
 *
 * Declared in config, never in a migration. Adding a field is a config change;
 * only promoting a field to an indexed generated column touches the schema, and
 * `php artisan cms:sync-schema` writes that migration for you to review.
 */
final class Collection
{
    /**
     * Columns that exist on `entries` in their own right.
     *
     * Every collection filters or sorts on these, so they are real columns
     * rather than jsonb keys. Promoting one is a no-op and `cms:sync-schema`
     * says so rather than generating a duplicate.
     *
     * @var array<int, string>
     */
    public const NATIVE_COLUMNS = [
        'id', 'collection', 'slug', 'locale', 'status', 'title',
        'published_at', 'sort_order', 'created_at', 'updated_at', 'deleted_at',
    ];

    private ?string $route = null;

    private ?string $indexRoute = null;

    private string $orderColumn = 'published_at';

    private string $orderDirection = 'desc';

    /** @var array<int, Field> */
    private array $fields = [];

    /** @var array<int, string> */
    private array $promoted = [];

    private ?Closure $cacheTagResolver = null;

    private ?string $schemaOrgBuilder = null;

    private ?string $seoTitleTemplate = null;

    private ?string $seoDescriptionField = null;

    private ?string $seoImageField = null;

    private ?string $label = null;

    /** @var array<string, string> slug => path */
    private array $paths = [];

    private function __construct(public readonly string $handle) {}

    public static function make(string $handle): self
    {
        return new self($handle);
    }

    /**
     * Human name for this collection.
     *
     * Used in breadcrumbs, the sitemap and llms.txt. Defaults to the handle in
     * headline case, which is right for `article` and wrong for anything whose
     * navigation label differs from its handle.
     */
    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function labelText(): string
    {
        return $this->label ?? Str::headline($this->handle);
    }

    public function route(string $pattern): self
    {
        $this->route = $pattern;

        return $this;
    }

    /**
     * Give particular entries a URL of their own, outside the route pattern.
     *
     * For a collection whose entries do not share one URL shape. The live site
     * builds its home, about, contact, service and industry pages all from the
     * `page` collection, each at an address the `/page/{slug}` pattern cannot
     * describe. Declared here so Entry::url() knows them, which is what makes
     * canonicals, the sitemap, slug-change redirects, cache purging and the
     * audit all agree on where a page lives.
     *
     *     Collection::make('page')->route('/page/{slug}')->paths([
     *         'home' => '/',
     *         'about' => '/about',
     *     ])
     *
     * The app still has to route the path to something that renders the
     * entry; this only tells the CMS what the address is.
     *
     * @param  array<string, string>  $paths  slug => absolute path
     */
    public function paths(array $paths): self
    {
        foreach ($paths as $slug => $path) {
            $this->paths[(string) $slug] = '/'.ltrim((string) $path, '/');
        }

        return $this;
    }

    /** The declared path for a slug, or null when it follows the pattern. */
    public function pathFor(string $slug): ?string
    {
        return $this->paths[$slug] ?? null;
    }

    /** @return array<string, string> */
    public function declaredPaths(): array
    {
        return $this->paths;
    }

    /** Where an entry with this slug lives, or null for a routeless collection. */
    public function urlFor(string $slug): ?string
    {
        if (isset($this->paths[$slug])) {
            return $this->paths[$slug];
        }

        return $this->route === null ? null : str_replace('{slug}', $slug, $this->route);
    }

    public function indexRoute(string $path): self
    {
        $this->indexRoute = $path;

        return $this;
    }

    public function orderBy(string $column, string $direction = 'asc'): self
    {
        $this->orderColumn = $column;
        $this->orderDirection = $direction;

        return $this;
    }

    /** @param array<int, Field> $fields */
    public function fields(array $fields): self
    {
        $this->fields = $fields;

        return $this;
    }

    /**
     * Fields to expose as Postgres generated columns plus an index.
     *
     * Promote anything you filter or sort on. jsonb extraction is cheap once
     * the row is in memory, but it cannot be indexed usefully without this.
     *
     * @param  array<int, string>  $fields
     */
    public function promote(array $fields): self
    {
        $this->promoted = $fields;

        return $this;
    }

    public function cacheTags(Closure $resolver): self
    {
        $this->cacheTagResolver = $resolver;

        return $this;
    }

    public function schemaOrg(string $builderClass): self
    {
        $this->schemaOrgBuilder = $builderClass;

        return $this;
    }

    /**
     * Title template for entries in this collection.
     *
     * Supports `{title}` and `{site}`. The middle rung of the fallback chain:
     * an entry's own SEO title wins, this is used when there is not one, and
     * the site-wide template catches collections that do not set one.
     */
    public function seoTitle(string $template): self
    {
        $this->seoTitleTemplate = $template;

        return $this;
    }

    /**
     * Field to fall back to for the meta description.
     *
     * Every collection names its summary something different (`excerpt`,
     * `summary`, `description`), and guessing in the resolver would mean a
     * list of candidate names that silently rots as the schema changes.
     */
    public function seoDescriptionFrom(string $field): self
    {
        $this->seoDescriptionField = $field;

        return $this;
    }

    /**
     * Media field to use as the social image.
     *
     * Named explicitly rather than discovered. An earlier version walked the
     * field list and took the first media field it found, which is convenient
     * right up until a collection has an image it is not allowed to publish:
     * `project` stores a cover image for work that is under NDA, and a resolver
     * that helps itself to any media field would put it in og:image on a page
     * whose whole design is to never show it.
     *
     * A collection that names nothing gets the site default image.
     */
    public function seoImageFrom(string $field): self
    {
        $this->seoImageField = $field;

        return $this;
    }

    public function seoImageField(): ?string
    {
        return $this->seoImageField;
    }

    public function seoTitleTemplate(): ?string
    {
        return $this->seoTitleTemplate;
    }

    public function seoDescriptionField(): ?string
    {
        return $this->seoDescriptionField;
    }

    public function routePattern(): ?string
    {
        return $this->route;
    }

    public function indexPath(): ?string
    {
        return $this->indexRoute;
    }

    public function orderColumn(): string
    {
        return $this->orderColumn;
    }

    public function orderDirection(): string
    {
        return $this->orderDirection;
    }

    /** @return array<int, Field> */
    public function allFields(): array
    {
        return $this->fields;
    }

    public function field(string $name): ?Field
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Fields whose value is pre-rendered to HTML on save.
     *
     * @return array<int, Field>
     */
    public function richTextFields(): array
    {
        return array_values(array_filter(
            $this->fields,
            fn (Field $field): bool => $field->isRichText(),
        ));
    }

    /**
     * Fields holding an ordered list of blocks.
     *
     * @return array<int, Field>
     */
    public function blockFields(): array
    {
        return array_values(array_filter(
            $this->fields,
            fn (Field $field): bool => $field->type === 'blocks',
        ));
    }

    /** @return array<int, string> */
    public function promotedFields(): array
    {
        return $this->promoted;
    }

    public function schemaOrgBuilder(): ?string
    {
        return $this->schemaOrgBuilder;
    }

    public function cacheTagResolver(): ?Closure
    {
        return $this->cacheTagResolver;
    }

    /**
     * Validation rules for a full entry payload.
     *
     * @return array<string, array<int, string>>
     */
    public function validationRules(): array
    {
        $rules = [];

        foreach ($this->fields as $field) {
            $rules[$field->name] = $field->validationRules();

            if ($field->type === 'blocks') {
                // Which rules apply to a block item depends on the type that
                // item declares, which a wildcard key cannot express. One
                // rule object walks the list instead.
                $rules[$field->name][] = new ValidBlocks($field);
            }

            // `faqs.*.question` and deeper. Laravel expands the wildcards.
            $rules = [...$rules, ...$field->nestedValidationRules()];
        }

        return $rules;
    }
}
