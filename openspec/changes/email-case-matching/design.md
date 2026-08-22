# Design — email-case-matching

## Context

Three facts ground this change:

1. **The case number is `identifier`, format `YYYY-NNNN`.** The `case` schema
   (`lib/Settings/dossiq_register.json`) has no `zaaknummer`/`caseNumber` property and no `pattern`;
   `identifier` is read-only and materialised by OpenRegister from
   `x-openregister-calculations.identifier = concat(year(startDate), "-", sequence(scope: yearly, pad: 4))`
   — e.g. `2026-0042`. There is **no `ZAAK-` prefix in the data.**
2. **Dossiq's existing inbound path doesn't cover this.** `InboundEmailJob` polls one shared
   functional IMAP mailbox with its own IMAP client and recognises only the bracketed subject tag
   `/\[([A-Z]+-\d{4}-\d{4,6})\]/` — which the schema-generated identifier can never produce
   (prefixed, bracket-required, while `CaseEmailRepository` then looks the raw prefixed string up
   against `identifier`). Personal NC Mail mailboxes, body text, and untagged mail are all invisible.
3. **The mechanism to copy exists and is proven.** Pipelinq's `EmailMatchService` +
   `EmailMatchJob` (300 s) read `mail_messages`/`mail_mailboxes`/`mail_recipients`, link through the
   generic `OCA\OpenRegister\Service\EmailLinkService` (`linkEmail`/`getLinkedEmails`), keep a
   per-user id cursor, store per-user settings/status blobs in `IAppConfig`, and fail closed on an
   empty `register` app-config. Its matching basis is correspondent **addresses only** — it never
   scans subject or body text, so text recognition is genuinely new capability, not a copy.

## Goals / Non-Goals

**Goals:** automatic, idempotent, opt-in case attachment of NC Mail messages by case-number
recognition; recognizer configurable and correct against the real identifier format; strict
no-auto-create; fail-closed guards mirroring pipelinq.

**Non-Goals:** replacing `InboundEmailJob` or its archival flow; `mailObjectTemplate`/auto-create;
moving the shared matcher core into the leaf (recommended direction, D6, separate change);
scanning attachments; retroactive full-mailbox scans by default (cursor starts at the current
high-water mark; an admin occ command may backfill explicitly).

## Decisions

### D1 — Copy pipelinq's transport skeleton verbatim, replace only the recognizer

Message iteration (`mail_messages ⋈ mail_mailboxes` on account, `id > cursor`, ASC, batch cap),
per-user settings/status blobs, DI-container resolution of the leaf with `method_exists` guards
(openregister and mail stay optional runtime deps), leaf-side idempotency plus a caller-side
`getLinkedEmails` pre-check, and the empty-register refusal are all lifted from
`pipelinq/lib/Service/EmailMatchService.php` unchanged in shape. Divergence is confined to one seam:
`extractAddresses → matchEmailToEntities` becomes `extractCaseNumberCandidates → resolveCasesByIdentifier`.
Keeping the seam identical is what makes D6 (later extraction into the leaf) cheap.

### D2 — The recognizer must match the data, not the legacy tag

Default pattern (app-config `email_case_matching_pattern`):

```
/(?<![\w-])(?:\[)?(?:[A-Z]{2,10}-)?((?:19|20)\d{2}-\d{4,6})(?:\])?(?![\w-])/u
```

- Capture group 1 is the bare identifier (`2026-0042`) — exactly what the `identifier` property
  holds, so resolution is a plain equality filter.
- Optional uppercase prefix and optional brackets accept the legacy `[ZAAK-2026-000142]` tag style
  as *decoration*, fixing at the recognition layer the mismatch `InboundEmailJob` has at line 53
  (its pattern requires the prefix+brackets and then resolves the **prefixed** string against
  `identifier`, which can never match — flagged as a separate defect, not fixed here).
- Boundary guards `(?<![\w-]) … (?![\w-])` stop `12026-00421` and phone-number fragments from
  matching.
- Validation on read: pattern must compile and contain ≥1 capture group, else the run is refused
  (REQ-ECM-001). A recognizer that silently matches nothing is the classic silent failure; refusing
  loudly is the only honest behaviour.

