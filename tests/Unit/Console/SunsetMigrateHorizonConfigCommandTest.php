<?php

namespace Admnio\Sunset\Tests\Unit\Console;

use Admnio\Sunset\Console\SunsetMigrateHorizonConfigCommand;
use Admnio\Sunset\Tests\TestCase;
use Illuminate\Console\Application as Artisan;

class SunsetMigrateHorizonConfigCommandTest extends TestCase
{
    /**
     * Temp directory for this test run.
     */
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/sunset-config-test-'.uniqid();
        mkdir($this->tmpDir, 0777, true);

        Artisan::starting(function ($artisan) {
            $artisan->resolveCommands([SunsetMigrateHorizonConfigCommand::class]);
        });
    }

    protected function tearDown(): void
    {
        // Clean up temp directory.
        $this->removeTmpDir($this->tmpDir);

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function removeTmpDir(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path.DIRECTORY_SEPARATOR.$item;
            is_dir($full) ? $this->removeTmpDir($full) : unlink($full);
        }
        rmdir($path);
    }

    /**
     * Write a minimal horizon.php to the temp dir.
     */
    private function writeHorizonConfig(array $config = []): string
    {
        $defaults = [
            'environments' => [
                'production' => [
                    'supervisor-1' => [
                        'connection' => 'redis',
                        'queue'      => ['default'],
                        'balance'    => 'auto',
                        'processes'  => 8,
                        'tries'      => 3,
                    ],
                ],
            ],
            'memory_limit'     => 64,
            'fast_termination' => false,
            'waits'            => ['redis:default' => 60],
        ];

        $merged = array_merge($defaults, $config);
        $path = $this->tmpDir.'/horizon.php';
        $export = var_export($merged, true);
        file_put_contents($path, "<?php\nreturn {$export};\n");

        return $path;
    }

    /**
     * Write a minimal sunset.php to the temp dir.
     */
    private function writeSunsetConfig(string $extra = ''): string
    {
        $path = $this->tmpDir.'/sunset.php';
        $content = <<<'PHP'
<?php

return [
    'redis_connection' => env('SUNSET_REDIS', 'default'),

    'workload_cache_ttl' => 5,

    'key_prefix' => env('SUNSET_KEY_PREFIX', 'sunset'),
PHP;

        if ($extra) {
            $content .= "\n".$extra;
        }

        $content .= <<<'PHP'

    'transports' => [
        'sqs' => [],
    ],
];
PHP;

        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Override config_path() in the application to point to our temp dir.
     */
    private function setConfigPath(): void
    {
        $this->app->useConfigPath($this->tmpDir);
    }

    // -------------------------------------------------------------------------
    // Tests
    // -------------------------------------------------------------------------

    public function test_command_has_correct_signature(): void
    {
        $command = new SunsetMigrateHorizonConfigCommand();

        $this->assertSame('sunset:migrate-horizon-config', $command->getName());
    }

    public function test_command_has_dry_run_and_force_options(): void
    {
        $command = new SunsetMigrateHorizonConfigCommand();

        $this->assertTrue($command->getDefinition()->hasOption('dry-run'));
        $this->assertTrue($command->getDefinition()->hasOption('force'));
    }

    public function test_errors_when_horizon_config_missing(): void
    {
        $this->writeSunsetConfig();
        $this->setConfigPath();

        // No horizon.php written.
        $this->artisan('sunset:migrate-horizon-config')->assertExitCode(1);
    }

    public function test_errors_when_sunset_config_missing(): void
    {
        $this->writeHorizonConfig();
        $this->setConfigPath();

        // No sunset.php written.
        $this->artisan('sunset:migrate-horizon-config')->assertExitCode(1);
    }

    public function test_errors_when_supervisors_key_exists_without_force(): void
    {
        $this->writeHorizonConfig();
        $this->writeSunsetConfig("\n    'supervisors' => [],\n");
        $this->setConfigPath();

        $this->artisan('sunset:migrate-horizon-config')->assertExitCode(1);
    }

    public function test_dry_run_prints_output_without_writing(): void
    {
        $this->writeHorizonConfig();
        $sunsetPath = $this->writeSunsetConfig();
        $before = file_get_contents($sunsetPath);
        $this->setConfigPath();

        $this->artisan('sunset:migrate-horizon-config', ['--dry-run' => true])->assertExitCode(0);

        // File must not have changed.
        $this->assertSame($before, file_get_contents($sunsetPath));
    }

    public function test_migration_inserts_supervisors_before_transports(): void
    {
        $this->writeHorizonConfig();
        $sunsetPath = $this->writeSunsetConfig();
        $this->setConfigPath();

        $this->artisan('sunset:migrate-horizon-config')->assertExitCode(0);

        $result = file_get_contents($sunsetPath);

        $this->assertStringContainsString("'supervisors'", $result);
        $this->assertStringContainsString("'memory_limit'", $result);
        $this->assertStringContainsString("'fast_termination'", $result);
        $this->assertStringContainsString("'wait'", $result);

        // Supervisors must appear before transports.
        $supervisorsPos = strpos($result, "'supervisors'");
        $transportsPos = strpos($result, "'transports'");
        $this->assertLessThan($transportsPos, $supervisorsPos);
    }

    public function test_migration_preserves_transports_key(): void
    {
        $this->writeHorizonConfig();
        $sunsetPath = $this->writeSunsetConfig();
        $this->setConfigPath();

        $this->artisan('sunset:migrate-horizon-config')->assertExitCode(0);

        $result = file_get_contents($sunsetPath);

        $this->assertStringContainsString("'transports'", $result);
        $this->assertStringContainsString("'sqs'", $result);
    }

    public function test_force_overwrites_existing_supervisors_key(): void
    {
        $this->writeHorizonConfig([
            'environments' => [
                'production' => [
                    'supervisor-1' => ['processes' => 10],
                ],
            ],
        ]);
        $this->writeSunsetConfig("\n    'supervisors' => [],\n");
        $this->setConfigPath();

        $this->artisan('sunset:migrate-horizon-config', ['--force' => true])->assertExitCode(0);

        $result = file_get_contents($this->tmpDir.'/sunset.php');

        // Should now contain the new supervisors from horizon.php.
        $this->assertStringContainsString("'production'", $result);
    }
}
