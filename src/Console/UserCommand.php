<?php

declare(strict_types=1);

namespace Cg\Cms\Console;

use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Create an admin account, change its password, or remove it.
 *
 * The password is never an argument (it would land in shell history and the
 * process list): it is asked for with hidden input, or read from a key in
 * .env with --password-env, which should be deleted from .env straight after.
 * Removing an account first hands its edit history to another one with
 * --transfer-to, and the last account can never be removed.
 */
final class UserCommand extends Command
{
    protected $signature = 'cms:user
        {email : The account\'s email address}
        {--name= : Display name}
        {--password-env= : Read the password from this key in .env instead of asking}
        {--delete : Remove the account}
        {--transfer-to= : With --delete: who the account\'s edits are credited to instead}
        {--force : With --delete: don\'t ask for confirmation}';

    protected $description = 'Create an admin account or set its password (or --delete one)';

    private const MIN_LENGTH = 12;

    public function handle(): int
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->components->error("[{$email}] is not an email address.");

            return self::FAILURE;
        }

        return $this->option('delete') ? $this->delete($model, $email) : $this->save($model, $email);
    }

    /** @param  class-string<Model>  $model */
    private function save(string $model, string $email): int
    {
        $password = $this->password();

        if ($password === null) {
            return self::FAILURE;
        }

        $user = $model::query()->firstOrNew(['email' => $email]);
        $created = ! $user->exists;
        $user->forceFill([
            'name' => $this->option('name') ?: ($user->getAttribute('name') ?: ucfirst(strstr($email, '@', true) ?: $email)),
            'password' => Hash::make($password),
        ])->save();

        $signin = '/'.trim((string) config('cg-cms.admin.prefix', 'admin'), '/').'/signin';
        $this->components->info($created ? "Created {$email}. Sign in at {$signin}." : "Password changed for {$email}.");

        if ($key = $this->option('password-env')) {
            $this->components->warn("Now remove {$key} from .env: the password shouldn't stay on disk.");
        }

        return self::SUCCESS;
    }

    private function password(): ?string
    {
        if ($key = $this->option('password-env')) {
            $path = app()->environmentFilePath();
            $values = is_readable($path) ? Dotenv::parse((string) file_get_contents($path)) : [];
            $password = (string) ($values[$key] ?? '');

            if ($password === '') {
                $this->components->error("No {$key} in {$path}. Add {$key}=your-password, run this again, then delete the line.");

                return null;
            }
        } else {
            if (! $this->input->isInteractive()) {
                $this->components->error('No terminal to ask for the password: use --password-env=KEY.');

                return null;
            }

            $password = (string) $this->secret('Password (at least '.self::MIN_LENGTH.' characters)');

            if ($password !== (string) $this->secret('Same again')) {
                $this->components->error("The passwords didn't match. Nothing changed.");

                return null;
            }
        }

        if (mb_strlen($password) < self::MIN_LENGTH) {
            $this->components->error('The password must be at least '.self::MIN_LENGTH.' characters. Nothing changed.');

            return null;
        }

        return $password;
    }

    /** @param  class-string<Model>  $model */
    private function delete(string $model, string $email): int
    {
        $user = $model::query()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error("No account for {$email}.");

            return self::FAILURE;
        }

        if ($model::query()->count() === 1) {
            $this->components->error("{$email} is the only account. Create another first, or nobody can sign in.");

            return self::FAILURE;
        }

        $heir = null;

        if ($to = $this->option('transfer-to')) {
            $heir = $model::query()->where('email', mb_strtolower(trim((string) $to)))->first();

            if ($heir === null || $heir->getKey() === $user->getKey()) {
                $this->components->error("No other account for {$to} to transfer to.");

                return self::FAILURE;
            }
        }

        $question = "Remove {$email}?".($heir ? " Its edits will be credited to {$heir->getAttribute('email')}." : ' Its edits will show no author.');

        if (! $this->option('force') && ! $this->confirm($question)) {
            $this->components->info('Nothing changed.');

            return self::SUCCESS;
        }

        $moved = 0;
        DB::transaction(function () use ($user, $heir, &$moved): void {
            if ($heir !== null) {
                foreach ($this->referencesToUsers($user->getTable()) as [$table, $column]) {
                    $moved += DB::table($table)->where($column, $user->getKey())->update([$column => $heir->getKey()]);
                }
            }

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->getKey())->delete();
            }

            $user->delete();
        });

        $this->components->info("Removed {$email}.".($heir ? " {$moved} records now credited to {$heir->getAttribute('email')}." : ''));

        return self::SUCCESS;
    }

    /**
     * Every column, in any table, with a foreign key to the users table.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function referencesToUsers(string $usersTable): array
    {
        $found = [];

        foreach (Schema::getTables() as $table) {
            foreach (Schema::getForeignKeys($table['name']) as $key) {
                if ($key['foreign_table'] === $usersTable && count($key['columns']) === 1) {
                    $found[] = [$table['name'], $key['columns'][0]];
                }
            }
        }

        return $found;
    }
}
