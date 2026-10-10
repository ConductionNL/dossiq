## MODIFIED Requirements

### Requirement: REQ-CTN-001 — One Navigation Child Per Case Type Via /api/manifest Delta

dossiq SHALL expose `GET /api/manifest` (authenticated, `#[NoAdminRequired]`) returning a `mergeStrategy: 'delta'` menu payload that adds the case types the current user CHOSE for their menu (REQ-CTN-004), in the user's order, under a caption `MyCaseTypesCaption` labelled "My case types" (order 30). Each entry SHALL carry `id: 'ct-<uuid>'`, `label: <case-type title>`, `route: 'Cases'`, `query: { caseType: <uuid> }` and an integer `order` from 31 upward in the chosen order. The caption SHALL carry `href` to Nextcloud's personal settings section `dossiq`. dossiq SHALL NOT add a menu entry for a case type the user did not choose. The frontend SHALL consume this delta through `useAppManifest('dossiq', builtManifest, { mergeStrategy: 'delta' })` so the navigation updates when the delta lands.

#### Scenario: The chosen case types appear under My case types
@e2e exclude The delta's shape and order are asserted in ManifestControllerTest; the e2e instance seeds no per-user choice and the merge is library behaviour.
- **GIVEN** the user may see the case types "Woo-verzoek", "Bezwaar" and "Klacht"
- **AND** the user chose "Bezwaar" then "Woo-verzoek"
- **WHEN** the app shell fetches `/api/manifest`
- **THEN** the menu SHALL gain the caption "My case types" at order 30
- **AND** two entries `ct-<uuid>` labelled "Bezwaar" (order 31) and "Woo-verzoek" (order 32), in that order
- **AND** each entry SHALL navigate to the `Cases` route with `query.caseType` set to its uuid
- **AND** there SHALL be no entry for "Klacht"

#### Scenario: Case types appear as Cases children
@e2e exclude Asserted in ManifestControllerTest: the delta carries no `CasesGroup` entry.
- **GIVEN** the user may see two case types "Aanvraag" and "Bezwaar" and chose neither
- **WHEN** the app shell fetches `/api/manifest`
- **THEN** the delta SHALL NOT add them as children of `CasesGroup` or of any other group
- **AND** the Cases list SHALL still filter on either through its own case type filter

#### Scenario: Delta never breaks the shell
@e2e exclude The four fallbacks are asserted in ManifestControllerTest.
- **GIVEN** OpenRegister is unavailable, the register or schema is unconfigured, or no case types exist
- **WHEN** the app shell fetches `/api/manifest`
- **THEN** the endpoint SHALL return a no-op delta `{ "menu": [] }`
- **AND** the app navigation SHALL render from the built manifest unchanged
- **AND** an anonymous caller SHALL be refused with 401

## ADDED Requirements

### Requirement: REQ-CTN-004 — Each user chooses the case types in their menu

dossiq SHALL keep, per user, an ordered list of case type uuids in the IConfig user value `menu_case_types` of app `dossiq` (a JSON array). `GET /api/menu-case-types` (`#[NoAdminRequired]`, the current user only) SHALL return `chosen` (the list, `{id, title}`, in order) and `available` (`{id, title}` of every current case type the user may see, sorted by title). `PUT /api/menu-case-types` with `ids` SHALL store the list after keeping only ids of case types the user may see, dropping duplicates and keeping the first 30. On read, ids the user can no longer see SHALL be left out. A user who never saved a choice SHALL get the seeded Woo request case type (`3c0f5a00-0000-4000-a000-00000000a001`) when they may see it, and nothing otherwise. A saved empty list SHALL be kept as empty.

#### Scenario: A new user sees Woo requests
@e2e exclude A default over an unset user value, asserted in MenuCaseTypesServiceTest.
- **GIVEN** a user who never saved a menu choice
- **AND** the user may see the seeded Woo request case type
- **WHEN** the menu is built
- **THEN** "My case types" SHALL hold the Woo request case type only

#### Scenario: A saved order is kept and cleaned
@e2e exclude The cleaning rules are asserted in MenuCaseTypesServiceTest.
- **GIVEN** the user may see case types A and B but not C
- **WHEN** the user saves `[B, C, A, B]`
- **THEN** `menu_case_types` SHALL hold `[B, A]`
- **AND** a following GET SHALL return `chosen` B then A

#### Scenario: Choosing nothing is a choice
@e2e exclude Asserted in MenuCaseTypesServiceTest.
- **GIVEN** the user saved an empty list
- **WHEN** the menu is built
- **THEN** "My case types" SHALL hold no entries and the Woo default SHALL NOT come back

### Requirement: REQ-CTN-005 — Case types in my menu is a section of Nextcloud's personal settings

dossiq SHALL offer the choice as a section "Case types in my menu" inside its existing Nextcloud personal settings section (`ISettings` personal form of the `dossiq` `IIconSection`), not on an app route, as the board `DqPersoonlijkeInstellingen` draws it. The section SHALL list the chosen case types in order, each with a move handle and a remove button, and offer a picker "Add case type" that appends a case type at the bottom. Every change SHALL be saved at once. Reordering SHALL work by dragging the handle and by Arrow up / Arrow down on the focused handle; the handle's accessible name SHALL state the row's position ("Woo requests, move, now 1 of 3") and a polite live region SHALL announce the new position after a move.

#### Scenario: A case handler adds, orders and removes case types
- **GIVEN** a case handler on Personal settings, Dossiq
- **WHEN** they add "Bezwaar" with Add case type
- **THEN** "Bezwaar" SHALL appear at the bottom of the list and in the sidebar under My case types on the next load
- **WHEN** they focus the handle of "Bezwaar" and press Arrow up
- **THEN** "Bezwaar" SHALL move one place up and the move SHALL be announced
- **WHEN** they press the remove button of "Bezwaar"
- **THEN** "Bezwaar" SHALL leave the list and the sidebar

#### Scenario: The pencil beside My case types opens this section
@e2e exclude The pencil is drawn by nextcloud-vue CnAppNav from the caption's `href`; the href itself is asserted in ManifestControllerTest.
- **GIVEN** the sidebar shows My case types
- **WHEN** the user activates the pencil beside the heading
- **THEN** Nextcloud's personal settings SHALL open on the Dossiq section
