<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Console\Commands;

use AlexKassel\ManifestEngine\ManifestManager;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class ManifestSchemaCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'manifest:schema
                            {name? : The manifest alias name}
                            {--output= : Path to save the compiled JSON Schema}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate and display or export the JSON Schema for a registered manifest';

    public function __construct(
        protected readonly ManifestManager $manager,
        protected readonly Filesystem $files,
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
     * Resolve target manifest alias with interactive choice fallback.
     */
    protected function resolveManifestName(string $prompt = 'Select a manifest:'): ?string
    {
        $nameArgument = $this->argument('name');

        if (is_string($nameArgument) && trim($nameArgument) !== '') {
            return trim($nameArgument);
        }

        $registered = $this->registeredNames();

        if (empty($registered)) {
            $this->comment('No manifest definitions are registered in this application.');

            return null;
        }

        if (! $this->input->isInteractive()) {
            $this->error('Please specify a manifest name. Available manifests: '.implode(', ', $registered));

            return null;
        }

        /** @var string */
        return $this->choice($prompt, $registered);
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $name = $this->resolveManifestName('Select a manifest to generate JSON Schema for:');

        if ($name === null) {
            return empty($this->registeredNames()) ? self::SUCCESS : self::FAILURE;
        }

        $def = $this->manager->registry->get($name);

        if ($def === null) {
            $this->error("No manifest registered with alias [{$name}].");
            $registered = $this->registeredNames();
            if (! empty($registered)) {
                $this->line('<comment>Available manifests:</comment> '.implode(', ', $registered));
            }

            return self::FAILURE;
        }

        $schemaInstance = $def->resolveSchema();
        if ($schemaInstance === null) {
            $this->warn('The selected manifest does not define a schema.');

            return self::FAILURE;
        }

        $compiled = $schemaInstance->jsonSchema();
        if ($compiled === null) {
            $this->warn('The selected manifest does not define a schema.');

            return self::FAILURE;
        }

        $encoded = (string) json_encode($compiled, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $outputPath = $this->option('output');
        if (is_string($outputPath) && trim($outputPath) !== '') {
            $this->files->ensureDirectoryExists(dirname($outputPath));
            $this->files->put($outputPath, $encoded."\n");
            $this->info("JSON Schema written to [{$outputPath}].");

            return self::SUCCESS;
        }

        $this->line($encoded);

        return self::SUCCESS;
    }
}
