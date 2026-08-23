#!/usr/bin/env node
//
// Reads a single JSON line from stdin describing the buyer's
// partially-signed transaction plus the payment we expect it to contain,
// verifies that payment against the transaction's ACTUAL decoded
// instructions, and only then adds the platform's signature (as the
// mpl-core TransferDelegate authority for the NFT being sold), submits
// it, waits for confirmation, and prints a single JSON line to stdout.
//
// SECURITY-CRITICAL — this verify-before-sign step is what prevents the
// "payment failed but the NFT transferred anyway" bug. The buyer's
// transaction is atomic: the payment instructions and the mpl-core
// NFT-transfer instruction are in the SAME transaction. Once we sign and
// broadcast, BOTH happen together, irreversibly. So payment verification
// must happen before signing, not after broadcasting — checking
// afterwards can only tell you the transfer already happened regardless
// of what it finds.
//
// Why this exists as a separate Node script instead of pure PHP: mpl-core
// and Solana transaction signing only have first-class, actively
// maintained libraries in JS/TS. There's no PHP Solana SDK we'd trust to
// correctly sign a real transaction, so this one step — and only this
// step — is delegated to a small, auditable Node script that Laravel
// shells out to via SolanaSignerService.
//
// Usage: echo "$JSON_PAYLOAD" | node cosign-and-submit.js
// Payload shape:
//   {
//     "signedTx": "<base64, buyer-signed transaction>",
//     "expectedTransfers": [
//       { "destination": "<owner wallet pubkey>", "amount": 1.23, "mint": "<token mint pubkey>" },
//       ...
//     ],
//     "tolerance": 0.0005   // optional, human-unit tolerance applied to every leg
//   }
// Env:   SOLANA_RPC_URL, PLATFORM_DELEGATE_KEYPAIR_PATH

const { Connection, Transaction, Keypair, PublicKey } = require("@solana/web3.js");
const {
  TOKEN_PROGRAM_ID,
  decodeTransferInstruction,
  decodeTransferCheckedInstruction,
  getAssociatedTokenAddressSync,
  getMint,
} = require("@solana/spl-token");
const { MPL_CORE_PROGRAM_ID: MPL_CORE_PROGRAM_ID_UMI } = require("@metaplex-foundation/mpl-core");
const fs = require("fs");

// mpl-core's MPL_CORE_PROGRAM_ID is a Umi-style pubkey (plain branded
// string), not a @solana/web3.js PublicKey — PublicKey#equals() needs a
// real instance, so re-wrap it once here rather than at every call site.
const MPL_CORE_PROGRAM_ID = new PublicKey(MPL_CORE_PROGRAM_ID_UMI.toString());

// mpl-core TransferV1 instruction: discriminator byte 14, accounts in
// FIXED order [asset, collection, payer, authority, newOwner,
// systemProgram, logWrapper] — optional accounts are still present as
// placeholder keys (see mpl-core's generated shared.getAccountMetasAndSigners,
// called with the 'programId' strategy), so the indices below never shift.
const MPL_CORE_TRANSFER_V1_DISCRIMINATOR = 14;
const MPL_CORE_TRANSFER_V1_ACCOUNT_INDEX = {
  asset: 0,
  authority: 3,
  newOwner: 4,
};

function readStdin() {
  return new Promise((resolve, reject) => {
    let data = "";
    process.stdin.setEncoding("utf8");
    process.stdin.on("data", (chunk) => (data += chunk));
    process.stdin.on("end", () => resolve(data));
    process.stdin.on("error", reject);
  });
}

/**
 * Pull every SPL-token transfer instruction (Transfer or TransferChecked,
 * legacy Token program only — extend to TOKEN_2022_PROGRAM_ID here too if
 * this platform ever lists Token-2022 mints) out of the transaction, with
 * amounts as raw base-unit BigInts.
 */
