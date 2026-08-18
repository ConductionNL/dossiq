# ORI removal — procest drops the raadsinformatie registers

Paired with decidesk `ori-adoption` (ships first). procest removes the ORI
register and all surfaces over it; existing data migrates to decidesk before
anything is deleted; cases link to decidesk meetings cross-app.

## ADDED Requirements

### Requirement: REQ-ORIR-001 — Removal MUST be gated on verified migration parity

procest MUST NOT delete the `ori` OpenRegister register, or any object in it,
until its objects are verifiably migrated into decidesk. The move renames
schemas, properties and enum values, so it is a **data migration** executed by
decidesk's `occ decidesk:import-ori` (mapping table, dry-run and rollback per
decidesk `ori-adoption`), never by editing schema JSON in place. Parity MUST be
verified by measuring object counts on both sides (source register counts vs
`ori:*`-tagged target counts, accounting for the 1→2 split of `stemming` into
Decision + VotingRound), not by trusting the import command's own report.

#### Scenario: Removal blocked while unmigrated objects exist

- GIVEN an upgraded procest whose `ori` register still contains objects without matching `ori:*` import tags in decidesk
- WHEN the `RetireOriRegister` repair step runs during `occ upgrade`
- THEN it warns, leaves the register and all its objects intact, and the upgrade still succeeds

#### Scenario: Retirement after verified parity

- GIVEN every object in the `ori` register has a matching `ori:*`-tagged counterpart in decidesk
- WHEN the repair step runs
- THEN it first writes a timestamped JSON export of the register (schemas + objects) to the app data directory, then deletes the register and its six schemas

#### Scenario: Repair step is idempotent and fail-safe

- GIVEN OpenRegister is unavailable, or the `ori` register was already retired
- WHEN the repair step runs
- THEN it is a no-op with an informational message and the upgrade succeeds

### Requirement: REQ-ORIR-002 — procest MUST stop shipping and provisioning the ORI register

`lib/Settings/ori_register.json` and `lib/Repair/RegisterOriRegister.php` MUST
be deleted, together with their `appinfo/info.xml` registrations (both the
`<install>` and `<post-migration>` entries); fresh installs MUST NOT create an
`ori` register. The `<post-migration>` slot is taken by `RetireOriRegister`
(REQ-ORIR-001). The `OriDataQualityCheck` background job and its `<job>`
registration MUST be deleted. After removal, a repository-wide search for
`ori_register`, `register: 'ori'`, `RegisterOriRegister`,
`OriDataQualityCheck` and `RaadsinformatieFeed` MUST match only
`RetireOriRegister` (which necessarily names the `ori` slug it retires) — and
the check MUST count the files searched, since "no matches" over zero files is
indistinguishable from a pass.

#### Scenario: Fresh install has no ORI register

- GIVEN a clean Nextcloud with OpenRegister and the new procest version
- WHEN procest is installed and its repair steps run
- THEN no OpenRegister register with slug `ori` exists and no ORI schemas were created

#### Scenario: No dangling ORI code references

- GIVEN the change is fully applied
- WHEN the repo-wide grep gate from design.md runs over `lib/`, `src/` and `appinfo/` (file count > 0)
- THEN the only matches are inside `RetireOriRegister` and its tests

### Requirement: REQ-ORIR-003 — The public ORI feeds MUST move to decidesk

`lib/Controller/RaadsinformatieFeedController.php` and the routes
`/feed/ori/vergaderingen.rss`, `/feed/ori/agendapunten.rss` and
`/feed/ori/documenten.rss` MUST be removed from procest. Consumers are pointed
at decidesk's replacement feeds (`/apps/decidesk/feed/ori/meetings.rss`,
`/feed/ori/agenda-items.rss`, `/feed/ori/documents.rss` — same ORI wire shape,
served by decidesk's adapter). procest's release notes MUST document the URL
change; procest MUST NOT proxy or redirect (the app may be installed without
decidesk, and a half-alive feed is worse than a documented move).

#### Scenario: procest feed routes are gone

- GIVEN the new procest version
- WHEN an anonymous client requests `/apps/procest/feed/ori/vergaderingen.rss`
- THEN it receives a 404 (route not registered), and the routes file contains no `/feed/ori/` entries

### Requirement: REQ-ORIR-004 — Cases MUST link to decidesk meetings cross-app

procest's vergadering-backed cases (the `case` objects managed by
`VergaderingCaseService` and advanced by `VergaderingDeadlineJob`) are KEPT.
Where such a case references a council meeting record, the procest `case`
schema MUST gain an optional additive `meetingRef` property holding the
decidesk `Meeting` uuid, and the meeting detail surface
(`VergaderingDetailView`, route `/besluitvorming/vergaderingen/:id`) MUST
render case-side data plus a link into decidesk (deep link or decidesk's
ADR-019 integration leaf) instead of reading ORI objects. The route MUST stay
registered (deep links and e2e MUST NOT break). When decidesk is absent or
`meetingRef` is empty the surface MUST degrade to a quiet unavailable notice.

#### Scenario: Case deep-links to its decidesk meeting

- GIVEN a vergadering-backed case whose `meetingRef` holds a decidesk Meeting uuid and decidesk is installed
- WHEN the user opens `/besluitvorming/vergaderingen/:id` for that case
- THEN the page shows the case data and an "Open meeting in decidesk" link targeting that Meeting, and no ORI register read is performed

#### Scenario: Graceful degradation without decidesk

- GIVEN decidesk is not installed
- WHEN the same page renders
- THEN the case data renders normally and the meeting panel shows an unavailable notice instead of an error

#### Scenario: Case lifecycle unaffected by the removal

- GIVEN vergadering-backed cases in status `gepland` with a start date in the past
- WHEN `VergaderingDeadlineJob` runs on the new version
- THEN the cases advance exactly as before (the job reads only case data, no ORI objects)
