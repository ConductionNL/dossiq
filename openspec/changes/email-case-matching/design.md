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

### D4 — Strict no-auto-create, revised: opt-in auto-create for the shared mailbox

**Originally:** `linkedTypes: ["mail"]` without `mailObjectTemplate` is the current, deliberate
posture: mail can be attached to cases, mail cannot spawn cases (REQ-ECM-005). Anything else would
let an inbound email create ZGW case records with no intake validation.

**Revised, and the reason is the outcome the original did not price in.** The
matcher this change describes runs over per-user NC Mail accounts, where "no
match, no action" is right: those are personal mailboxes and most of what
arrives has nothing to do with a case. The SHARED functional mailbox is a
different object. It exists because a municipality publishes it as the way to
write in, so a message that matches nothing there is not noise, it is somebody
asking for something. `InboundEmailJob` dropped it with no case, no task, and
no log line, which meant an instance losing every inbound message looked exactly
like an instance receiving none.

So the posture is now split by source:

| source | unmatched mail |
|---|---|
| per-user NC Mail accounts (`CaseEmailMatchJob`) | no action, unchanged (REQ-ECM-005) |
| the shared functional mailbox (`InboundEmailJob`) | becomes a case of the configured fallback type, or nothing when none is configured (REQ-ECM-009) |

What makes the second safe is the same thing the original was protecting:
nothing is created unless an administrator names a case type for it. The
setting is empty by default, so an instance that does not opt in behaves
exactly as it does today, and there is no default a deployment can inherit by
accident. The intake validation concern stands and is answered by the choice
of case type: the fallback type is where an organisation puts its own triage
lifecycle, not a shortcut past one.

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

---

## Implementation notes, added 2026-09-11

Phases 1 to 4 are built. Six things the design did not say, each decided while
building and each with a reason worth keeping.

### The batch runs as the mailbox owner, and that is the tenant boundary

D1 said to lift pipelinq's transport unchanged in shape. Two of its behaviours
could not be lifted, and both are security-relevant rather than cosmetic.

pipelinq runs its lookups from a cron job with no session user. OpenRegister
reads exactly that shape (CLI, no user, not SaaS mode) as a trusted system
context in `MagicOrganizationHandler::resolveOrganizationScope()`, which returns
`SCOPE_ALL`: **every organisation**. And its `linkEmail()` calls cannot succeed
at all, because `EmailLinkService::linkEmail()` throws 401 without a session
user.

Both are answered by running the whole batch inside
`ObjectService::runAs($owner)` (openregister#3332). The owner is the session
subject for the duration, so the case lookup carries the owner's RBAC and their
active organisation, and the leaf records the link as made by the owner. When
OpenRegister exposes no `runAs()`, the run is refused rather than run with
whatever identity the process happens to carry.

### The organisation filter has to be asked for explicitly

`_multitenancy: true` is not enough. `MagicSearchHandler::applyAccessControlFilters()`
SKIPS the organisation filter for a caller whose RBAC already grants the schema,
and `resolveMultitenancyFlag()` skips it for a schema with public read. A case
handler holds exactly that grant. The case lookup therefore passes
`_multitenancy_explicit => true`, which is what OpenRegister's own
`ObjectsController` passes when a caller asks for `_multi=true`.

Measured on the dev instance, register 23 / schema 172, through `runAs()`: a user
whose active organisation is a second organisation gets 0 rows for an identifier
that exists in the Default Organisation, while a non-admin in the Default
Organisation gets 1. On that schema (`authorization: null`) the filter held with
and without the flag; the flag is there for the schemas where it does not.

### Three checks, not one

Recognising a number is never enough. `CaseNumberRecognizer::resolveCases()`
requires all four of: the owner's own scope (above), exact equality on
`identifier` after the search, exactly one visible match (two link to neither,
because picking one is a guess), and `CaseAccessGuard::hasCaseReadAccess()`, the
per-case check dossiq's case endpoints already use.

### Per-user settings live in user preferences, not app config

pipelinq keys per-user blobs as `email_match_settings.<uid>` in `IAppConfig`.
App-config keys are capped at 64 characters (`AppConfig::KEY_MAX_LENGTH`) and a
Nextcloud user id may be 64 characters on its own, so that shape throws for the
long LDAP and SSO ids a municipality actually has. `IUserConfig` has no such
limit, is removed with the user, and lets the job ask for opted-in users
directly (`searchUsersByValueBool`) instead of walking every account.

### The class split is D6's seam, made real

Rather than one 900-line service, the code is three classes:
`CaseNumberRecognizer` (the pattern and identifier resolution, the only
dossiq-specific part), `CaseEmailMatchPreferences` (per-user settings, cursor,
status), and `CaseEmailMatchService` (the transport D6 wants moved into the leaf
later). PHPMD's complexity ceiling forced the question; D6 already had the
answer.

### Scope limits, stated rather than implied

The body scan reads `mail_messages.preview_text` only, which Mail caps at 255
characters. A case number further down a long body is missed in V1, as D3
allows, and the settings screen says so instead of letting a user assume
otherwise. The instance toggle and the pattern get their own admin endpoints
(`/api/settings/email-case-matching/instance`) rather than joining
`EmailTemplateController`'s IMAP keys: the pattern needs validating before it is
stored, and putting that there would have coupled the shared-mailbox controller
to this feature.
