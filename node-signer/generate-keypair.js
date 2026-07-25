// One-time script to generate the platform's delegate hot wallet.
// Run this ONCE, then move the printed secret key JSON into a secure
// file (NOT inside your git repo) and set PLATFORM_DELEGATE_KEYPAIR_PATH
// in your .env to point at it.
const { Keypair } = require("@solana/web3.js");
const kp = Keypair.generate();
console.log("Public Key (put this in .env as PLATFORM_DELEGATE_WALLET):");
console.log(kp.publicKey.toBase58());
console.log("\nSecret Key (save this to a JSON file, e.g. storage/app/secure/delegate-keypair.json):");
console.log(JSON.stringify(Array.from(kp.secretKey)));
