<?php

namespace App\Http\Controllers;

use App\Services\LocalStorageService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class IpfsController extends Controller
{
    public function __construct(private LocalStorageService $storage) {}

    /**
     * Resolve a bare content hash (nft.image_hash — no extension) to the
     * actual stored file and redirect to it.
     *
     * Why this exists: LocalStorageService saves files as
     * "{sha256hash}.{ext}" on disk, but only the bare hash is stored in
     * the nfts table (image_hash). A frontend that only has the hash
     * can't build a working <img src> on its own — it doesn't know the
     * extension. This endpoint does that lookup server-side: glob for
     * "{hash}.*" in the nft-images dir and 302-redirect to whichever
     * file matches, so the browser ends up loading the real URL either
     * way (cached by the browser after the first hit).
     *
     * GET /api/image/{hash}
     */
    public function resolveByHash(string $hash)
    {
        // Guard against path traversal / junk input — a sha256 hex
        // digest is always exactly 64 [0-9a-f] characters.
        if (!preg_match('/^[a-f0-9]{64}$/i', $hash)) {
            abort(404);
        }

        $disk  = Storage::disk('public');
        $files = $disk->files('nft-images');

        foreach ($files as $file) {
            if (str_starts_with(basename($file), $hash . '.')) {
                return redirect(rtrim(config('app.url'), '/') . '/storage/' . $file);
            }
        }

        abort(404, 'Image not found for this hash.');
    }

    /**
     * Image → local disk
     * POST /api/ipfs/upload-image
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|file|image|mimes:jpg,jpeg,png,gif,webp|max:50240|dimensions:max_width=8000,max_height=8000',
            'name'  => 'required|string|max:100',
        ]);

        try {
            $result = $this->storage->uploadImage(
                $request->file('image'),
                $request->name
            );

            return response()->json([
                'success'   => true,
                'message'   => 'Image uploaded',
                'data'      => $result,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * NFT Metadata → local disk
     * POST /api/ipfs/upload-metadata
     */
    public function uploadMetadata(Request $request): JsonResponse
    {
        $request->validate([
            'name'        => 'required|string|max:100',
            'description' => 'required|string|max:1000',
            'image_url'   => 'required|url',
            'attributes'  => 'nullable|array',
            'royalty'     => 'nullable|integer|min:0|max:50',
            'symbol'      => 'nullable|string|max:10',
        ]);

        // Metaplex standard metadata format
        $metadata = [
            'name'        => $request->name,
            'symbol'      => $request->symbol ?? 'NFT',
            'description' => $request->description,
            'image'       => $request->image_url,
            'seller_fee_basis_points' => ($request->royalty ?? 5) * 100, // 5% = 500
            'attributes'  => $request->attributes ?? [],
            'properties'  => [
                'files' => [
                    ['uri' => $request->image_url, 'type' => 'image/png'],
                ],
                'category' => 'image',
            ],
        ];

        try {
            $result = $this->storage->uploadMetadata($metadata);

            return response()->json([
                'success'      => true,
                'message'      => 'Metadata uploaded',
                'data'         => $result,
                'metadata'     => $metadata,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Remove a stored file by its content hash
     * DELETE /api/ipfs/unpin/{hash}
     */
    public function unpin(string $hash): JsonResponse
    {
        try {
            $result = $this->storage->unpin($hash);

            return response()->json([
                'success' => $result,
                'message' => $result ? 'Removed successfully' : 'Remove failed — file not found',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}