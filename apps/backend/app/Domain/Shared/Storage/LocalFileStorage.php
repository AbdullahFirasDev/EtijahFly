<?php

namespace App\Domain\Shared\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use RuntimeException;

/**
 * Development/test driver backed by the private `local` disk (storage/app/private).
 * Temporary URLs use Laravel's signed local file serving.
 */
class LocalFileStorage implements FileStorage
{
    public function __construct(private readonly Filesystem $disk) {}

    public function put(string $bucket, string $path, string $contents, string $contentType): void
    {
        if (! $this->disk->put($this->key($bucket, $path), $contents)) {
            throw new RuntimeException("Unable to write [{$bucket}/{$path}]");
        }
    }

    public function get(string $bucket, string $path): string
    {
        $contents = $this->disk->get($this->key($bucket, $path));
        if ($contents === null) {
            throw new RuntimeException("File [{$bucket}/{$path}] not found");
        }

        return $contents;
    }

    public function exists(string $bucket, string $path): bool
    {
        return $this->disk->exists($this->key($bucket, $path));
    }

    public function delete(string $bucket, string $path): void
    {
        $this->disk->delete($this->key($bucket, $path));
    }

    public function temporaryUrl(string $bucket, string $path, int $ttlSeconds, ?string $downloadName = null): string
    {
        /** @var FilesystemAdapter $disk */
        $disk = $this->disk;

        return $disk->temporaryUrl($this->key($bucket, $path), now()->addSeconds($ttlSeconds));
    }

    public function ping(): bool
    {
        return $this->disk->put('.healthcheck', (string) time());
    }

    private function key(string $bucket, string $path): string
    {
        if (str_contains($path, '..') || str_contains($bucket, '..')) {
            throw new RuntimeException('Invalid storage path');
        }

        return trim($bucket, '/').'/'.ltrim($path, '/');
    }
}
