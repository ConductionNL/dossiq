# Proposal: resident-start-points-have-a-page

Kind: code. Found by the Zuiddrecht demo audit of 13 October 2026 (Woo journey).

## Why

Two gaps stopped a resident's Woo request on `development`:

1. **No way in without a dossier.** `startWooVerzoekAlgemeen` ("Informatie opvragen (Woo-verzoek)")
   is declared with a `summary` and `audiences` so a site can list it as a start tile (REQ-SRPD-006),
   but no resident page offered it. Portaliq's `StartTileCollector` makes a tile only for an action a
   page of the contribution offers through an `action` block, and routes the tile to that page. So
   the action had no tile, no page and no entry: a resident without a dossier could not file a Woo
   request.
2. **Nobody on the staff reads an unassigned Woo request.** Since `case-access-by-handling-team`
   (dossiq#3753) a case is read and updated only by its assignee and its case type's handling teams.
   The shipped `woo-verzoek` case type declared no `handling` block and no `intakeDestinations`, the
   intake sets no assignee, so a new Woo request was readable only by an administrator.

## What changes

- **A page for every start point** (REQ-RSP-001). An action a resident starts from nothing (it
  carries a `summary` and `audiences`, and no `recordField`, `rowField` or `attachTo`) and that no
  resident page offers yet gets a page of its own: the page id is the action id, the label is the
  action label, `menu: false`, and its only block is the action. The overview carries a `cta` tile
  to that page after what the resident still has to do, as the `MijnOverzicht` board draws "Wat kan
  ik regelen", and the form stands on its own page as the `WooVerzoek` board draws it. The rule
  reads the declaration, not a name: today it selects only `startWooVerzoekAlgemeen`.
- **The Woo request case type names its handling team** (REQ-RSP-002): `handling.defaultGroup` is
  `behandelaars`, the provisioned group the shipped flows assign work to. Case type object 1.4.6,
  register 0.20.42.

## What does not change

- The four resident pages and their blocks, apart from the tile on the overview.
- `createBezwaar` and `createKlacht` stay on the case page: each is about one case.
- No group is invented. `dossiq-medewerkers` is not used as the team: every staff member would read
  every Woo request, which is what #3753 narrowed, and every employee's "My case types" picker would
  shrink to the Woo case type alone (`MenuCaseTypesService::offeredCaseTypes()`).

## Related

- portaliq `woo-intake-delivers-to-dossiq` task 3.1 (REQ-WID-003) wants the portal's default Woo
  entry to be this action when dossiq is installed and none when it is not. The tile this change
  makes is that entry; the provisioning task stays portaliq's.
