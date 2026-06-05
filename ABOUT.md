# ABOUT

## Why this role

I like building systems where correctness and an honest "I don't know" matter
more than raw output — and AI-native engineering is where that judgment is the
whole game. This problem is a good example: in collections, a *wrong* contact is
worse than a missing one, so a confidence score, provenance, and first-class
"cannot verify" beat a slick scraper that invents precise-looking contacts. That
asymmetry is a more interesting constraint to design against than chasing recall.

## How I work with AI tools

I drive Claude Code + MCP daily at KAP GAMES. My rule is **plan first, make the
model build against an explicit spec, then verify rather than trust.** For this
take-home I committed `PLAN.md` before reading the clarifications, then checked
the scorer by running the real fixture through the whole pipeline and asserting
the score distribution — not by eyeballing the code. I override the model most
often when it reasons by analogy across systems ("chain A works like this, so
chain B must too") — that's a failure mode I've been burned by myself (below),
so I watch for it. I trust it for mechanical breadth; I own the architecture and
the verification.

## My last project (Enjin daemon-signed mints, KAP GAMES)

Making Pirate Pass EXP/badge claims mint on Enjin end-to-end **without asking the
player to sign per claim**, with both token collections permanently soulbound.

- **One ambiguity — who signs a server-initiated mint?** On our EVM chains the
  pattern is "backend issues a voucher, user submits the tx." That didn't carry
  to Enjin (Substrate): no per-collection contract to verify an operator voucher,
  and WalletConnect there only covers tokens the user already owns. I resolved it
  by reading the platform's actual transaction lifecycle instead of assuming —
  mutations sit `PENDING` until the Enjin Wallet Daemon (our key, our infra)
  signs and broadcasts. So: the daemon signs, server-side, no user prompt. I
  wrote that down as the architecture before building.

- **One tradeoff — `freezeCollection { PERMANENT }` over per-token freeze.** The
  per-token path meant a `frozen_at` column, a per-mint freeze step, and a beat
  task to reconcile it. I took irreversibility in exchange for deleting that
  whole class of ongoing complexity — justified because these tokens are *meant*
  to be non-transferable forever. If "soulbound" had been a maybe, I'd have paid
  for the reversible path instead.

- **One mistake — I inferred a field shape from the UI instead of the model.** I
  built Enjin wallet-linking by analogy to the Soneium flow, matching on
  `subtype = 'enjin'`. But `Subtype.choices` only contained `SONEIUM` — Enjin
  wallets actually lived on a different column, `wallet_type`. I rebuilt the read
  path on `wallet_type` and now check the real enum before wiring against an
  inferred shape.

- **One review comment that changed my mind.** My plan asserted the Enjin
  Platform could auto-sign mints via a managed wallet's hosted key — I'd ported
  the EVM "the platform just signs for you" assumption straight across. Review
  surfaced that managed wallets don't auto-sign; pending txs wait for the wallet
  daemon. That reversed the whole signing architecture (one daemon per tenant, it
  funds its own extrinsic fees). The lesson stuck harder than the fix: verify a
  platform's real behavior before designing on top of it.

Shipped into [capnco.gg](https://capnco.gg/profile). More at [daccoder.dev](https://daccoder.dev).

## What I'd improve about this challenge / your CLAUDE.md

- **CLAUDE.md vs the Contact Finder.** The PR / branch / `[TICKET-ID]`
  conventions clearly belong to the legacy ticket challenge, but they're the
  only "how we ship" guidance, so it's ambiguous whether a PR is expected here —
  the submission text says "clean commit timeline." One line ("Contact Finder:
  no PR, commit to your repo") would remove the doubt.
- **The auto-review Action** triggers on `pull_request` and needs
  `ANTHROPIC_API_KEY` / a Slack webhook. On a candidate's fork those secrets
  don't exist, so opening a PR produces a red, ticket-flavoured run. Worth a note.
- **Entity resolution scope.** The mocks key on exact `company_name`; real life
  is fuzzy name+address matching. A sentence on whether that's in scope would
  help candidates calibrate.
- `php artisan test --parallel` in CLAUDE.md needs `brianium/paratest`, which
  isn't in `composer.json` — plain `php artisan test` works.
