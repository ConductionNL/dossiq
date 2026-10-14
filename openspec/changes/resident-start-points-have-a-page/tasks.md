# Tasks: resident-start-points-have-a-page

Kind: code. Board canon: `MijnOverzicht` ("Wat kan ik regelen" between the open questions and the
running cases) and `WooVerzoek` (the request form on a page of its own), design-system
`screens-src/zuiddrecht/`.

## 1. Start points

- [x] 1.1 `lib/Portal/PortalPages.php`: `forResident()` adds a page per start point no page offers
  (`startPages()`, `isStartPoint()`, `offeredActions()`), and the overview a `cta` page tile after
  the tasks block (REQ-RSP-001).
  - `tests/Unit/Portal/ResidentStartPointsTest.php`
  - `tests/Unit/Portal/PortalContributionProviderTest.php`, `tests/Unit/Portal/PortalCasePageTest.php`
    updated to the fifth page and the tile.

## 2. Handling team

- [x] 2.1 `lib/Settings/register.d/81-woo-verzoek.json`: `handling.defaultGroup: behandelaars`, case
  type object 1.4.6; `lib/Settings/dossiq_register.json` 0.20.42 (REQ-RSP-002).
  - `tests/Unit/Settings/WooCaseTypeHandlingTeamTest.php`

## 3. Live

- [ ] 3.1 After merge, on an upgraded instance: a non-admin account in `behandelaars` lists an
  unassigned Woo request; a resident without a dossier opens the tile and sends a request.
