<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;

/**
 * Installs the database schema without needing the `mysql` client binary.
 *
 * ---------------------------------------------------------------------------
 * Why this is needed
 * ---------------------------------------------------------------------------
 * This project's migrations were squashed into database/schema/mysql-schema.sql
 * and the individual migration files removed. On a fresh database Laravel's own
 * `migrate` tries to load that dump via MySqlSchemaState::load(), which shells
 * out to the `mysql` command-line binary through proc_open.
 *
 * Shared hosting frequently has neither: no `mysql` client on PATH, and
 * proc_open/exec disabled. `php artisan migrate` then dies with a
 * ProcessFailedException against an empty database — which is exactly what
 * happens on this machine too.
 *
 * This command reads the same dump and applies it over the existing PDO
 * connection, so it works anywhere PHP can already reach MySQL. Afterwards it
 * hands over to the normal migrator for anything added since the squash.
 *
 * Usage on a fresh deployment:
 *
 *     php artisan db:install
 *
 * If you have no shell at all, importing database/schema/mysql-schema.sql
 * through phpMyAdmin achieves the same thing — the dump already contains the
 * migrations table and its rows, so Laravel will consider the schema current.
 */
class InstallDatabase extends Command
{
    protected $signature = 'db:install
                            {--force : Apply the schema even if tables already exist}';

    protected $description = 'Load the SQL schema over PDO (no mysql binary required), then run pending migrations';

    public function handle(): int
    {
        $schemaPath = database_path('schema/mysql-schema.sql');

        if (! file_exists($schemaPath)) {
            $this->error("No schema dump at {$schemaPath}.");

            return self::FAILURE;
        }

        $database = DB::connection()->getDatabaseName();
        $alreadyInstalled = Schema::hasTable('migrations');

        if ($alreadyInstalled && ! $this->option('force')) {
            $this->info("`{$database}` already has a migrations table — skipping schema load.");
            $this->line('Pass --force to apply the dump over the existing schema.');
        } else {
            $this->warn("Applying schema to `{$database}`.");

            if ($alreadyInstalled && ! $this->confirmToProceed()) {
                return self::FAILURE;
            }

            $this->loadSchema($schemaPath);
        }

        // Anything added after the squash still goes through the normal path.
        $this->newLine();
        $this->line('Running any pending migrations…');
        $this->call('migrate', ['--force' => true]);

        $this->newLine();
        $this->info('Database ready.');

        return self::SUCCESS;
    }

    private function loadSchema(string $path): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        $statements = preg_split("/;\s*\n/", file_get_contents($path));
        $executed = 0;

        $bar = $this->output->createProgressBar(count($statements));

        foreach ($statements as $statement) {
            $statement = trim($statement);
            $bar->advance();

            if ($statement === '' || str_starts_with($statement, '--') || str_starts_with($statement, '/*')) {
                continue;
            }

            $pdo->exec($statement);
            $executed++;
        }

        $bar->finish();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        $this->newLine(2);
        $this->info("Applied {$executed} statements.");
    }

    /** Guard against wiping a populated database by accident. */
    private function confirmToProceed(): bool
    {
        if (app()->environment('production')) {
            return $this->confirm('This database already has a schema. Re-applying may drop data. Continue?', false);
        }

        return true;
    }
}
