<?php

namespace Admnio\Sunset\Console;

use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * @internal This class is part of Sunset's internal implementation; signatures
 *           may change between minor releases of v1.x. Consumers should depend
 *           on the published Admnio\Sunset\Contracts\* interfaces instead.
 */
#[AsCommand(name: 'sunset:migrate-horizon-config')]
class SunsetMigrateHorizonConfigCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sunset:migrate-horizon-config
                            {--dry-run : Print the new config to stdout without writing}
                            {--force : Overwrite existing supervisors key if present}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate supervisor configuration from horizon.php into sunset.php';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $horizonPath = config_path('horizon.php');
        $sunsetPath = config_path('sunset.php');

        if (! file_exists($horizonPath)) {
            $this->components->error('horizon.php config file not found at: '.$horizonPath);

            return 1;
        }

        if (! file_exists($sunsetPath)) {
            $this->components->error('sunset.php config file not found at: '.$sunsetPath);

            return 1;
        }

        // Load horizon config to extract supervisor settings.
        $horizonConfig = require $horizonPath;

        // Load current sunset.php as a string for manipulation.
        $sunsetContents = file_get_contents($sunsetPath);

        // Check if supervisors key already exists in sunset.php.
        if (str_contains($sunsetContents, "'supervisors'") && ! $this->option('force')) {
            $this->components->error(
                "sunset.php already contains a 'supervisors' key. Use --force to overwrite."
            );

            return 1;
        }

        // Build the supervisors array from horizon environments.
        $environments = $horizonConfig['environments'] ?? [];
        $memoryLimit = $horizonConfig['memory_limit'] ?? 64;
        $fastTermination = $horizonConfig['fast_termination'] ?? false;
        $wait = $horizonConfig['waits'] ?? $horizonConfig['wait'] ?? [];

        $supervisorsPhp = $this->buildSupervisorsPhp($environments);
        $memoryLimitPhp = $this->exportValue($memoryLimit);
        $fastTerminationPhp = $this->exportValue($fastTermination);
        $waitPhp = $this->buildArrayPhp($wait, 2);

        $insertion = <<<PHP

    'supervisors' => {$supervisorsPhp},

    'memory_limit' => {$memoryLimitPhp},

    'fast_termination' => {$fastTerminationPhp},

    'wait' => {$waitPhp},

PHP;

        // Remove existing supervisors/memory_limit/fast_termination/wait keys if --force.
        if ($this->option('force')) {
            $sunsetContents = $this->removeExistingKeys($sunsetContents);
        }

        // Find the line containing 'transports' => and insert before it.
        if (! preg_match("/^(\s*'transports'\s*=>)/m", $sunsetContents)) {
            $this->components->error("Could not find 'transports' key in sunset.php.");

            return 1;
        }

        $newContents = preg_replace(
            "/^(\s*'transports'\s*=>)/m",
            $insertion.'    $1',
            $sunsetContents,
            1
        );

        if ($this->option('dry-run')) {
            $this->line($newContents);

            return 0;
        }

        file_put_contents($sunsetPath, $newContents);

        $this->components->info('Horizon supervisor configuration migrated into sunset.php successfully.');

        return 0;
    }

    /**
     * Build PHP array representation of supervisors from horizon environments.
     *
     * @param  array  $environments
     * @return string
     */
    protected function buildSupervisorsPhp(array $environments): string
    {
        if (empty($environments)) {
            return '[]';
        }

        $lines = ['['];

        foreach ($environments as $env => $supervisorGroups) {
            $lines[] = "        '{$env}' => [";

            foreach ($supervisorGroups as $name => $config) {
                $lines[] = "            '{$name}' => [";

                foreach ($config as $key => $value) {
                    $exportedValue = $this->exportValue($value);
                    $lines[] = "                '{$key}' => {$exportedValue},";
                }

                $lines[] = '            ],';
            }

            $lines[] = '        ],';
        }

        $lines[] = '    ]';

        return implode("\n", $lines);
    }

    /**
     * Build a PHP array literal from an associative array.
     *
     * @param  array  $array
     * @param  int  $indent  Indentation level (in units of 4 spaces)
     * @return string
     */
    protected function buildArrayPhp(array $array, int $indent = 0): string
    {
        if (empty($array)) {
            return '[]';
        }

        $pad = str_repeat('    ', $indent);
        $innerPad = str_repeat('    ', $indent + 1);

        $lines = ['['];

        foreach ($array as $key => $value) {
            $exportedKey = is_int($key) ? $key : "'{$key}'";
            $exportedValue = $this->exportValue($value);
            $lines[] = "{$innerPad}{$exportedKey} => {$exportedValue},";
        }

        $lines[] = "{$pad}]";

        return implode("\n", $lines);
    }

    /**
     * Export a scalar or array value as PHP code.
     *
     * @param  mixed  $value
     * @return string
     */
    protected function exportValue($value): string
    {
        if (is_array($value)) {
            return var_export($value, true);
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_null($value)) {
            return 'null';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'".addslashes((string) $value)."'";
    }

    /**
     * Remove existing migrated keys from sunset.php contents.
     *
     * @param  string  $contents
     * @return string
     */
    protected function removeExistingKeys(string $contents): string
    {
        $keysToRemove = ['supervisors', 'memory_limit', 'fast_termination', 'wait'];

        foreach ($keysToRemove as $key) {
            // Remove simple scalar keys: 'key' => value,
            $contents = preg_replace(
                "/^\s*'{$key}'\s*=>\s*[^[].+,\s*\n/m",
                '',
                $contents
            );

            // Remove array keys: 'key' => [ ... ],  (multi-line, non-greedy)
            $contents = preg_replace(
                "/^\s*'{$key}'\s*=>\s*\[.*?\],\s*\n/ms",
                '',
                $contents
            );
        }

        return $contents;
    }
}
