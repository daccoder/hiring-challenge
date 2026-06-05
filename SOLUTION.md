# Contact Finder — solution

A precision-first contact finder: takes `company_name` + `mailing_address`,
combines three independent (mocked) sources, and returns **one decision-maker
contact per company with a confidence score and full provenance** — or an
honest "cannot verify" with a reason, never a fabricated contact.

Built in Laravel 12 / PHP 8.2 to match your stack, as `app/Modules/ContactFinder`.

## Run it

```bash
composer install
php artisan config:clear
php artisan contacts:find          # uses challenge/data + challenge/mocks by default
php artisan test                   # pure unit tests, no DB needed
./vendor/bin/pint                  # formatting
```

Options: `--input=`, `--mocks=`, `--out=` (defaults point at the challenge
data). The committed `output/` is byte-identical to a fresh run — the command
and the files share the same DTO serialisation.

Outputs per row (`output/contacts.csv`): the six required fields —
`contact_name`, `contact_role`, `contact_email_or_phone`, `confidence_score`,
`source`, `needs_human_review` — plus `status` and `reason`.
`output/contacts.jsonl` adds the **full audit trail**: every `source_url`, the
raw provider payloads, and the per-point score breakdown.

## Architecture

```
CSV → ContactFinderService → [ registry | listing | enrichment ]  (providers behind one port)
                           → ContactReconciler → ConfidenceScorer
                           → ContactResult (contact + score + status + provenance)
```

- **Providers** implement one `ContactProvider` port; the mocks are just one
  adapter. Each is **failure-isolated** — one source erroring is a "not found"
  from that source, never a dead row.
- **Per-row + stateless** — a 1k-row batch is a loop today; one idempotent
  queued job per row at scale (the design already supports it).
- **Reconciler** decides the contact; **Scorer** is a pure, auditable function.

## Confidence scoring (explainable by design)

No opaque model — every point is traceable to a reason string (see `reason` /
`score_reasons` in the output).

| Signal | Effect |
|---|---|
| Base: registry (decision-maker) / listing-name / listing-phone / enrichment | 50 / 35 / 25 / `confidence×0.5` |
| Name agrees across ≥2 then ≥3 independent sources | +25, +15 |
| Same phone from two sources | +15 |
| Email domain aligns with company name | +10 |
| Registered agent (not a decision-maker) | −10 |
| Role-based email (`info@`/`office@`) with no named person | −10 |
| Single-token identity (e.g. just "Jeff") | −5 |
| **Sources disagree on the person** | capped at 45, routed to a human — never auto-picked |

**Threshold = 70** (per CLARIFICATIONS). Below it, on a conflict, or with no
data → `contact_email_or_phone = ""` and `needs_human_review = true`.

## Results on the sample (precision over recall, as asked)

`8 verified · 9 needs_review · 1 conflicting · 12 unverified` — a high review
rate on genuinely hard rows is the **correct** outcome here.

Two rows are deliberate judgment calls worth flagging:
- **Sunbelt Roofing (30, review):** two sources agree on a *phone*, but there's
  no named decision-maker and only a role-based `office@` — I hold it for review
  rather than action a generic line in a collections context.
- **Lakeside Auto Glass (55, review):** two weak sources agree on the first name
  "Jeff" only — not enough to confirm the AP decision-maker.

A reviewer might score these differently; the point is the *reasoning* is
explicit and the value isn't faked.

## How the build followed the plan and adapted to CLARIFICATIONS

| PLAN.md default | CLARIFICATIONS | What changed in the build |
|---|---|---|
| "conservative threshold" | 70 | `ContactReconciler::THRESHOLD = 70` |
| role-based over personal when unsure | AP → owner → CFO → office manager | contact-pick order |
| precision vs recall (my Q1) | precision over recall | contact value suppressed below 70, not emitted as a guess |
| channel (my Q2) — unanswered | silent | stated assumption: named-DM email first, corroborated phone fallback |
| privacy | US B2B only, business contact only | only corporate-domain emails / business lines emitted; every value carries a `source_url` |

## Privacy / compliance

Business contacts only — no personal/home data, no breach/broker sources, no
fabricated contacts. Every emitted value is traceable to a `source_url`
(supports opt-out/suppression and audit). Conflicts go to a human, not a guess.

## Notes on your conventions (CLAUDE.md)

- **Module structure** honoured (`app/Modules/ContactFinder`); tests in
  `tests/Unit` in your existing pure-PHPUnit style.
- **Statuses use neutral words** (`verified` / `needs_review` / `unverified` /
  `conflicting`) per "no negative words in user-facing text".
- **History is linear on `main`, no PR.** Your PR/branch/`[TICKET-ID]`
  conventions read as the legacy ticket challenge, and the auto-review Action
  fires on `pull_request` (and fails on a fork without secrets). In your real
  flow this would land as a `feat/…` branch + PR to `main`; for an async
  take-home I kept history linear so the PLAN-first → slice timeline reads
  cleanly. PLAN.md is committed first, on its own — the timestamps tell the story.
- `php artisan test` (not `--parallel`) — paratest isn't in `composer.json`.
