<?php

declare(strict_types=1);

namespace Cg\Cms\Console\Content;

use Cg\Cms\Content\ContentException;
use Cg\Cms\Content\ContentService;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * What every cms:* content command shares: `--json` output for machines
 * (Claude Code reads it), readable output for people, problems listed by
 * field, and attribution to a user so the admin shows who made a change.
 */
abstract class ContentCommand extends Command
{
    protected function service(): ContentService
    {
        return app(ContentService::class);
    }

    /**
     * Run an action, printing its result or its problems.
     *
     * @param  Closure(): mixed  $action
     * @param  Closure(mixed): void  $human
     */
    protected function attempt(Closure $action, Closure $human): int
    {
        try {
            $result = $action();
        } catch (ContentException $e) {
            if ($this->option('json')) {
                $this->line((string) json_encode(['error' => $e->getMessage(), 'problems' => $e->problems], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            } else {
                $this->components->error($e->getMessage());

                foreach ($e->problems as $field => $messages) {
                    foreach ((array) $messages as $message) {
                        $this->components->twoColumnDetail($field, $message);
                    }
                }
            }

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $human($result);
        }

        return self::SUCCESS;
    }

    /** The user changes are attributed to: --as, then CMS_CLI_USER. */
    protected function userId(): ?int
    {
        $email = $this->option('as') ?: config('cg-cms.cli.user');

        if (! is_string($email) || $email === '') {
            return null;
        }

        $model = config('auth.providers.users.model');
        $user = $model::query()->where('email', $email)->first();

        if (! $user instanceof Authenticatable) {
            throw new ContentException("No user with the email [{$email}] to attribute this change to.");
        }

        return (int) $user->getAuthIdentifier();
    }

    /**
     * Values from --from (markdown with frontmatter) and --set name=value.
     *
     * Frontmatter keys are field names, plus `seo_title` and
     * `seo_description`. The markdown body goes into --field, or the
     * collection's first rich text field. A --set value that parses as JSON
     * (an array, a number, true) is used as JSON; anything else is a string.
     *
     * @param  array<int, array<string, mixed>>  $fields  The collection's fields, as collections() describes them
     * @return array<string, mixed>
     */
    protected function contentInput(array $fields): array
    {
        $values = [];
        $from = $this->option('from');

        if (is_string($from) && $from !== '') {
            if (! is_file($from)) {
                throw new ContentException("No such file: {$from}");
            }

            $raw = str_replace("\r\n", "\n", (string) file_get_contents($from));
            $meta = [];
            $body = $raw;

            if (preg_match('/^---\n(.*?)\n---\n?(.*)$/s', $raw, $matches) === 1) {
                try {
                    $parsed = Yaml::parse($matches[1]);
                } catch (ParseException $e) {
                    throw new ContentException('The frontmatter is not valid YAML: '.$e->getMessage().' Quote any value that contains a colon.');
                }

                $meta = is_array($parsed) ? $parsed : [];
                $body = $matches[2];
            }

            foreach ($meta as $key => $value) {
                if ($key === 'seo_title' || $key === 'seo_description') {
                    $values['seo'][substr($key, 4)] = $value;
                } else {
                    $values[$key] = $value;
                }
            }

            $target = $this->option('field') ?: collect($fields)->firstWhere('type', 'richText')['name'] ?? null;

            if (trim($body) !== '') {
                if ($target === null) {
                    throw new ContentException('This collection has no rich text field for the markdown body. Put values in the frontmatter instead.');
                }

                $values[$target] = $body;
            }
        }

        foreach ((array) $this->option('set') as $pair) {
            if (! str_contains((string) $pair, '=')) {
                throw new ContentException("--set needs name=value, got [{$pair}].");
            }

            [$name, $value] = explode('=', (string) $pair, 2);
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE && ! is_string($decoded) ? $decoded : $value;

            if (str_starts_with($name, 'seo.')) {
                $values['seo'][substr($name, 4)] = $value;
            } else {
                $values[$name] = $value;
            }
        }

        return $values;
    }

    /** @param  array<string, mixed>  $result */
    protected function printChecks(array $result): void
    {
        $issues = $result['checks']['issues'] ?? [];

        if ($issues === []) {
            $this->components->info('Checks: nothing to flag.');

            return;
        }

        foreach ($issues as $issue) {
            $line = sprintf('%s %s: %s', $issue['severity'] === 'block' ? '<fg=red>block</>' : '<fg=yellow>warn</>', $issue['field'], $issue['message']);
            $this->line('  '.$line.($issue['suggestion'] ? " <fg=gray>Try: {$issue['suggestion']}</>" : ''));
        }
    }
}
