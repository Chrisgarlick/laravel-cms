<?php

declare(strict_types=1);

namespace Cg\Cms\Seo;

/**
 * Everything the head of a page needs, already resolved.
 *
 * A plain value object rather than a bag of nullable lookups, because the
 * fallback chain has to happen exactly once and in one place. A template that
 * can ask "is there a custom title?" is a template that can get the answer
 * wrong, and every page that renders its own meta is a page that can forget a
 * tag.
 */
final readonly class SeoData
{
    /**
     * @param  array<int, string>  $keywords  Retained for the resource collection, which
     *                                        already carries them. Not emitted as a meta
     *                                        tag: no search engine has used that since
     *                                        2009. They feed the SEO audit instead.
     */
    public function __construct(
        public string $title,
        public string $description,
        public string $canonical,
        public ?string $image = null,
        public ?string $imageAlt = null,
        public string $type = 'website',
        public bool $noindex = false,
        public bool $nofollow = false,
        public ?string $publishedAt = null,
        public ?string $modifiedAt = null,
        public array $keywords = [],
    ) {}

    /**
     * The robots directive, or null when the defaults apply.
     *
     * Emitting `index, follow` is the default behaviour spelled out, so it is
     * bytes on every page that change nothing. Only the exceptions are worth a
     * tag.
     */
    public function robots(): ?string
    {
        if (! $this->noindex && ! $this->nofollow) {
            return null;
        }

        return implode(', ', [
            $this->noindex ? 'noindex' : 'index',
            $this->nofollow ? 'nofollow' : 'follow',
        ]);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'canonical' => $this->canonical,
            'image' => $this->image,
            'imageAlt' => $this->imageAlt,
            'type' => $this->type,
            'robots' => $this->robots(),
            'publishedAt' => $this->publishedAt,
            'modifiedAt' => $this->modifiedAt,
        ];
    }
}
