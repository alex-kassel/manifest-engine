<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Console\Commands;

use AlexKassel\ManifestEngine\ManifestManager;
use Illuminate\Console\Command;

abstract class BaseManifestCommand extends Command
{
    public function __construct(
        protected readonly ManifestManager $manager,
    ) {
        parent::__construct();
    }

    /**
     * Get list of registered manifest names.
     *
     * @return array<int, string>
     */
    protected function registeredNames(): array
    {
        return array_keys($this->manager->registry()->all());
    }

    /**
     * Resolve optional base path option.
     */
    protected function resolveBasePath(): ?string
    {
        if (! $this->hasOption('base-path')) {
            return null;
        }

        $basePath = $this->option('base-path');

        return is_string($basePath) && trim($basePath) !== '' ? trim($basePath) : null;
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
}
