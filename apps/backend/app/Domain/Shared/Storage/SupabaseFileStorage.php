<?php

namespace App\Domain\Shared\Storage;

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

/**
 * Supabase Storage (REST API) driver. Uses the service-role key, which must only exist in
 * the server environment. Buckets are created private by `php artisan platform:storage-setup`.
 *
 * @see https://supabase.com/docs/reference/api/storage
 */
class SupabaseFileStorage implements FileStorage
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly string $serviceKey,
        private readonly int $timeout = 20,
    ) {
        if ($this->baseUrl === '' || $this->serviceKey === '') {
            throw new RuntimeException('Supabase storage is selected but SUPABASE_URL / SUPABASE_SERVICE_ROLE_KEY are not configured.');
        }
    }

    public function put(string $bucket, string $path, string $contents, string $contentType): void
    {
        $this->client()
            ->withHeaders(['x-upsert' => 'true'])
            ->withBody($contents, $contentType)
            ->post($this->objectUrl($bucket, $path))
            ->throw();
    }

    public function get(string $bucket, string $path): string
    {
        return $this->client()
            ->get("{$this->baseUrl}/storage/v1/object/authenticated/{$bucket}/{$this->encodePath($path)}")
            ->throw()
            ->body();
    }

    public function exists(string $bucket, string $path): bool
    {
        return $this->client()
            ->head("{$this->baseUrl}/storage/v1/object/authenticated/{$bucket}/{$this->encodePath($path)}")
            ->successful();
    }

    public function delete(string $bucket, string $path): void
    {
        $this->client()->delete($this->objectUrl($bucket, $path))->throw();
    }

    public function temporaryUrl(string $bucket, string $path, int $ttlSeconds, ?string $downloadName = null): string
    {
        $signed = $this->client()
            ->post("{$this->baseUrl}/storage/v1/object/sign/{$bucket}/{$this->encodePath($path)}", ['expiresIn' => $ttlSeconds])
            ->throw()
            ->json('signedURL');

        if (! is_string($signed) || $signed === '') {
            throw new RuntimeException('Supabase did not return a signed URL');
        }

        $url = $this->baseUrl.'/storage/v1'.(str_starts_with($signed, '/') ? $signed : '/'.$signed);

        return $downloadName ? $url.'&download='.rawurlencode($downloadName) : $url;
    }

    public function ping(): bool
    {
        return $this->client()->get("{$this->baseUrl}/storage/v1/bucket")->successful();
    }

    /** @param array{public?: bool, file_size_limit?: int, allowed_mime_types?: list<string>} $options */
    public function ensureBucket(string $bucket, array $options = []): void
    {
        $exists = $this->client()->get("{$this->baseUrl}/storage/v1/bucket/{$bucket}");
        if ($exists->successful()) {
            return;
        }

        $this->client()->post("{$this->baseUrl}/storage/v1/bucket", array_merge([
            'id' => $bucket,
            'name' => $bucket,
            'public' => false,
        ], $options))->throw();
    }

    private function client(): PendingRequest
    {
        return $this->http
            ->withToken($this->serviceKey)
            ->withHeaders(['apikey' => $this->serviceKey])
            ->timeout($this->timeout)
            ->acceptJson();
    }

    private function objectUrl(string $bucket, string $path): string
    {
        return "{$this->baseUrl}/storage/v1/object/{$bucket}/{$this->encodePath($path)}";
    }

    private function encodePath(string $path): string
    {
        if (str_contains($path, '..')) {
            throw new RuntimeException('Invalid storage path');
        }

        return implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    }
}
