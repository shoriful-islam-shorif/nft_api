<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Exception;

class PinataService
{
    private string $apiKey;
    private string $secretKey;
    private string $gateway;
    private string $baseUrl = 'https://api.pinata.cloud';

    public function __construct()
    {
        $this->apiKey   = config('services.pinata.api_key');
        $this->secretKey = config('services.pinata.secret_key');
        $this->gateway  = config('services.pinata.gateway');
    }

    /**
     * Image Pinata tp  Upload 
     */
    public function uploadImage(UploadedFile $file, string $name): array
    {
        $response = Http::withHeaders([
            'pinata_api_key'        => $this->apiKey,
            'pinata_secret_api_key' => $this->secretKey,
        ])->attach(
            'file',
            file_get_contents($file->getRealPath()),
            $file->getClientOriginalName()
        )->post("{$this->baseUrl}/pinning/pinFileToIPFS", [
            'pinataMetadata' => json_encode(['name' => $name]),
            'pinataOptions'  => json_encode(['cidVersion' => 1]),
        ]);

        if (!$response->successful()) {
            throw new Exception('Pinata image upload failed: ' . $response->body());
        }

        $ipfsHash = $response->json('IpfsHash');

        return [
            'ipfs_hash' => $ipfsHash,
            'url'       => $this->gateway . $ipfsHash,
            'gateway'   => "https://ipfs.io/ipfs/{$ipfsHash}",
        ];
    }

    /**
     * NFT Metadata Pinata to  Upload 
     */
    public function uploadMetadata(array $metadata): array
    {
        $response = Http::withHeaders([
            'pinata_api_key'        => $this->apiKey,
            'pinata_secret_api_key' => $this->secretKey,
            'Content-Type'          => 'application/json',
        ])->post("{$this->baseUrl}/pinning/pinJSONToIPFS", [
            'pinataContent'  => $metadata,
            'pinataMetadata' => ['name' => $metadata['name'] . '_metadata'],
            'pinataOptions'  => ['cidVersion' => 1],
        ]);

        if (!$response->successful()) {
            throw new Exception('Pinata metadata upload failed: ' . $response->body());
        }

        $ipfsHash = $response->json('IpfsHash');

        return [
            'ipfs_hash'    => $ipfsHash,
            'metadata_uri' => $this->gateway . $ipfsHash,
        ];
    }

    /**
     * IPFS to  Unpin
     */
    public function unpin(string $ipfsHash): bool
    {
        $response = Http::withHeaders([
            'pinata_api_key'        => $this->apiKey,
            'pinata_secret_api_key' => $this->secretKey,
        ])->delete("{$this->baseUrl}/pinning/unpin/{$ipfsHash}");

        return $response->successful();
    }
}
