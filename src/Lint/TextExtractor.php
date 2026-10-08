<?php

declare(strict_types=1);

namespace Cg\Cms\Lint;

/**
 * Flattens an authored field value into the prose a human wrote.
 *
 * Handles the three shapes a field can hold: a plain string, a TipTap document,
 * and an array of blocks whose data may itself contain documents.
 *
 * **Code is deliberately excluded.** Code blocks and inline code are skipped
 * entirely, because every rule here would otherwise fire on them and be wrong
 * every time. A CLI flag is `--write`, a CSS custom property is `--ink`, and an
 * American spelling inside a code sample is the API's spelling rather than the
 * author's. On a site whose subject matter is software, a linter that cannot
 * tell prose from code produces warnings nobody reads.
 */
final class TextExtractor
{
    /** Node types whose contents are code, not prose. */
    private const CODE_NODES = ['codeBlock'];

    /** Mark that makes a text node inline code. */
    private const CODE_MARK = 'code';

    /** Extract prose from any authored value. */
    public function extract(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return '';
        }

        // A TipTap document.
        if (($value['type'] ?? null) === 'doc') {
            return $this->fromNode($value);
        }

        // A list of blocks, each with its own nested field data.
        return $this->fromBlocks($value);
    }

    /** @param array<string, mixed> $node */
    private function fromNode(array $node): string
    {
        $type = $node['type'] ?? null;

        if (is_string($type) && in_array($type, self::CODE_NODES, true)) {
            return '';
        }

        if ($type === 'text') {
            return $this->isCode($node) ? '' : (string) ($node['text'] ?? '');
        }

        $content = $node['content'] ?? [];

        if (! is_array($content)) {
            return '';
        }

        $parts = [];

        foreach ($content as $child) {
            if (is_array($child)) {
                $parts[] = $this->fromNode($child);
            }
        }

        // Block-level nodes are joined with a newline so a rule cannot match
        // across a paragraph boundary and report a phrase nobody wrote.
        $separator = $this->isInline($type) ? '' : "\n";

        return implode($separator, array_filter($parts, fn (string $part): bool => $part !== ''));
    }

    /**
     * Prose held inside an array of blocks.
     *
     * Walks the whole structure rather than a known field list, because block
     * definitions live in the app and a linter that only checks the fields it
     * was told about will miss the one somebody added last week.
     *
     * @param  array<mixed>  $blocks
     */
    private function fromBlocks(array $blocks): string
    {
        $parts = [];

        foreach ($blocks as $key => $value) {
            // Structural keys, never prose. Linting them would flag a block
            // handle like `case-study-grid` as an en-dash range.
            if (in_array($key, ['type', '_type', 'id', '_key', 'theme'], true)) {
                continue;
            }

            if (is_string($value)) {
                $parts[] = $value;

                continue;
            }

            if (! is_array($value)) {
                continue;
            }

            $parts[] = ($value['type'] ?? null) === 'doc'
                ? $this->fromNode($value)
                : $this->fromBlocks($value);
        }

        return implode("\n", array_filter($parts, fn (string $part): bool => $part !== ''));
    }

    /** @param array<string, mixed> $node */
    private function isCode(array $node): bool
    {
        $marks = $node['marks'] ?? [];

        if (! is_array($marks)) {
            return false;
        }

        foreach ($marks as $mark) {
            if (is_array($mark) && ($mark['type'] ?? null) === self::CODE_MARK) {
                return true;
            }
        }

        return false;
    }

    private function isInline(mixed $type): bool
    {
        return in_array($type, ['text', 'hardBreak'], true);
    }
}
