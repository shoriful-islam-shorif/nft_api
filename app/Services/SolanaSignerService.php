<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Shells out to a small Node.js helper (node-signer/cosign-and-submit.js)
 * to add the platform's signature to a buyer-submitted, partially-signed
 * transaction, then broadcasts it.
 *
 * Why Node and not pure PHP: mpl-core / Metaplex UMI instruction builders
 * and Solana transaction signing only have first-class, actively
 * maintained libraries in JS/TS. Rather than reimplementing (or trusting
 * a lesser-used) ed25519/Solana transaction signing in PHP, this one
 * step — and only this step — is delegated to that Node script.
 */
class SolanaSignerService
{
    private string $nodeBinary;
    private string $scriptPath;

    public function __construct()
    {
        $this->nodeBinary = config('services.solana.node_binary', 'node');
        $this->scriptPath = base_path('node-signer/cosign-and-submit.js');
    }

    /**
     * Read-only: detect an external mint's token standard (mpl-core vs
     * legacy Token Metadata), fetch its on-chain + off-chain metadata,
     * and find its current owner. Used by the NFT import flow. Shells
     * out to node-signer/fetch-external-nft.js for the same reason as
     * coSignAndSubmit() — no trustworthy PHP SDK for deserializing
     * Metaplex account data.
     *
     * @return array{success: bool, error?: string, ...}
     */
    /**
     * Build the environment to hand to the Node child process.
     *
     * getenv() with no arguments reads the real OS process environment
     * table directly — unlike $_ENV (empty unless php.ini's
     * variables_order includes 'E', which many Windows/XAMPP setups
     * don't) or $_SERVER under the `php artisan serve` built-in server
     * (which doesn't reliably carry OS-level vars like SystemRoot).
     * Without SystemRoot, Node's crypto/CSPRNG subsystem crashes on
     * Windows with "Assertion failed: ncrypto::CSPRNG(nullptr, 0)".
     */
    private function buildProcessEnv(array $overrides): array
    {
        $inherited = getenv();
        if (!is_array($inherited)) {
            $inherited = [];
        }
        return array_merge($inherited, $overrides);
    }

    public function fetchExternalNft(string $mintAddress): array
    {
        $scriptPath = base_path('node-signer/fetch-external-nft.js');

        if (!file_exists($scriptPath)) {
            Log::error('Solana signer: fetch-external-nft script missing', ['path' => $scriptPath]);
            return ['success' => false, 'error' => 'Import service is not installed on this server.'];
        }

        $env = $this->buildProcessEnv([
            'SOLANA_RPC_URL' => config('services.solana.rpc_url'),
        ]);

        $process = new Process(
            [$this->nodeBinary, $scriptPath],
            dirname($scriptPath),
            $env
        );

        $process->setInput($mintAddress);
        $process->setTimeout(30);

        try {
            $process->run();
        } catch (\Throwable $e) {
            Log::error('Solana signer: fetch-external-nft process failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'Import lookup failed to run.'];
        }

        $output = trim($process->getOutput());
        if (!$output) {
            Log::error('Solana signer: no output from fetch-external-nft', [
                'stderr' => trim($process->getErrorOutput()),
            ]);
            return ['success' => false, 'error' => 'Import lookup failed to respond.'];
        }

        $result = json_decode($output, true);
        if (!is_array($result)) {
            return ['success' => false, 'error' => 'Import lookup returned an invalid response.'];
        }

        return $result;
    }

    /**
     * @param  string $base64Tx  Partially-signed transaction (buyer's signature already present)
     * @return array{success: bool, signature?: string, error?: string}
     */
    public function coSignAndSubmit(string $base64Tx): array
    {
        if (!file_exists($this->scriptPath)) {
            Log::error('Solana signer: node script missing', ['path' => $this->scriptPath]);
            return ['success' => false, 'error' => 'Signing service is not installed on this server.'];
        }

        $keypairPath = config('services.platform.delegate_keypair_path');
        if (!$keypairPath || !file_exists($keypairPath)) {
            Log::error('Solana signer: delegate keypair file missing', ['path' => $keypairPath]);
            return ['success' => false, 'error' => 'Platform delegate wallet is not configured on this server.'];
        }

        $env = $this->buildProcessEnv([
            'SOLANA_RPC_URL'                 => config('services.solana.rpc_url'),
            'PLATFORM_DELEGATE_KEYPAIR_PATH' => $keypairPath,
        ]);

        $process = new Process(
            [$this->nodeBinary, $this->scriptPath],
            dirname($this->scriptPath),
            $env
        );

        $process->setInput($base64Tx);
        $process->setTimeout(60);

        try {
            $process->run();
        } catch (\Throwable $e) {
            Log::error('Solana signer: process execution failed', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'Signing service failed to run.'];
        }

        $output      = trim($process->getOutput());
        $errorOutput = trim($process->getErrorOutput());

        if (!$output) {
            Log::error('Solana signer: no output from node script', [
                'stderr'    => $errorOutput,
                'exit_code' => $process->getExitCode(),
            ]);
            return ['success' => false, 'error' => 'Signing service failed to respond.'];
        }

        $result = json_decode($output, true);
        if (!is_array($result)) {
            Log::error('Solana signer: unparseable output', ['output' => $output, 'stderr' => $errorOutput]);
            return ['success' => false, 'error' => 'Signing service returned an invalid response.'];
        }

        if (!($result['success'] ?? false)) {
            Log::warning('Solana signer: signing/submission failed', $result);
        }

        return $result;
    }
}
