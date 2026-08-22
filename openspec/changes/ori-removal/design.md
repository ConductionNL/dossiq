# Design: ori-removal

Paired with decidesk `ori-adoption` (ships first). The canonical ORI → Popolo
mapping table lives in **decidesk `ori-adoption/design.md`** — this document
does not fork it; the summary below is for the migration runbook only.

## This is a data migration, not a rename

The move renames schemas, properties and enum values. A schema/property/value
rename is a **data migration**: nothing may be "renamed" by editing JSON while
live objects still carry the old shape. All object rewriting is done by
decidesk's `occ decidesk:import-ori` (owned by `ori-adoption`), which is
idempotent, tags every created object with `externalReference: ori:<uuid>`,
supports `--dry-run` and `--rollback`, and never touches the source register.

### Mapping summary (canonical table: decidesk `ori-adoption/design.md`)

| ORI (dossiq, source) | decidesk (target) | Rename class |
| --- | --- | --- |
| `vergadering` | `Meeting` | schema + property renames (`name`→`title`, `startDate`→`scheduledDate`, `type`/`status` value maps incl. `afgelast`→`cancelled`) |
| `agendapunt` | `AgendaItem` | schema + property renames (`subject`→`title`, `omschrijving`→`description`, `sortOrder`→`orderNumber`, `attachments`→`documents`) |
| `raadsdocument` | `DigitalDocument` | schema + property renames (`title`→`name`, `contentType`→`encodingFormat`, `fileSize`→`contentSize` int→string, value map `brief`→`letter`) |
| `stemming` | `Decision` + `VotingRound` | schema split; value maps (`aangenomen`→`adopted`, `verworpen`→`rejected`, `proposal`→`resolution`); `politicalGroupResults`→`partyResults` (aggregate preserved, never decomposed into fabricated per-person votes) |
| `raadslid` | `Person` + `Membership`(s) | schema split; `role` value map (mayor→chair, clerk→secretary, alderman→member of executive board); `actief:false`→`endDate` = cutover date (approximation, logged) |
| `fractie` | `GovernanceBody` (`bodyType: political-group`) | schema rename; `zetels`→`seatCount`, `coalitiepartij`/`oppositiepartij`→`coalitionRole: coalition`/`opposition` |

## Migration runbook (per instance)

1. **Preconditions.** decidesk release containing `ori-adoption` is installed
   and enabled; OpenRegister up; dossiq still at the pre-removal version (the
   `ori` register intact).
2. **Dry run.** `occ decidesk:import-ori --source-register=ori --dry-run
   --strict`. Record the per-schema source counts. Fix any reported dangling
   references in the source data first — the importer reports them rather than
   silently dropping them.
3. **Live run.** Same command without `--dry-run`. Then **verify parity by
   measuring, not by reading the command's own report**: count objects per
   source schema in the `ori` register and count `ori:*`-tagged objects per
   target schema in decidesk via the OpenRegister API (use `_limit`/faceting;
   remember a bare `limit=` is a property filter). A `stemming` legitimately
   yields two target objects (Decision + VotingRound); the parity formula in
   the verification task accounts for that.
4. **Verify surfaces.** decidesk feeds `/apps/decidesk/feed/ori/*.rss` return
   the migrated content anonymously; spot-check one meeting, one vote, one
   political group in the decidesk UI.
5. **Upgrade dossiq** to the version carrying this change. The
   `RetireOriRegister` repair step now finds the register's objects fully
   `ori:*`-migrated (it asks decidesk / checks tags) and retires the register;
   otherwise it warns and leaves everything in place (fail-safe: removal never
   outruns migration).

### Rollback

- **Before dossiq upgrade:** `occ decidesk:import-ori --rollback` deletes
  exactly the `ori:*`-tagged decidesk objects; the untouched `ori` register is
  still the complete record. No dossiq data was modified.
- **After dossiq upgrade but register retirement was skipped** (guard
  triggered): nothing was deleted; downgrade dossiq or re-run migration.
