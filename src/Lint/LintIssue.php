<?php

declare(strict_types=1);

namespace Cg\Cms\Lint;

/**
 * One thing a lint rule objected to.
 *
 * Carries enough to show the author where the problem is without making them
 * hunt: the field, a snippet of surrounding text, and where possible the exact
 * rewrite. A rule that can only say "this is wrong" is a rule people learn to
 * work around.
 */
final readonly class LintIssue
{
    public const BLOCK = 'block';

    public const WARN = 'warn';

    public function __construct(
        public string $rule,
        public string $field,
        public string $message,
        public string $excerpt = '',
        public ?string $suggestion = null,
        public string $severity = self::BLOCK,
    ) {}

    public function blocking(): bool
    {
        return $this->severity === self::BLOCK;
    }

    /**
     * The same issue at a different severity.
     *
     * A rule declares the severity it thinks it deserves; config has the final
     * say. Rather than passing config into every rule, the Linter re-stamps the
     * issue on the way out, which keeps the rules pure.
     */
    public function withSeverity(string $severity): self
    {
        return new self(
            rule: $this->rule,
            field: $this->field,
            message: $this->message,
            excerpt: $this->excerpt,
            suggestion: $this->suggestion,
            severity: $severity,
        );
    }

    /** The sentence an author actually reads, in the admin or on the console. */
    public function describe(): string
    {
        $text = $this->message;

        if ($this->excerpt !== '') {
            $text .= ' Found in: "'.$this->excerpt.'"';
        }

        if ($this->suggestion !== null) {
            $text .= ' Try: '.$this->suggestion;
        }

        return $text;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'field' => $this->field,
            'severity' => $this->severity,
            'message' => $this->message,
            'excerpt' => $this->excerpt,
            'suggestion' => $this->suggestion,
        ];
    }
}
