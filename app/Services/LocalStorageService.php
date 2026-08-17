<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Exception;

/**
 * Drop-in replacement for PinataService — same method names and return
 * shape ('url'/'ipfs_hash' for images, 'metadata_uri'/'ipfs_hash' for
 * metadata) so NftController and IpfsController didn't need to change
 * their calling code, only which service they inject.
 *
 * Files are content-addressed (filename = sha256 hash of the file's own
 * bytes) — the same property that made IPFS useful for this: if the
 * bytes change, the hash/filename changes too, so a swapped-out image
 * can't silently keep the same URL. It's not fully decentralized the
 * way IPFS is (this is still one server), but it keeps that specific
 * tamper-evidence property.
 *
 * Requires the 'public' disk's symlink to exist:
 *   php artisan storage:link
 */
class LocalStorageService
{
    private const IMAGE_DIR    = 'nft-images';
    private const METADATA_DIR = 'nft-metadata';

    /**
     * Image → local disk (storage/app/public/nft-images/{hash}.{ext})
     */
    public function uploadImage(UploadedFile $file, string $name): array
    {
        $bytes = file_get_contents($file->getRealPath());
        if ($bytes === false) {
            throw new Exception('Could not read uploaded image file.');
        }

        $hash      = hash('sha256', $bytes);
        $extension = strtolower($file->getClientOriginalExtension()) ?: $file->extension() ?: 'bin';
        $filename  = "{$hash}.{$extension}";
        $path      = self::IMAGE_DIR . '/' . $filename;

        // Content-addressed: if this exact file was uploaded before, the
        // hash (and therefore filename) is identical — skip rewriting it.
        if (!Storage::disk('public')->exists($path)) {
            Storage::disk('public')->put($path, $bytes);
        }

        $url = rtrim(config('app.url'), '/') . '/storage/' . $path;

        return [
            'ipfs_hash'  => $hash,   // kept as 'ipfs_hash' for interface compatibility with callers
            'url'        => $url,
            'gateway'    => $url,
            'size_bytes' => strlen($bytes),
        ];
    }

    /**
     * NFT metadata JSON → local disk (storage/app/public/nft-metadata/{hash}.json)
     */
    public function uploadMetadata(array $metadata): array
    {
        $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new Exception('Could not encode NFT metadata as JSON.');
        }

        $hash     = hash('sha256', $json);
        $filename = "{$hash}.json";
        $path     = self::METADATA_DIR . '/' . $filename;

        if (!Storage::disk('public')->exists($path)) {
            Storage::disk('public')->put($path, $json);
        }

        $url = rtrim(config('app.url'), '/') . '/storage/' . $path;

        return [
            'ipfs_hash'    => $hash,
            'metadata_uri' => $url,
        ];
    }

    /**
     * Remove a previously stored file by its content hash. Searches
     * both directories since the caller (IpfsController::unpin) only
     * has a hash, not which type of file it was.
     */
    public function unpin(string $hash): bool
    {
        $disk    = Storage::disk('public');
        $removed = false;

        foreach ([self::IMAGE_DIR, self::METADATA_DIR] as $dir) {
            foreach ($disk->files($dir) as $file) {
                if (str_starts_with(basename($file), $hash)) {
                    $disk->delete($file);
                    $removed = true;
                }
            }
        }

        return $removed;
    }
}