- **After register retirement:** the retirement step exports the register
  (schemas + objects) to a timestamped JSON backup in the app data directory
  before deletion; restoring = re-import of that file. The decidesk copy is
  authoritative from this point.

## Repair-step design: `RetireOriRegister`

Replaces `RegisterOriRegister` in `appinfo/info.xml` (`<post-migration>`; the
`<install>` entry is dropped entirely — fresh installs never get an ORI
register). Behaviour:

- OpenRegister absent → no-op (info line), same posture as the old step.
- `ori` register absent → no-op (already retired / never provisioned).
- Register present and every object is either gone or verified migrated
  (matching `ori:*` tags in decidesk) → export backup JSON → delete register +
  schemas.
- Register present with unmigrated objects → **warn and keep** ("run
  `occ decidesk:import-ori` first"); the upgrade itself still succeeds.
  Idempotent: safe to run on every upgrade until it can retire.

## Cross-app link: case ↔ decidesk Meeting

dossiq's vergadering-backed cases are dossiq `case` objects (register/schema
from dossiq config) and are **kept** — `VergaderingCaseService`,
`VergaderingDeadlineJob` and their statuses continue to work on case data
alone (the service already reads only the case register; its doc comment's
"created in the ORI register" claim describes an ingest bridge that was never
built and is corrected as part of this change).

What changes is the *reference to the meeting record*:

- The dossiq `case` schema gains an optional `meetingRef` property (string,
  decidesk `Meeting` uuid) in `dossiq_register.json` — additive.
- `VergaderingDetailView` (route `/besluitvorming/vergaderingen/:id`,
  manifest `src/manifest.d/50-besluitvorming.json`) stops rendering ORI
  agenda/vote data of its own and instead shows the case-side data plus an
  "Open meeting in decidesk" deep link
  (`/apps/decidesk/#/meetings/<meetingRef>`) and/or decidesk's registered
  integration leaf, exactly the ADR-019 consume pattern proven by
  `consume-decidesk-besluitvorming-leaf`. Route stays registered (ADR-044:
  deep links and e2e must not break).
- decidesk absent or `meetingRef` empty → quiet unavailable notice, never a
  broken panel.

## Removal inventory (verified against the working tree, not memory)

| Surface | File(s) | Action |
| --- | --- | --- |
| Register definition | `lib/Settings/ori_register.json` | delete |
| Provisioning repair | `lib/Repair/RegisterOriRegister.php`; `appinfo/info.xml` `<install>` + `<post-migration>` entries | delete; replace post-migration entry with `RetireOriRegister` |
| Data-quality cron | `lib/Cron/OriDataQualityCheck.php`; `appinfo/info.xml` `<job>` entry | delete |
| Public feeds | `lib/Controller/RaadsinformatieFeedController.php`; `appinfo/routes.php` lines for `/feed/ori/vergaderingen.rss`, `/feed/ori/agendapunten.rss`, `/feed/ori/documenten.rss` | delete (decidesk serves `/feed/ori/meetings.rss`, `/feed/ori/agenda-items.rss`, `/feed/ori/documents.rss`) |
| Meeting detail page | `src/views/besluitvorming/VergaderingDetailView.vue` (+ `50-besluitvorming.json` manifest entry) | repoint to case data + decidesk link/leaf (kept, modified) |
| Case service docs | `lib/Service/VergaderingCaseService.php` header comment | correct (no ORI ingest exists) |
| Tests | unit/e2e touching the deleted controller/cron/repair | delete or repoint |

Grep gate (task-enforced): after removal, `grep -rn "ori_register\|register: 'ori'\|RegisterOriRegister\|OriDataQualityCheck\|RaadsinformatieFeed" lib/ src/ appinfo/` returns only the new `RetireOriRegister` (which must reference the `ori` slug to retire it) — count the files, since `[OK] no matches` is also what a zero-file grep prints.
