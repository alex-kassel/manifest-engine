<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Console\Commands;

use AlexKassel\ManifestEngine\ManifestManager;
use Illuminate\Console\Command;

class ManifestMakeCommand extends Command
{
    public const DEFAULT_EXT = '.json';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'manifest:make
                            {name : Manifest alias name or file path to initialize}
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
     * Execute the console command.
     */
    public function handle(): int
    {
        $target = (string) $this->argument('name');
        $registry = $this->manager->registry();
        $def = $registry->get($target);

        if ($def !== null) {
            $manifest = $this->manager->get($target);
        } else {
            $path = str_ends_with($target, self::DEFAULT_EXT) ? $target : $target.self::DEFAULT_EXT;
            $root = function_exists('base_path') ? base_path() : (string) getcwd();
            $fullPath = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
            $manifest = $this->manager->open($fullPath);
        }

        if ($manifest->exists() && ! $this->option('force')) {
            $this->warn("Manifest file already exists at [{$manifest->path}]. Use --force to overwrite.");

            return self::FAILURE;
        }

        $manifest->init();
        if ($this->option('force') && $manifest->exists()) {
            $initialData = $manifest->schema !== null ? $manifest->schema->defaults() : [];
            $manifest->save($initialData);
        }

        $this->info("Manifest initialized successfully at [{$manifest->path}].");

        return self::SUCCESS;
    }
}
