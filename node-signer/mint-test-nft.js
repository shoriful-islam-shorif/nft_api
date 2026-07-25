#!/usr/bin/env node
//
// DEVNET TESTING ONLY. Mints a plain legacy-standard (SPL Token +
// Token Metadata) NFT directly to a wallet, completely bypassing this
// platform — so you get a genuine "external" NFT to test the Import
// feature against.
//
// Usage:
//   node mint-test-nft.js /path/to/your-test-keypair.json
//
// The keypair file must be a JSON array secret key (same format
// generate-keypair.js prints), already funded with devnet SOL
// (https://faucet.solana.com). The NFT is minted TO that same wallet.
//
// After running, either:
//   (a) import that keypair into Phantom (Settings → Add/Connect
//       Wallet → Import Private Key) to test with your real wallet UI, or
//   (b) just use the printed mint address directly if you already
//       have another way to prove ownership.

const { Connection, Keypair } = require("@solana/web3.js");
const { generateSigner, keypairIdentity, percentAmount } = require("@metaplex-foundation/umi");
const { createUmi } = require("@metaplex-foundation/umi-bundle-defaults");
const { createNft, mplTokenMetadata } = require("@metaplex-foundation/mpl-token-metadata");
const { fromWeb3JsKeypair } = require("@metaplex-foundation/umi-web3js-adapters");
const fs = require("fs");

async function main() {
  const keypairPath = process.argv[2];
  if (!keypairPath) throw new Error("Usage: node mint-test-nft.js /path/to/keypair.json");

  const rpcUrl = process.env.SOLANA_RPC_URL || "https://api.devnet.solana.com";
  const secretKey = Uint8Array.from(JSON.parse(fs.readFileSync(keypairPath, "utf8")));
  const web3Keypair = Keypair.fromSecretKey(secretKey);

  const umi = createUmi(rpcUrl).use(mplTokenMetadata());
  umi.use(keypairIdentity(fromWeb3JsKeypair(web3Keypair)));

  const mint = generateSigner(umi);

  // A tiny placeholder metadata URI — good enough for a test asset.
  // Real NFTs would point to actual hosted JSON with an image.
  const uri = "https://arweave.net/1BhRHVeuCwq6r0kgYPMHmWkeqZ9uD1KwZOizJlHkq6c";

  await createNft(umi, {
    mint,
    name: "Test Import NFT",
    uri,
    sellerFeeBasisPoints: percentAmount(5), // 5% royalty, for testing royalty math too
  }).sendAndConfirm(umi, { confirm: { commitment: "confirmed" } });

  console.log("Minted! Mint address (use this in the Import page):");
  console.log(mint.publicKey.toString());
  console.log("\nOwner wallet:");
  console.log(web3Keypair.publicKey.toString());
}

main().catch((err) => {
  console.error("Failed:", err.message || err);
  process.exit(1);
});
