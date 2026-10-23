<?php

namespace App\Console\Commands;

use App\Domain\Shared\Storage\FileStorage;
use App\Domain\Shared\Storage\SupabaseFileStorage;
use Illuminate\Console\Command;

class SetupStorage extends Command
{
    protected $signature = 'platform:storage-setup';

    protected $description = 'Create the private Supabase Storage buckets used by the platform';

    public function handle(FileStorage $storage): int
    {
        if (! $storage instanceof SupabaseFileStorage) {
            $this->info('FILE_STORAGE_DRIVER is not "supabase"; local storage needs no setup.');

            return self::SUCCESS;
        }

        $buckets = config('filestorage.buckets');
        $storage->ensureBucket($buckets['documents'], ['file_size_limit' => 10 * 1024 * 1024, 'allowed_mime_types' => ['application/pdf']]);
        $storage->ensureBucket($buckets['branding'], ['file_size_limit' => 1024 * 1024, 'allowed_mime_types' => ['image/png', 'image/jpeg', 'image/webp']]);
        $storage->ensureBucket($buckets['payment_proofs'], ['file_size_limit' => 5 * 1024 * 1024, 'allowed_mime_types' => ['application/pdf', 'image/png', 'image/jpeg']]);

        $this->info('Private buckets ready: '.implode(', ', $buckets));

        return self::SUCCESS;
    }
}
