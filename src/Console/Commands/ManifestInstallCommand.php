<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Console\Commands;

use AlexKassel\ManifestEngine\ManifestRegistry;
use AlexKassel\ManifestEngine\Services\ManifestInstaller;
use Illuminate\Console\Command;

class ManifestInstallCommand extends Command
{
    public const ARG_NAME = 'name';

    public const OPTION_FORCE = 'force';

    public const ICON_CREATED = '<info>✔</info>';

    public const ICON_SKIPPED = '<comment>⏭</comment>';

    public const ICON_DEFAULT = '•';

    public const SINGLE_MANIFEST_COUNT = 1;

    public const FIRST_ITEM_INDEX = 0;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'manifest:install
        {name? : Manifest name to install (e.g. workspace, domains)}
        {--force : Force overwrite existing manifest and runner files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install a registered manifest and publish its standalone runner';

    public function __construct(
        protected readonly ManifestRegistry $registry,
        protected readonly ManifestInstaller $installer,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $manifests = $this->registry->all();

        if (empty($manifests)) {
            $this->error('No manifest definitions are registered in the application.');
            $this->comment('How to fix:');
            $this->line('  • Register a manifest via Manifest::register(\'workspace\', \'workspace.json\', WorkspaceSchema::class)');

            return self::FAILURE;
        }

        $name = $this->argument(self::ARG_NAME);

        if ($name === null || trim((string) $name) === '') {
            $keys = array_keys($manifests);

            if (count($keys) === self::SINGLE_MANIFEST_COUNT) {
                $name = $keys[self::FIRST_ITEM_INDEX];
            } elseif ($this->input->isInteractive()) {
                $name = (string) $this->choice('Select manifest to install:', $keys, $keys[self::FIRST_ITEM_INDEX]);
            } else {
                $this->error('Missing required argument [name]. Available manifests: '.implode(', ', $keys));
                $this->comment('How to fix:');
                $this->line("  • Run with explicit name: php artisan manifest:install {$keys[self::FIRST_ITEM_INDEX]}");

                return self::FAILURE;
            }
        }

        $name = strtolower(trim((string) $name));
        $definition = $this->registry->get($name);

        if ($definition === null) {
            $available = implode(', ', array_keys($manifests));
            $this->error("Manifest [{$name}] is not registered. Available manifests: {$available}.");

            return self::FAILURE;
        }

        $force = (bool) $this->option(self::OPTION_FORCE);
        $rootPath = function_exists('base_path') ? base_path() : (string) getcwd();

        $this->info("Installing manifest [{$name}] ({$definition->filename})...");
        $steps = $this->installer->install($definition, $rootPath, $force);

        foreach ($steps as $step) {
            $icon = match ($step['status']) {
                ManifestInstaller::STATUS_CREATED => self::ICON_CREATED,
                ManifestInstaller::STATUS_SKIPPED => self::ICON_SKIPPED,
                default => self::ICON_DEFAULT,
            };
            $this->line("  {$icon} {$step['message']}");
        }

        $this->newLine();
        $this->info("Manifest [{$name}] installation completed successfully.");

        return self::SUCCESS;
    }
}