function decodeTokenTransfers(tx) {
  const transfers = [];

  for (const ix of tx.instructions) {
    if (!ix.programId.equals(TOKEN_PROGRAM_ID)) continue;

    // TransferChecked (preferred: encodes the mint directly, so no
    // ambiguity about which token is moving).
    try {
      const decoded = decodeTransferCheckedInstruction(ix, TOKEN_PROGRAM_ID);
      transfers.push({
        destination: decoded.keys.destination.pubkey,
        amountRaw: BigInt(decoded.data.amount),
        mint: decoded.keys.mint.pubkey,
      });
      continue;
    } catch (_) {
      // Not a TransferChecked instruction — fall through and try legacy Transfer.
    }

    // Legacy Transfer (no mint in the instruction data — matched against
    // expected mint by destination ATA instead, below).
    try {
      const decoded = decodeTransferInstruction(ix, TOKEN_PROGRAM_ID);
      transfers.push({
        destination: decoded.keys.destination.pubkey,
        amountRaw: BigInt(decoded.data.amount),
        mint: null,
      });
    } catch (_) {
      // Some other Token-program instruction (e.g. Approve) — ignore.
    }
  }

  return transfers;
}

/**
 * Confirm every entry in expectedTransfers is actually present in the
 * transaction's decoded token transfers, matched by destination
 * associated-token-account + amount (within tolerance) for the given mint.
 * Throws with a descriptive message on the first mismatch — refusing to
 * sign is the whole point, so this never "best-efforts" a partial match.
 */
async function verifyExpectedTransfers(connection, tx, expectedTransfers, tolerance) {
  if (!Array.isArray(expectedTransfers) || expectedTransfers.length === 0) {
    throw new Error("expectedTransfers missing or empty — refusing to sign an unverified transaction");
  }

  const decoded = decodeTokenTransfers(tx);
  const decimalsCache = new Map();

  async function decimalsFor(mintAddress) {
    if (decimalsCache.has(mintAddress)) return decimalsCache.get(mintAddress);
    const info = await getMint(connection, new PublicKey(mintAddress));
    decimalsCache.set(mintAddress, info.decimals);
    return info.decimals;
  }

  const tol = typeof tolerance === "number" && tolerance > 0 ? tolerance : 0;

  for (const expected of expectedTransfers) {
    if (!expected || !expected.destination || !expected.mint || expected.amount == null) {
      throw new Error("expectedTransfers entry missing destination/amount/mint");
    }

    const mintPk = new PublicKey(expected.mint);
    const ownerPk = new PublicKey(expected.destination);
    // allowOwnerOffCurve=true: the treasury or a creator wallet could be
    // a PDA, not just a normal keypair-owned wallet.
    const expectedAta = getAssociatedTokenAddressSync(mintPk, ownerPk, true);

    const decimals = await decimalsFor(expected.mint);
    const expectedRaw = BigInt(Math.round(expected.amount * 10 ** decimals));
    const toleranceRaw = BigInt(Math.round(tol * 10 ** decimals));

    const match = decoded.find((d) => {
      if (!d.destination.equals(expectedAta)) return false;
      if (d.mint && !d.mint.equals(mintPk)) return false; // TransferChecked: mint must match too
      const diff = d.amountRaw > expectedRaw ? d.amountRaw - expectedRaw : expectedRaw - d.amountRaw;
      return diff <= toleranceRaw;
    });

    if (!match) {
      throw new Error(
        `Payment verification failed: expected ${expected.amount} (mint ${expected.mint}) to ${expected.destination} not found in transaction`
      );
    }
  }
}

/**
 * Confirm the transaction actually contains an mpl-core TransferV1
 * instruction moving `expectedNftTransfer.asset` to
 * `expectedNftTransfer.newOwner` (and, if given, authorized by
 * `expectedNftTransfer.authority`). This is the missing half of payment
 * verification: verifyExpectedTransfers() above only proves the SPL-token
 * payment legs are correct — it says nothing about which NFT (or whose
 * wallet) the same transaction's delegate-authority transfer instruction
 * actually moves. Since the platform delegate key can authorize a
 * TransferV1 for ANY listed asset (not just the one being paid for), a
 * transaction that passes payment verification could still smuggle in a
 * transfer of a completely different, more valuable NFT to the buyer's
 * wallet for free. Refusing to sign here closes that gap the same way
 * verifyExpectedTransfers() closes it for payment.
 */
