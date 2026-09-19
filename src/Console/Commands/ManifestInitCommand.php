<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Console\Commands;

use AlexKassel\ConsoleUx\Concerns\InteractsWithConsoleUx;
use AlexKassel\ManifestEngine\Manifest;
use AlexKassel\ManifestEngine\ManifestRegistry;
use Illuminate\Console\Command;

class ManifestInitCommand extends Command
{
    use InteractsWithConsoleUx;

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
            if ($this->isInteractiveEnvironment()) {
                if (! empty($registeredNames)) {
                    $choice = $this->promptSelect(
                        label: 'Select a registered manifest or enter a custom path:',
                        options: array_merge($registeredNames, ['custom' => 'Custom file path...']),
                    );

                    $target = $choice === 'custom'
                        ? $this->promptText(label: 'Enter relative file path (e.g. workspace.json):', required: true)
                        : $choice;
                } else {
                    $target = $this->promptText(label: 'Enter the manifest alias name or file path to initialize:', required: true);
                }
            } else {
                return $this->failWithGuidance(
                    guidance: 'Please specify a manifest alias name or file path to initialize.',
                    argument: 'name',
                    availableOptions: $registeredNames,
                    usageExample: 'php artisan manifest:init <name> [--force]',
                    agentInstructions: 'Check registered manifests via manifest:status before calling manifest:init.',
                );
            }
        } else {
            $target = trim($targetArgument);
        }

        if ($target === '') {
            return $this->failWithGuidance(
                guidance: 'Please specify a manifest alias name or file path to initialize.',
                argument: 'name',
                availableOptions: $registeredNames,
                usageExample: 'php artisan manifest:init <name> [--force]',
            );
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
