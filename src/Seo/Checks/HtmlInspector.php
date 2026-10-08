<?php

declare(strict_types=1);

namespace Cg\Cms\Seo\Checks;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Reads the facts the SEO checks need out of a page or a fragment.
 *
 * Parsed with DOMDocument rather than matched with regular expressions,
 * because the checks care about structure (which heading comes after which,
 * whether an img has an alt attribute at all or an empty one) and a regex
 * that is right about attributes in every quoting style is a parser.
 *
 * Content checks read from `<main>` when the page has one. Navigation and
 * footers are the same on every page, so counting their links would make
 * "at least two internal links" pass on an empty article, and a logo marked
 * up as an h1 would make every page look like it has two.
 */
final class HtmlInspector
{
    private readonly DOMXPath $xpath;

    private readonly ?DOMElement $scope;

    public function __construct(string $html)
    {
        $document = new DOMDocument;

        // The XML declaration is the documented way to make libxml read the
        // input as UTF-8 rather than Latin-1, which otherwise turns every
        // curly quote in a title into three characters and every length
        // check into a lie.
        if (trim($html) !== '') {
            @$document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        }

        $this->xpath = new DOMXPath($document);

        $main = $this->xpath->query('//main')?->item(0);
        $body = $this->xpath->query('//body')?->item(0);

        $this->scope = $main instanceof DOMElement ? $main : ($body instanceof DOMElement ? $body : null);
    }

    public function title(): ?string
    {
        return $this->firstText('//head/title');
    }

    public function metaDescription(): ?string
    {
        $node = $this->xpath->query('//head/meta[@name="description"]')?->item(0);

        return $node instanceof DOMElement ? trim($node->getAttribute('content')) : null;
    }

    public function robots(): ?string
    {
        $node = $this->xpath->query('//head/meta[@name="robots"]')?->item(0);

        return $node instanceof DOMElement ? strtolower($node->getAttribute('content')) : null;
    }

    /**
     * Headings in document order.
     *
     * @return array<int, array{level: int, text: string}>
     */
    public function headings(): array
    {
        $headings = [];

        foreach ($this->scoped('.//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]') as $node) {
            $headings[] = [
                'level' => (int) substr($node->nodeName, 1),
                'text' => $this->normalise($node->textContent),
            ];
        }

        return $headings;
    }

    /**
     * Every image, with its alt attribute. Null means the attribute is absent.
     *
     * @return array<int, array{src: string, alt: string|null}>
     */
    public function images(): array
    {
        $images = [];

        foreach ($this->scoped('.//img') as $node) {
            $images[] = [
                'src' => $node->getAttribute('src'),
                'alt' => $node->hasAttribute('alt') ? trim($node->getAttribute('alt')) : null,
            ];
        }

        return $images;
    }

    /**
     * Link targets. Whole document when $everywhere, content only otherwise.
     *
     * @return array<int, string>
     */
    public function links(bool $everywhere = false): array
    {
        $nodes = $everywhere
            ? $this->xpath->query('//a[@href]')
            : $this->scoped('.//a[@href]');

        $links = [];

        foreach ($nodes ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $links[] = trim($node->getAttribute('href'));
            }
        }

        return $links;
    }

    /** The content's visible text, whitespace collapsed. */
    public function text(): string
    {
        if ($this->scope === null) {
            return '';
        }

        // Script and style text is not prose, and JSON-LD inside <main>
        // would otherwise count as the page's opening words.
        $clone = $this->scope->cloneNode(true);

        if ($clone instanceof DOMElement) {
            foreach (iterator_to_array($clone->getElementsByTagName('script')) as $script) {
                $script->parentNode?->removeChild($script);
            }

            foreach (iterator_to_array($clone->getElementsByTagName('style')) as $style) {
                $style->parentNode?->removeChild($style);
            }
        }

        return $this->normalise($clone->textContent);
    }

    /**
     * Each JSON-LD block, decoded. False for one that does not parse.
     *
     * @return array<int, array<mixed>|false>
     */
    public function jsonLd(): array
    {
        $blocks = [];

        foreach ($this->xpath->query('//script[@type="application/ld+json"]') ?: [] as $node) {
            $decoded = json_decode((string) $node->textContent, true);
            $blocks[] = is_array($decoded) ? $decoded : false;
        }

        return $blocks;
    }

    /** @return iterable<DOMElement> */
    private function scoped(string $query): iterable
    {
        if ($this->scope === null) {
            return [];
        }

        $nodes = $this->xpath->query($query, $this->scope);

        $elements = [];

        foreach ($nodes ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    private function firstText(string $query): ?string
    {
        $node = $this->xpath->query($query)?->item(0);

        return $node === null ? null : $this->normalise($node->textContent);
    }

    private function normalise(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
