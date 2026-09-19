<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Console\Commands;

use AlexKassel\ManifestEngine\Manifest;
use AlexKassel\ManifestEngine\ManifestRegistry;
use Illuminate\Console\Command;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class ManifestInitCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'manifest:init
                            {name? : Manifest alias name or file path to initialize}
                            {--force : Overwrite existing file if present}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize and scaffold a manifest file with its schema defaults';

    public function __construct(
        protected readonly ManifestRegistry $registry,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $targetArgument = $this->argument('name');
        $registeredNames = $this->registry->names();

        if (! is_string($targetArgument) || trim($targetArgument) === '') {
            if ($this->input->isInteractive() && function_exists('\Laravel\Prompts\select')) {
                if (! empty($registeredNames)) {
                    $choice = select(
                        label: 'Select a registered manifest or enter a custom path:',
                        options: array_merge($registeredNames, ['custom' => 'Custom file path...']),
                    );

                    $target = $choice === 'custom'
                        ? (string) text(label: 'Enter relative file path (e.g. workspace.json):', required: true)
                        : (string) $choice;
                } else {
                    $target = (string) text(label: 'Enter the manifest alias name or file path to initialize:', required: true);
                }
            } else {
                $this->error('Please specify a manifest alias name or file path to initialize.');
                if (! empty($registeredNames)) {
                    $this->line('<comment>Registered manifests:</comment> '.implode(', ', $registeredNames));
                    $this->line('<comment>Usage:</comment> php artisan manifest:init <name>');
                }

                return self::FAILURE;
            }
        } else {
            $target = trim($targetArgument);
        }

        if ($target === '') {
            $this->error('Please specify a manifest alias name or file path to initialize.');

            return self::FAILURE;
        }

        if (str_contains($target, '..')) {
            $this->error('Path traversal is not allowed in manifest path.');

            return self::FAILURE;
        }

        if ($this->registry->has($target)) {
            $definition = $this->registry->get($target);
            $manifest = new Manifest($definition->path, $definition->schema);
        } else {
            $path = str_ends_with($target, '.json') ? $target : "{$target}.json";
            $absolutePath = str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:[\\\\\/]/', $path)
                ? $path
                : (function_exists('base_path') ? base_path($path) : $path);

            $manifest = new Manifest($absolutePath);
        }

        if ($manifest->exists()) {
            if (! $this->option('force')) {
                $this->warn("Manifest file already exists at [{$manifest->path}]. Use --force to overwrite.");

                return self::FAILURE;
            }

            $initialData = $manifest->schema?->defaults() ?? [];
            $manifest->save($initialData);
        } else {
            $manifest->init();
        }

        $this->info("Manifest initialized successfully at [{$manifest->path}].");

        return self::SUCCESS;
    }
}