False-positive risk is real (`YYYY-NNNN` is a common shape — invoice numbers, other systems' ids).
Two mitigations keep precision acceptable: candidates only ever *link* (no create, no mutation of the
case), and a candidate must resolve to an existing case identifier before anything happens. The
year prefix restriction (`19|20`) removes most numeric noise. Instances wanting stricter matching
set a stricter pattern.

### D3 — Subject first, body as fallback, and honesty about "body"

Subject comes free from `mail_messages.subject`. The body is **not** in the tables pipelinq reads:
NC Mail caches a bounded plaintext preview (`preview_text`) and otherwise fetches bodies from IMAP.
Scan order is therefore: subject → (only when the subject resolved nothing) the body text available
from the Mail store — the cached preview when present, the full body via Mail's message retrieval
where that is cheaply available. The spec's wording ("body text available from the Nextcloud Mail
store") is deliberate: a case number deep in a long body may fall outside the cached preview, and
this change does not promise IMAP round-trips per message. This limitation is accepted for V1 and
documented in the settings UI help text; the shared-core extraction (D6) is the right place to give
body retrieval a proper leaf-side home.

### D4 — Strict no-auto-create

`linkedTypes: ["mail"]` without `mailObjectTemplate` is the current, deliberate posture: mail can be
attached to cases, mail cannot spawn cases. This change preserves it exactly (REQ-ECM-005). Anything
else would let an inbound email create ZGW case records with no intake validation.

### D5 — Coexistence with InboundEmailJob

Two paths, two jobs, no shared state, no conflict:

| | `InboundEmailJob` (existing) | `CaseEmailMatchJob` (this change) |
|---|---|---|
| Source | one shared functional mailbox, direct IMAP | per-user NC Mail accounts, Mail DB tables |
| Recognition | bracketed subject tag only | configurable pattern, subject then body |
| Effect | link + archival (`EmailArchivalService`) | leaf link only |
| Toggle | IMAP config presence | instance + per-user toggles |

Both end at the same place (email attached to the case), and leaf-side idempotency keys on the
message make double-processing harmless. Convergence criterion for a later change: when the shared
mailbox is migrated to an NC Mail account, `InboundEmailJob`'s recognition collapses into this
matcher and only its archival trigger remains.

### D6 — The generic matcher belongs in the email leaf (recommendation)

After this change, two apps will carry near-identical 1000-line matcher transports differing only in
one method. The honest architectural direction (ADR-012, ADR-022): lift the core — message iteration,
cursor, per-user settings/status, idempotent `linkEmail` — into the OpenRegister email leaf as a
generic matching engine that accepts app-registered **recognizers**; pipelinq contributes the
correspondent-address recognizer (+ public-domain guard), dossiq contributes the case-number
recognizer (pattern + identifier resolution — the only genuinely dossiq-specific ~100 lines of this
change). That is a separate openregister change; this change keeps the seam clean (D1) so the
extraction is mechanical. Until it lands, duplication is accepted and recorded here rather than
hidden.

## Fail-closed invariants

- Empty `register` app-config, or `case` schema unresolvable → refuse, log once, zero OR calls
  (never cast `''` to int; pipelinq's `registerSlug()` guard verbatim).
- Invalid recognizer pattern → refuse the run, log error.
- Leaf or Mail app absent → no-op (container resolution returns null; `method_exists` guards).
- Instance toggle `no` → nothing runs regardless of user settings.

## Risks / Trade-offs

- **False positives** (D2): mitigated by year-anchored pattern, existing-identifier resolution, and
  link-only effect; residual risk is an irrelevant-but-harmless link a handler can remove in the
  Mail sidebar.
- **Preview-bounded body scan** (D3): a case number beyond the cached preview is missed in V1;
  documented, revisited with D6.
- **Privacy**: scanning personal mailboxes is exactly why both toggles default to off and the user
  toggle is per-user; the matcher stores no message content, only link rows in the leaf and numeric
  counters in status.
- **Duplication with pipelinq** (D6): accepted, bounded, and pointed at its resolution.

## Migration / Rollout

- Purely additive: new service, new job (registered in `info.xml`), new app-config keys, new
  per-user settings section. No schema change, no data migration.
- Cursor initialises at the account's current max message id — no historic scan on enable; an
  explicit occ command may be added later for backfill.
- Rollback: disable the toggles or remove the job registration; existing leaf links remain (they are
  ordinary email-leaf links, indistinguishable from manual ones — by design).
