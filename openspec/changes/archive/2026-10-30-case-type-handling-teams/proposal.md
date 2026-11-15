---
kind: code
depends_on: [case-types-in-my-menu]
---

# Proposal: case-type-handling-teams

Ruben's decision of 2026-10-09: the picker "Case types in my menu" (board
`DqPersoonlijkeInstellingen`, design-system#152) offers the case types the
user's team handles cases in, as the board's hint says ("Je ziet de zaaktypen
waar je team zaken in behandelt."). `case-types-in-my-menu` (dossiq#3530)
shipped the picker with "the case types you have access to", because dossiq
had no link between a team and the case types it handles. This change builds
that link and moves the picker onto it.

## Why

A case type already says which team a new case goes to: `handling.defaultGroup`,
a Nextcloud group (REQ-SEED-01 of `starter-content-and-templates`). That is one
team. A case type is often handled by more than one: a melding openbare ruimte
lands with Buitenruimte and is also worked by Toezicht en handhaving. The picker
needs the whole set, and an administrator needs one place to say it.

## What changes

- The case type's `handling` block gains `teams`: the Nextcloud groups that
  handle cases of this type, besides the default group. The default group
  always counts as a handling team, so every case type that names one is
  linked on the day this ships without a migration.
- `CaseTypeHandling::teams()` reads the set (default group first, then
  `teams`, no duplicates, no empty ids). It is the only reader, and `teams`
  joins `READ_SWITCHES`, so publishing still refuses a switch nothing reads.
- The case type form (Settings, Case types, General) gains a field
  "Handling teams", a multi-select of Nextcloud groups, the way the board
  `DqZaaktype` draws it in the case type's details card
  (design-system#177).
- The picker rule (amended in `case-types-in-my-menu`, REQ-CTN-004): offer
  the current case types the user may see whose handling teams include a
  group the user is in. A user in no handling team is offered every case type
  they may see, as before.
- The hint under the picker goes back to the board text, in nl and en.

## Decisions

- **The link is the existing handling block, not a new relation.** A team in
  dossiq's handling code is a Nextcloud group: `handling.defaultGroup`, the
  handover's `TeamDirectory`, and the board `DqAfdelingen` ("uit
  Nextcloud-groepen"). Membership is Nextcloud's own (`IGroupManager`), so
  dossiq keeps no second member list. Why not the other two candidates is in
  design.md.
- **Administered on the case type.** The handling block lives on the case type
  and is versioned with it; a team page would have to write into every case
  type it names. The board change adds the row to `DqZaaktype`.
- **No team: fall back to access.** A user in none of the groups any case type
  names (a new employee, an administrator, an instance that has not filled the
  field in) still gets a useful picker rather than an empty one.

## Impact

- `lib/Settings/register.d/48-starter-content.json` (`handling.teams`)
- `lib/Service/CaseType/CaseTypeHandling.php`, `lib/Service/MenuCaseTypesService.php`,
  `lib/Controller/MenuCaseTypesController.php`, `lib/Controller/ManifestController.php`
- `src/views/settings/tabs/GeneralTab.vue`, `src/services/nextcloudGroupsApi.js` (new), `l10n/`
- design-system#177: board `DqZaaktype` gains "Behandelende teams" in its details card.
- Spec `case-types`: REQ-CT-44 added. Change `case-types-in-my-menu`:
  REQ-CTN-004 amended.
