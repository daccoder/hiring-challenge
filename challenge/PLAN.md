# PLAN.md (committed BEFORE reading CLARIFICATIONS.md or any solution code)

Context I'm designing for: ~1,000 unpaid B2B accounts, only `company_name` + `mailing_address`, goal is to reach the right AP decision-maker to drive payment. This is debt-collection adjacent, so a *wrong* contact is not a neutral miss — it's a compliance and reputation event. That assumption shapes every decision below: I optimize for precision and treat "I cannot verify this" as a first-class, valuable output, not a failure.

## Architecture

A staged, idempotent, per-row pipeline. Each input row is an independent unit of work so the run is resumable and partial failure never poisons the batch.

```
CSV → [Ingest/Normalize] → [Entity resolve] → [Enrich: fan-out to providers] → [Reconcile + score] → [Persist w/ provenance] → [Output / route]
```

- **Ingest/Normalize** — parse CSV, normalize company name (strip `LLC/Inc/Co`, casing, punctuation) and address (USPS-style standardization), produce a stable `entity_key = hash(normalized_name + normalized_address)`.
- **Entity resolve** — one row → one candidate business. Collisions (same key twice, or a name resolving to multiple addresses) are flagged, not silently merged.
- **Enrich** — fan out to N providers behind a single `ContactProvider` port (adapter per source). Each provider returns zero-or-more `ContactCandidate`s, each carrying its raw payload + source id. Providers are independent and failure-isolated (one timeout ≠ dead row).
- **Reconcile + score** — collapse candidates per company, compute `confidence_score`, decide `needs_human_review`.
- **Persist** — store input, every raw candidate, and the chosen contact with full provenance.
- **Output** — emit the required per-row record; route low-confidence/conflicting rows to a review queue.

Implementation: since the repo is Laravel and `CLAUDE.md` asks for module structure, I'd build it as `app/Modules/ContactFinder` — an artisan command driving a `ContactFinderService`, provider adapters implementing one interface, and the mocks wired in as just another adapter. The core logic stays portable; the providers are the only swappable edge.

## Sources & strategy

No single source is sufficient or trustworthy alone, so I combine *kinds* and treat agreement across independent kinds as the real confidence signal:

- **Authoritative / structured** (business registries, state filings, licensing boards) — high trust for *legal entity + sometimes an owner/registered agent name*, but stale and rarely give email/phone. **Fail mode:** outdated officers, registered agent ≠ real decision-maker.
- **Official company web presence** (the business's own site/contact page) — good for role-based emails (`ap@`, `billing@`, `info@`) and a main line. **Fail mode:** no site, generic inbox, or a marketing address nobody reads.
- **Enrichment / directory providers** (the Stage B mocks stand in for these) — can give named people + roles + email/phone. **Fail mode:** confidently wrong, stale, or matched to the wrong same-named business.

Strategy: pull from all available kinds, then **trust corroboration over any single high-confidence-looking hit**. A role-based business email that the company's own site confirms beats a "named CFO + personal cell" that only one directory asserts.

## Quality

- **Dedupe** — collapse candidates on `(entity_key)` then on normalized contact value (lowercased email / E.164 phone). The same contact from multiple *independent* sources isn't a duplicate to discard — it's a corroboration signal to record and reward.
- **Confidence score (0–100), explainable heuristic** (no budget for a trained model, and I'd want it auditable anyway). Start from a per-source base trust weight, then:
  - **+** corroboration (same value from ≥2 independent source kinds)
  - **+** role specificity (named AP/CFO/owner > `billing@` > `info@`)
  - **+** domain match (email domain == company's own domain)
  - **−** single-source & unverified
  - **−** free-mail domain when a corporate domain exists, role ambiguity, staleness, name-not-corroborated
  - Plus cheap verification: email syntax + MX, phone format/line-type, domain alignment (mocked in Stage B). Map to 0–100; below the threshold → `needs_human_review = true`.
- **Provenance** — every emitted field is traceable: which provider(s) contributed, the raw payload snapshot, and a fetched-at timestamp, all persisted. The output `source` column reflects the actual contributing provider(s). Nothing is emitted that I can't point back to a source.
- **"Cannot verify" is explicit**, never a blank guess. Per-row status enum: `found` | `low_confidence` | `not_found` | `conflicting`. The last three (and anything below threshold) set `needs_human_review = true` with a short reason. Conflicting sources go to review — I do **not** auto-pick a winner and pretend it's certain.
- **False-positive risk** — the worst outcome is confidently attaching a real person to the *wrong* business and dunning them. Mitigations: require corroboration for a "confident" verdict, prefer role-based over personal when unsure, enforce the domain-match check, keep the threshold conservative, and send conflicts to a human rather than guessing.

## Privacy / compliance

This is collections, so restraint is a feature.

**I will:** use only permissible sources (public registries, the company's own site, licensed/mocked providers); prefer role-based business contacts over personal PII; minimize to only what's needed to reach the AP decision-maker; keep provenance + timestamps so any contact is auditable and erasable on request; default to the lowest-risk channel we already hold (the mailing address).

**I will NOT:** scrape sources whose ToS forbid it, bypass auth/paywalls/rate limits, or use breach/leaked data and sketchy people-search PII brokers; harvest personal data beyond need (no home addresses, SSNs, personal cells unless explicitly sanctioned for a permitted channel); and I will **never** fabricate or "best-guess" a precise contact and present it as verified.

Regulatory note: these are *commercial* accounts, so FDCPA (largely consumer-debt) may not strictly apply — but I'd confirm the governing regime with legal and still respect TCPA (calls/texts), CAN-SPAM (email), DNC, and state collection rules. The allowed *channel* dictates which contact field is even worth finding (see Q2).

## Clarifying questions

1. **What's the downstream cost of a *wrong* contact vs a *missing* one — i.e., where do you want the precision/recall line?**
   - Why it matters: it sets the confidence threshold and whether we emit "best-guess" rows at all. In collections, contacting the wrong person about a debt is a legal/reputational hit, so the asymmetry is steep.
   - Default if unanswered: optimize hard for precision — only surface high-confidence, corroborated contacts; route everything else to human review. Under-deliver before mis-contacting.
   - What changes: the numeric threshold, whether low-confidence rows are returned as suggestions or suppressed, and how aggressively I infer across sources.

2. **What channel will these contacts be actioned on (email / phone / physical mail), and are there channel-specific legal constraints I should encode?**
   - Why it matters: the channel decides which field is worth finding *and* the compliance surface. A personal cell is useless and risky if we can't legally call/text it; CAN-SPAM, TCPA, and DNC differ by channel.
   - Default if unanswered: prioritize a role-based business email plus the mailing address we already hold; treat personal phone numbers as low-priority and review-only.
   - What changes: provider-output priority, which fields I attempt to verify, and what I even bother emitting.

3. **Do we get any feedback signal on whether a contact was correct (payment, reply, bounce, "wrong person" complaint), and is there an internal account/debt ID per row I can join against?**
   - Why it matters: a feedback loop turns confidence from a static heuristic into a calibrated, improving score, and an internal ID lets me reconcile against what we already know instead of inferring identity from name+address alone.
   - Default if unanswered: treat it as a one-shot batch with a transparent heuristic score, persist outcomes anyway so a loop can be added later, and use `(normalized_name + address)` as the entity key.
   - What changes: whether confidence is heuristic vs calibrated, whether I persist an outcomes table from day one, and how I do entity resolution (join on ID vs fuzzy match).
