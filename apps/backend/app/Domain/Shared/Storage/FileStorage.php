<?php

namespace App\Domain\Shared\Storage;

/**
 * Private object storage. All buckets are private; browsers only ever receive short-lived
 * signed URLs, issued after the API has authorised the request.
 */
interface FileStorage
{
    public function put(string $bucket, string $path, string $contents, string $contentType): void;

    public function get(string $bucket, string $path): string;

    public function exists(string $bucket, string $path): bool;

    public function delete(string $bucket, string $path): void;

    public function temporaryUrl(string $bucket, string $path, int $ttlSeconds, ?string $downloadName = null): string;

    /** Lightweight connectivity check for the health endpoint. */
    public function ping(): bool;
}
