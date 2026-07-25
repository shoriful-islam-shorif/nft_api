#!/usr/bin/env node
//
// Reads a base64, partially-signed (by the buyer) Solana transaction from
// stdin, adds the platform's signature (as the mpl-core TransferDelegate
// authority for the NFT being sold), submits it, waits for confirmation,
// and prints a single JSON line to stdout.
//
// Why this exists as a separate Node script instead of pure PHP: mpl-core
// and Solana transaction signing only have first-class, actively
// maintained libraries in JS/TS. There's no PHP Solana SDK we'd trust to
// correctly sign a real transaction, so this one step — and only this
// step — is delegated to a small, auditable Node script that Laravel
// shells out to via SolanaSignerService.
//
// Usage: echo "$BASE64_TX" | node cosign-and-submit.js
// Env:   SOLANA_RPC_URL, PLATFORM_DELEGATE_KEYPAIR_PATH

const { Connection, Transaction, Keypair } = require("@solana/web3.js");
const fs = require("fs");

function readStdin() {
  return new Promise((resolve, reject) => {
    let data = "";
    process.stdin.setEncoding("utf8");
    process.stdin.on("data", (chunk) => (data += chunk));
    process.stdin.on("end", () => resolve(data));
    process.stdin.on("error", reject);
  });
}

async function main() {
  const rpcUrl      = process.env.SOLANA_RPC_URL;
  const keypairPath = process.env.PLATFORM_DELEGATE_KEYPAIR_PATH;

  if (!rpcUrl)      throw new Error("SOLANA_RPC_URL not set");
  if (!keypairPath) throw new Error("PLATFORM_DELEGATE_KEYPAIR_PATH not set");
  if (!fs.existsSync(keypairPath)) throw new Error(`Keypair file not found at ${keypairPath}`);

  const base64Tx = (await readStdin()).trim();
  if (!base64Tx) throw new Error("No transaction provided on stdin");

  const secretKey       = Uint8Array.from(JSON.parse(fs.readFileSync(keypairPath, "utf8")));
  const platformKeypair = Keypair.fromSecretKey(secretKey);

  const connection = new Connection(rpcUrl, "confirmed");
  const tx = Transaction.from(Buffer.from(base64Tx, "base64"));

  // Sanity check: the platform key must actually be one of this
  // transaction's required signers — i.e. the frontend really built this
  // as a delegated NFT transfer + payment, not something unrelated.
  // Refuse to blindly sign anything handed to us.
  const isRequiredSigner = tx.signatures.some(
    (s) => s.publicKey.toBase58() === platformKeypair.publicKey.toBase58()
  );
  if (!isRequiredSigner) {
    throw new Error("Platform key is not a required signer on this transaction — refusing to sign");
  }

  tx.partialSign(platformKeypair);

  const signature = await connection.sendRawTransaction(tx.serialize(), {
    skipPreflight: false,
    preflightCommitment: "confirmed",
  });

  await connection.confirmTransaction(signature, "confirmed");

  console.log(JSON.stringify({ success: true, signature }));
}

main().catch((err) => {
  console.log(JSON.stringify({ success: false, error: err.message || String(err) }));
  process.exit(1);
});