function verifyExpectedNftTransfer(tx, expectedNftTransfer) {
  if (!expectedNftTransfer || !expectedNftTransfer.asset || !expectedNftTransfer.newOwner) {
    throw new Error("expectedNftTransfer missing asset/newOwner — refusing to sign an unverified NFT transfer");
  }

  const expectedAsset    = new PublicKey(expectedNftTransfer.asset);
  const expectedNewOwner = new PublicKey(expectedNftTransfer.newOwner);
  const expectedAuthority = expectedNftTransfer.authority
    ? new PublicKey(expectedNftTransfer.authority)
    : null;

  const match = tx.instructions.find((ix) => {
    if (!ix.programId.equals(MPL_CORE_PROGRAM_ID)) return false;
    if (!ix.data || ix.data[0] !== MPL_CORE_TRANSFER_V1_DISCRIMINATOR) return false;

    const keys = ix.keys;
    const assetKey     = keys[MPL_CORE_TRANSFER_V1_ACCOUNT_INDEX.asset]?.pubkey;
    const authorityKey = keys[MPL_CORE_TRANSFER_V1_ACCOUNT_INDEX.authority]?.pubkey;
    const newOwnerKey  = keys[MPL_CORE_TRANSFER_V1_ACCOUNT_INDEX.newOwner]?.pubkey;

    if (!assetKey || !assetKey.equals(expectedAsset)) return false;
    if (!newOwnerKey || !newOwnerKey.equals(expectedNewOwner)) return false;
    if (expectedAuthority && (!authorityKey || !authorityKey.equals(expectedAuthority))) return false;

    return true;
  });

  if (!match) {
    throw new Error(
      `NFT transfer verification failed: expected asset ${expectedNftTransfer.asset} to be transferred to ${expectedNftTransfer.newOwner} not found in transaction`
    );
  }
}

async function main() {
  const rpcUrl      = process.env.SOLANA_RPC_URL;
  const keypairPath = process.env.PLATFORM_DELEGATE_KEYPAIR_PATH;

  if (!rpcUrl)      throw new Error("SOLANA_RPC_URL not set");
  if (!keypairPath) throw new Error("PLATFORM_DELEGATE_KEYPAIR_PATH not set");
  if (!fs.existsSync(keypairPath)) throw new Error(`Keypair file not found at ${keypairPath}`);

  const raw = (await readStdin()).trim();
  if (!raw) throw new Error("No input provided on stdin");

  let payload;
  try {
    payload = JSON.parse(raw);
  } catch (_) {
    throw new Error("Input must be a JSON object: { signedTx, expectedTransfers, tolerance? }");
  }

  const { signedTx, expectedTransfers, expectedNftTransfer, tolerance } = payload;
  if (!signedTx) throw new Error("signedTx missing from input");

  const secretKey       = Uint8Array.from(JSON.parse(fs.readFileSync(keypairPath, "utf8")));
  const platformKeypair = Keypair.fromSecretKey(secretKey);

  const connection = new Connection(rpcUrl, "confirmed");
  const tx = Transaction.from(Buffer.from(signedTx, "base64"));

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

  // ── Verify payment BEFORE signing ─────────────────────────────
  // If this throws, we exit via the catch below without ever calling
  // partialSign() or sendRawTransaction() — nothing is broadcast, so the
  // NFT is never transferred and the buyer is never charged.
  await verifyExpectedTransfers(connection, tx, expectedTransfers, tolerance);

  // ── Verify the NFT transfer itself BEFORE signing ─────────────
  // Payment verification alone is not enough: it never looks at which
  // asset the same transaction's mpl-core TransferV1 instruction moves,
  // or to whom. Required so a transaction can't pass payment checks for
  // one (cheap) NFT while transferring a different one to the buyer.
  verifyExpectedNftTransfer(tx, expectedNftTransfer);

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
