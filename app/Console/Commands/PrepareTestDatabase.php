<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PDO;
use PDOException;

/**
 * Builds the `taga_test` database from the committed schema dump.
 *
 * This project has no migration files — they were squashed into
 * database/schema/mysql-schema.sql — so `migrate:fresh` cannot build a schema
 * and the usual test setup does not apply. This command recreates the test
 * database from that dump instead.
 *
 * It refuses to touch any database other than the configured test one, so it
 * cannot be pointed at `taga` by accident.
 */
class PrepareTestDatabase extends Command
{
    protected $signature = 'test:prepare-db {--force : Drop and recreate if it already exists}';

    protected $description = 'Create or rebuild the test database from database/schema/mysql-schema.sql';

    public function handle(): int
    {
        $database = 'taga_test';

        // Guard rail: this command creates and drops databases, so it is pinned
        // to the test name rather than reading whatever DB_DATABASE happens to
        // be set to when it runs.
        if (config('database.connections.mysql.database') === $database && ! app()->environment('testing')) {
            $this->warn("Note: your current DB_DATABASE is already {$database}.");
        }

        $schemaPath = database_path('schema/mysql-schema.sql');

        if (! file_exists($schemaPath)) {
            $this->error("Schema dump not found at {$schemaPath}.");

            return self::FAILURE;
        }

        $config = config('database.connections.mysql');

        try {
            $pdo = new PDO(
                "mysql:host={$config['host']};port={$config['port']}",
                $config['username'],
                $config['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            $this->error('Could not connect to MySQL: '.$e->getMessage());

            return self::FAILURE;
        }

        $exists = in_array(
            $database,
            $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN),
            true
        );

        if ($exists && ! $this->option('force')) {
            $this->info("{$database} already exists. Pass --force to rebuild it.");

            return self::SUCCESS;
        }

        if ($exists) {
            $pdo->exec("DROP DATABASE `{$database}`");
            $this->line("Dropped {$database}.");
        }

        $pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$database}`");
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');

        $statements = preg_split("/;\s*\n/", file_get_contents($schemaPath));
        $executed = 0;

        foreach ($statements as $statement) {
            $statement = trim($statement);

            if ($statement === '' || str_starts_with($statement, '--') || str_starts_with($statement, '/*')) {
                continue;
            }

            $pdo->exec($statement);
            $executed++;
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);

        $this->info("Built {$database}: {$executed} statements, ".count($tables).' tables.');

        return self::SUCCESS;
    }
}
