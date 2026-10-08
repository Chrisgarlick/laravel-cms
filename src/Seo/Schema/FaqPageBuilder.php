<?php

declare(strict_types=1);

namespace Cg\Cms\Seo\Schema;

use Cg\Cms\Models\Entry;
use Cg\Cms\Seo\SeoData;

/**
 * FAQPage, from any FAQ block on the entry.
 *
 * Reads the block data in two shapes: a flat `q1_question` / `q1_answer` set,
 * which is how `columns` and `offer-cards` already store repeated content, and a
 * nested list of pairs, which is how a repeatable field would store it when the
 * admin grows one in Phase 3. Supporting both now means this builder does not
 * need revisiting when the editor changes.
 *
 * Answers are emitted as the rendered HTML where it exists, because schema.org
 * `acceptedAnswer.text` permits basic markup and stripping the links out of an
 * answer makes it less useful to an answer engine than the page itself.
 */
final class FaqPageBuilder implements SchemaBuilder
{
    /** Block handles that hold questions and answers. */
    private const HANDLES = ['faq', 'faqs', 'accordion'];

    public function build(Entry $entry, SeoData $seo, array $context): array
    {
        $pairs = [];

        foreach ($entry->schema()->blockFields() as $field) {
            foreach ($this->faqBlocks($entry, $field->name) as $index => $block) {
                $pairs = [...$pairs, ...$this->pairsIn($entry, $block, $field->name, $index)];
            }
        }

        if ($pairs === []) {
            return [];
        }

        return [[
            '@type' => 'FAQPage',
            '@id' => $seo->canonical.'#faq',
            'mainEntity' => array_map(fn (array $pair): array => [
                '@type' => 'Question',
                'name' => $pair['question'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text' => $pair['answer'],
                ],
            ], $pairs),
        ]];
    }

    /** @return array<int, array<string, mixed>> */
    private function faqBlocks(Entry $entry, string $fieldName): array
    {
        $blocks = [];

        foreach ((array) $entry->value($fieldName, []) as $index => $block) {
            if (! is_array($block)) {
                continue;
            }

            $handle = $block['type'] ?? $block['_type'] ?? null;

            if (is_string($handle) && in_array($handle, self::HANDLES, true)) {
                $blocks[$index] = $block;
            }
        }

        return $blocks;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<int, array{question: string, answer: string}>
     */
    private function pairsIn(Entry $entry, array $block, string $fieldName, int $index): array
    {
        $data = is_array($block['data'] ?? null) ? $block['data'] : $block;
        $pairs = [];

        // The repeater shape, which the `faq` block uses.
        //
        // An answer is rich text, so the authored value is a document and the
        // HTML comes from `rendered` at the path the observer mirrors from
        // the data. Re-rendering it here would be a second renderer, which is
        // the thing Kritano issue 3d was about.
        foreach ((array) ($data['items'] ?? $data['questions'] ?? []) as $row => $item) {
            if (! is_array($item)) {
                continue;
            }

            $question = $this->text($item['question'] ?? null);

            $answer = (string) data_get(
                $entry->rendered,
                "{$fieldName}.{$index}.items.{$row}.answer",
                '',
            );

            // A plain string answer is legacy content or a simpler schema.
            $answer = $answer !== '' ? $answer : $this->text($item['answer'] ?? null);

            if ($question !== '' && $answer !== '') {
                $pairs[] = ['question' => $question, 'answer' => $answer];
            }
        }

        // Flattened pairs, for any legacy block storing them that way.
        for ($n = 1; $n <= 12; $n++) {
            $question = $this->text($data["q{$n}_question"] ?? null);

            if ($question === '') {
                continue;
            }

            $answer = (string) data_get($entry->rendered, "{$fieldName}.{$index}.q{$n}_answer", '');
            $answer = $answer !== '' ? $answer : $this->text($data["q{$n}_answer"] ?? null);

            if ($answer !== '') {
                $pairs[] = ['question' => $question, 'answer' => $answer];
            }
        }

        return $pairs;
    }

    private function text(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }

        // A rich text value that was never rendered. Better to skip it than to
        // emit an empty answer that invalidates the whole FAQPage node.
        return '';
    }
}
