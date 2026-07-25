<?php

namespace App\Http\Controllers;

use App\Services\PinataService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class IpfsController extends Controller
{
    public function __construct(private PinataService $pinata) {}

    /**
     * Image → Pinata IPFS Upload
     * POST /api/ipfs/upload-image
     */
    public function uploadImage(Request $request): JsonResponse
    {
        $request->validate([
            'image' => 'required|file|image|mimes:jpg,jpeg,png,gif,webp|max:10240|dimensions:max_width=8000,max_height=8000',
            'name'  => 'required|string|max:100',
        ]);

        try {
            $result = $this->pinata->uploadImage(
                $request->file('image'),
                $request->name
            );

            return response()->json([
                'success'   => true,
                'message'   => 'Image uploaded to IPFS',
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
     * NFT Metadata → Pinata IPFS Upload
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
            $result = $this->pinata->uploadMetadata($metadata);

            return response()->json([
                'success'      => true,
                'message'      => 'Metadata uploaded to IPFS',
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
     * Pinata to Unpin
     * DELETE /api/ipfs/unpin/{hash}
     */
    public function unpin(string $hash): JsonResponse
    {
        try {
            $result = $this->pinata->unpin($hash);

            return response()->json([
                'success' => $result,
                'message' => $result ? 'Unpinned successfully' : 'Unpin failed',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
