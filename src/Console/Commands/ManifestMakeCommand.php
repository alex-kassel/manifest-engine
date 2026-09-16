<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Console\Commands;

use AlexKassel\ManifestEngine\ManifestManager;
use Illuminate\Console\Command;

class ManifestMakeCommand extends Command
{
    public const DEFAULT_EXT = '.json';

    public const DEFAULT_EMPTY_DATA = [];

    public const DEFAULT_BASE_DIR = '.';

    public const CUSTOM_PATH_CHOICE = '[Custom file path...]';

    public const MISSING_NAME_MESSAGE = 'Please specify a manifest alias name or file path to initialize.';

    public const PATH_TRAVERSAL_ERROR = 'Path traversal is not allowed in manifest path.';

    public const PROMPT_TARGET_LABEL = 'Enter the manifest alias name or file path to initialize:';

    public const PROMPT_CUSTOM_PATH_LABEL = 'Enter relative file path (e.g. workspace.json):';

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
     * Execute the console command.
     */
    public function handle(): int
    {
        $registry = $this->manager->registry();
        $targetArgument = $this->argument('name');

        if (! is_string($targetArgument) || trim($targetArgument) === '') {
            $registered = array_keys($registry->all());

            if ($this->input->isInteractive()) {
                if (! empty($registered)) {
                    $options = array_merge($registered, [self::CUSTOM_PATH_CHOICE]);
                    /** @var string $choice */
                    $choice = $this->choice('Select a registered manifest or enter a custom path:', $options);

                    if ($choice === self::CUSTOM_PATH_CHOICE) {
                        $target = (string) $this->ask(self::PROMPT_CUSTOM_PATH_LABEL);
                    } else {
                        $target = $choice;
                    }
                } else {
                    $target = (string) $this->ask(self::PROMPT_TARGET_LABEL);
                }
            } else {
                $this->error(self::MISSING_NAME_MESSAGE);
                if (! empty($registered)) {
                    $this->line('<comment>Available registered manifests:</comment> '.implode(', ', $registered));
                }

                return self::FAILURE;
            }
        } else {
            $target = trim($targetArgument);
        }

        if (trim($target) === '') {
            $this->error(self::MISSING_NAME_MESSAGE);

            return self::FAILURE;
        }

        if (str_contains($target, '..')) {
            $this->error(self::PATH_TRAVERSAL_ERROR);

            return self::FAILURE;
        }

        $def = $registry->get($target);

        if ($def !== null) {
            $manifest = $this->manager->get($target);
        } else {
            $path = str_ends_with($target, self::DEFAULT_EXT) ? $target : $target.self::DEFAULT_EXT;
            $root = function_exists('base_path') ? base_path() : (string) (getcwd() ?: self::DEFAULT_BASE_DIR);
            $fullPath = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$path;
            $manifest = $this->manager->open($fullPath);
        }

        if ($manifest->exists()) {
            if (! $this->option('force')) {
                $this->warn("Manifest file already exists at [{$manifest->path}]. Use --force to overwrite.");

                return self::FAILURE;
            }

            $initialData = $manifest->schema !== null ? $manifest->schema->defaults() : self::DEFAULT_EMPTY_DATA;
            $manifest->save($initialData);
        } else {
            $manifest->init();
        }

        $this->info("Manifest initialized successfully at [{$manifest->path}].");

        return self::SUCCESS;
    }
}
