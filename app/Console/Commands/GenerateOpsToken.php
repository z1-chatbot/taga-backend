<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Generates the token for the /__ops maintenance endpoint and writes it to .env.
 *
 * Kept out of source control by design — .env is not committed, and the
 * endpoint stays disabled until a token of at least 32 characters is present.
 */
class GenerateOpsToken extends Command
{
    protected $signature = 'ops:token {--show : Print the current token instead of generating a new one}';

    protected $description = 'Generate the MAINTENANCE_TOKEN used by the /__ops deployment endpoint';

    public function handle(): int
    {
        $envPath = base_path('.env');

        if (! file_exists($envPath)) {
            $this->error('No .env file found.');

            return self::FAILURE;
        }

        if ($this->option('show')) {
            $current = config('app.maintenance_token');

            if (! $current) {
                $this->warn('No MAINTENANCE_TOKEN set — the /__ops endpoint is disabled.');

                return self::SUCCESS;
            }

            $this->line($current);

            return self::SUCCESS;
        }

        $token = Str::random(64);
        $contents = file_get_contents($envPath);

        if (preg_match('/^MAINTENANCE_TOKEN=.*$/m', $contents)) {
            $contents = preg_replace('/^MAINTENANCE_TOKEN=.*$/m', "MAINTENANCE_TOKEN={$token}", $contents);
            $action = 'Replaced';
        } else {
            $contents = rtrim($contents)."\n\n# Token for the /__ops deployment endpoint. Rotate with `php artisan ops:token`.\nMAINTENANCE_TOKEN={$token}\n";
            $action = 'Added';
        }

        file_put_contents($envPath, $contents);

        $this->info("{$action} MAINTENANCE_TOKEN in .env:");
        $this->newLine();
        $this->line($token);
        $this->newLine();
        $this->line('Use it as the X-Maintenance-Token header, e.g.');
        $this->line('  curl -X POST https://yoursite/__ops/clear -H "X-Maintenance-Token: '.substr($token, 0, 8).'..."');
        $this->newLine();
        $this->warn('Run `php artisan config:clear` if your config is cached.');

        return self::SUCCESS;
    }
}
