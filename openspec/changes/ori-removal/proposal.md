# Proposal: ori-removal

Paired with decidesk change `ori-adoption` (decidesk/openspec/changes/ori-adoption/).
**decidesk's adoption ships FIRST; this change ships SECOND** — it must not
start until decidesk's schema deltas, `occ decidesk:import-ori` importer and
ORI feed adapter are merged and deployed.

## Summary

procest drops the ORI (Open Raadsinformatie) register. Per the product-owner
decision, raadsinformatie moves to decidesk **purely**, modelled on Popolo from
the start (decidesk `ori-adoption`). procest removes
`lib/Settings/ori_register.json` and every surface that provisions, checks or
serves it; existing instances migrate their ORI objects into decidesk's
register via decidesk's importer **before** removal; and procest cases that
reference a council meeting (vergadering) link to the decidesk `Meeting`
cross-app instead of a procest-owned record.

## Why

- **decidesk owns decision-making** (ADR-019/ADR-022; precedent:
  `consume-decidesk-besluitvorming-leaf`, `procest-delegation-via-events`).
  Meetings, agenda items, votes, council members and political groups are
  decidesk's core Popolo domain; procest's ORI register is a parallel,
  Dutch-named duplicate of it.
- **English identifiers are a fleet contract.** The ORI register's schemas
  (`vergadering`, `zetels`, `aangenomen`, …) violate it structurally; the
  statutory Dutch ORI wire shape survives in decidesk's adapter layer only.
- **One register, one record.** Every consumer (feeds, dashboards, catalogs)
  reads one canonical dataset in decidesk instead of choosing between two.

## What changes (procest)

1. **Data migration first (blocking gate).** For every existing instance, the
   objects in the OpenRegister `ori` register are migrated into decidesk by
   running decidesk's `occ decidesk:import-ori` (dry-run, then live, then
   verify counts). This is explicitly a **data migration**: the move renames
   schemas, properties and enum values (`vergadering`→`Meeting`,
   `sortOrder`→`orderNumber`, `aangenomen`→`adopted`, …) — the mapping table,
   dry-run procedure and rollback notes live in decidesk
   `ori-adoption/design.md` and are summarised in this change's `design.md`.
   Removal steps are gated on verified migration parity.

2. **Register removal.** Delete `lib/Settings/ori_register.json` and the
   `RegisterOriRegister` repair step (both `<install>` and `<post-migration>`
   entries in `appinfo/info.xml`). A new one-shot repair step retires the
   now-orphaned `ori` OpenRegister register on upgraded instances — but only
   when it is empty or its objects carry decidesk's `ori:*` import tags
   (i.e. migration verified); otherwise it warns and leaves the data in place.

3. **ORI surface removal.**
   - `lib/Cron/OriDataQualityCheck.php` background job (+ its
     `appinfo/info.xml` registration) — deleted; decidesk owns data quality
     for its own register.
   - `lib/Controller/RaadsinformatieFeedController.php` + the three
     `/feed/ori/*.rss` routes in `appinfo/routes.php` — deleted; the feeds are
     re-served by decidesk (`/apps/decidesk/feed/ori/*.rss`, same wire shape).
   - Any remaining procest reference to the `ori` register slug or its six
     schema slugs — removed (verified by grep gate, not by memory).

4. **Cross-app link: case ↔ decidesk Meeting.** procest keeps its
   vergadering-backed *cases* (`VergaderingCaseService`,
   `VergaderingDeadlineJob`, the `VergaderingDetailView` page) — those are
   procest case records, not ORI objects. Where such a case references a
   council meeting record, it now stores a `meetingRef` (decidesk `Meeting`
   uuid) and the UI deep-links to decidesk (or renders decidesk's integration
   leaf, same pattern as `consume-decidesk-besluitvorming-leaf`), instead of
   pointing at an ORI `vergadering` object. When decidesk is not installed the
   link degrades to a quiet unavailable notice.

## Out of scope / kept

- decidesk-side work (schemas, importer, adapter, seed) — that is
  `ori-adoption` in the decidesk repo.
- procest's vergadering-backed case lifecycle (statuses, deadline job) — kept;
  only the *meeting record* moves.
- The besluitvorming leaf and voorstel→Decision delegation — already shipped by
  `consume-decidesk-besluitvorming-leaf` / `procest-delegation-via-events`.

## Depends on

- decidesk `ori-adoption` merged, released and deployed (importer + feeds
  available).
- OpenRegister available on the instance (as today).
