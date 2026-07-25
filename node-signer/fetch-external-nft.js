#!/usr/bin/env node
//
// Given a mint address, figures out whether it's an mpl-core asset or a
// legacy SPL-Token + Token Metadata NFT, fetches its on-chain metadata
// (and the linked off-chain JSON), and works out who currently holds it.
// Read-only — no signing, no DAS/Helius dependency, just standard RPC.
//
// Usage: echo "$MINT_ADDRESS" | node fetch-external-nft.js
// Env:   SOLANA_RPC_URL

const { Connection, PublicKey } = require("@solana/web3.js");

function readStdin() {
  return new Promise((resolve, reject) => {
    let data = "";
    process.stdin.setEncoding("utf8");
    process.stdin.on("data", (chunk) => (data += chunk));
    process.stdin.on("end", () => resolve(data));
    process.stdin.on("error", reject);
  });
}

function resolveUri(uri) {
  if (!uri) return uri;
  if (uri.startsWith("ipfs://")) {
    return "https://ipfs.io/ipfs/" + uri.replace("ipfs://", "");
  }
  return uri;
}

async function fetchOffchainJson(uri) {
  try {
    const resolved = resolveUri(uri);
    const res = await fetch(resolved, { signal: AbortSignal.timeout(10000) });
    if (!res.ok) return {};
    return await res.json();
  } catch (_) {
    return {};
  }
}

async function tryMplCore(umi, mintAddress) {
  const { fetchAsset } = await import("@metaplex-foundation/mpl-core");
  const { publicKey: umiPk } = await import("@metaplex-foundation/umi");

  const asset = await fetchAsset(umi, umiPk(mintAddress));

  const royaltyPlugin = (asset.plugins || []).find((p) => p.type === "Royalties");
  const sellerFeeBasisPoints = royaltyPlugin ? royaltyPlugin.basisPoints : 0;
  const creators = royaltyPlugin?.creators?.map((c) => ({
    address: c.address.toString(),
    share: c.percentage,
  })) || [{ address: asset.updateAuthority?.address?.toString() || asset.owner.toString(), share: 100 }];

  return {
    token_standard: "mpl_core",
    name: asset.name,
    uri: asset.uri,
    seller_fee_basis_points: sellerFeeBasisPoints,
    creators,
    update_authority: asset.updateAuthority?.address?.toString() || null,
    current_owner: asset.owner.toString(),
  };
}

async function tryLegacyTokenMetadata(umi, connection, mintAddress) {
  const { fetchMetadataFromSeeds } = await import("@metaplex-foundation/mpl-token-metadata");
  const { publicKey: umiPk } = await import("@metaplex-foundation/umi");

  const metadata = await fetchMetadataFromSeeds(umi, { mint: umiPk(mintAddress) });

  // Supply for an NFT mint is 1 — whoever holds the largest (only)
  // token account for this mint is the current owner. No DAS needed.
  const mintPubkey = new PublicKey(mintAddress);
  const largest = await connection.getTokenLargestAccounts(mintPubkey);
  let currentOwner = null;
  const topAccount = largest?.value?.[0];
  if (topAccount && Number(topAccount.amount) > 0) {
    const accInfo = await connection.getParsedAccountInfo(topAccount.address);
    currentOwner = accInfo?.value?.data?.parsed?.info?.owner || null;
  }

  return {
    token_standard: "legacy_token_metadata",
    name: metadata.name?.replace(/\0/g, "").trim(),
    uri: metadata.uri?.replace(/\0/g, "").trim(),
    seller_fee_basis_points: metadata.sellerFeeBasisPoints,
    creators: (metadata.creators.__option === "Some" ? metadata.creators.value : []).map((c) => ({
      address: c.address.toString(),
      share: c.share,
    })),
    update_authority: metadata.updateAuthority?.toString() || null,
    current_owner: currentOwner,
  };
}

async function main() {
  const rpcUrl = process.env.SOLANA_RPC_URL;
  if (!rpcUrl) throw new Error("SOLANA_RPC_URL not set");

  const mintAddress = (await readStdin()).trim();
  if (!mintAddress) throw new Error("No mint address provided on stdin");

  const { createUmi } = await import("@metaplex-foundation/umi-bundle-defaults");
  const { mplCore }    = await import("@metaplex-foundation/mpl-core");
  const { mplTokenMetadata } = await import("@metaplex-foundation/mpl-token-metadata");

  const connection = new Connection(rpcUrl, "confirmed");
  const umi = createUmi(rpcUrl).use(mplCore()).use(mplTokenMetadata());

  let result;
  try {
    result = await tryMplCore(umi, mintAddress);
  } catch (mplCoreErr) {
    try {
      result = await tryLegacyTokenMetadata(umi, connection, mintAddress);
    } catch (legacyErr) {
      throw new Error(
        `Could not read this mint as either mpl-core or legacy Token Metadata. ` +
        `mpl-core error: ${mplCoreErr.message}; legacy error: ${legacyErr.message}`
      );
    }
  }

  const offchain = await fetchOffchainJson(result.uri);

  console.log(JSON.stringify({
    success: true,
    mint_address: mintAddress,
    ...result,
    image_url: resolveUri(offchain.image) || null,
    description: offchain.description || null,
    attributes: offchain.attributes || [],
  }));
}

main().catch((err) => {
  console.log(JSON.stringify({ success: false, error: err.message || String(err) }));
  process.exit(1);
});
