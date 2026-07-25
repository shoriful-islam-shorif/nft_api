import { Connection, Keypair, clusterApiUrl } from "@solana/web3.js";
import {
  getOrCreateAssociatedTokenAccount,
  transfer,
} from "@solana/spl-token";
import fs from "fs";

const RPC_URL = process.env.SOLANA_RPC_URL || clusterApiUrl("devnet");

const AUTHORITY_FILE = "./devnet-authority.json";

//  SPUMP mint
const SPUMP_MINT = "2dnqMdrntLNHwuLFnnKQrsnSsWgzK2qxEdTebSNzsprK";
const USDC_MINT = "4FSFZHeiEUKxZa2jWMkYkeoV1sqekCLSpRYCERiiVre7";

//  Phantom wallet
// const RECIPIENT = "APXUCLWfUWKvH7R8kvmVZuKcvMj3NT36oHHBPHWEJqJT";
const RECIPIENT = "GZLMqyh3sPEcHe72ZFzjFd9rxev7NDHaC4kPAhVKUsQa";

const AMOUNT = 1000;
const DECIMALS = 6;

async function main() {
  const connection = new Connection(RPC_URL, "confirmed");

  const secret = JSON.parse(fs.readFileSync(AUTHORITY_FILE, "utf8"));
  const authority = Keypair.fromSecretKey(Uint8Array.from(secret));

  const mint = new (await import("@solana/web3.js")).PublicKey(SPUMP_MINT);
  const recipient = new (await import("@solana/web3.js")).PublicKey(RECIPIENT);

  const senderAta = await getOrCreateAssociatedTokenAccount(
    connection,
    authority,
    mint,
    authority.publicKey
  );

  const recipientAta = await getOrCreateAssociatedTokenAccount(
    connection,
    authority,
    mint,
    recipient
  );

  const sig = await transfer(
    connection,
    authority,
    senderAta.address,
    recipientAta.address,
    authority,
    AMOUNT * 10 ** DECIMALS
  );

  console.log("Transfer successful!");
  console.log("Signature:", sig);
}

main().catch(console.error);