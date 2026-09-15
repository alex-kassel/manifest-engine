<?php

declare(strict_types=1);

namespace AlexKassel\ManifestEngine\Contracts;

interface StorageDriver
{
    /**
     * Determine if a manifest file exists.
     */
    public function exists(string $path): bool;

    /**
     * Read the entire content of a manifest file with a shared lock (LOCK_SH).
     */
    public function readLocked(string $path): string;

    /**
     * Execute an atomic mutation under an exclusive lock (LOCK_EX).
     *
     * @param  callable(string): string  $callback
     */
    public function mutateLocked(string $path, callable $callback): string;

    /**
     * Atomically write contents to a file using temporary file and atomic rename.
     */
    public function writeAtomic(string $path, string $contents): void;

    /**
     * Calculate SHA-1 hash of the file on disk.
     */
    public function hash(string $path): ?string;
}
