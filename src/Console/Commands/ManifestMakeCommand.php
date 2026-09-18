<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Console\Commands;

use AlexKassel\ManifestEngine\Manifest;
use AlexKassel\ManifestEngine\ManifestManager;
use Illuminate\Console\Command;

class ManifestMakeCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'manifest:make
                            {name? : Manifest alias name or file path to initialize}
                            {--force : Overwrite existing file if present}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize and scaffold a manifest file with its schema defaults';

    public function __construct(
        protected readonly ManifestManager $manager,
    ) {
        parent::__construct();
    }

    /**
     * @return array<int, string>
     */
    protected function registeredNames(): array
    {
        return $this->manager->registry->names();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $registry = $this->manager->registry;
        $targetArgument = $this->argument('name');

        if (! is_string($targetArgument) || trim($targetArgument) === '') {
            $registered = $this->registeredNames();

            if ($this->input->isInteractive()) {
                if (! empty($registered)) {
                    $choice = $this->choice('Select a registered manifest or enter a custom path:', array_merge($registered, ['[Custom file path...]']));

                    if ($choice === '[Custom file path...]') {
                        $target = (string) $this->ask('Enter relative file path (e.g. workspace.json):');
                    } else {
                        $target = (string) $choice;
                    }
                } else {
                    $target = (string) $this->ask('Enter the manifest alias name or file path to initialize:');
                }
            } else {
                $this->error('Please specify a manifest alias name or file path to initialize.');
                if (! empty($registered)) {
                    $this->line('<comment>Available registered manifests:</comment> '.implode(', ', $registered));
                }

                return self::FAILURE;
            }
        } else {
            $target = trim($targetArgument);
        }

        if (trim($target) === '') {
            $this->error('Please specify a manifest alias name or file path to initialize.');

            return self::FAILURE;
        }

        if (str_contains($target, '..')) {
            $this->error('Path traversal is not allowed in manifest path.');

            return self::FAILURE;
        }

        $manifest = $registry->has($target)
            ? $this->manager->open($target)
            : new Manifest(str_ends_with($target, '.json') ? $target : $target.'.json');

        if ($manifest->exists()) {
            if (! $this->option('force')) {
                $this->warn("Manifest file already exists at [{$manifest->path}]. Use --force to overwrite.");

                return self::FAILURE;
            }

            $initialData = $manifest->schema !== null ? $manifest->schema->defaults() : [];
            $manifest->save($initialData);
        } else {
            $manifest->init();
        }

        $this->info("Manifest initialized successfully at [{$manifest->path}].");

        return self::SUCCESS;
    }
}
