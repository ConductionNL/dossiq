## ADDED Requirements

### Requirement: Every start point has a page and a tile on the overview (REQ-RSP-001)

An action of the resident contribution that carries a non-empty `label`, a non-empty `summary` and
a non-empty `audiences` list, and declares no `recordField`, `rowField` or `attachTo`, is a start
point. For every start point that no resident page offers through an `action` block, the
contribution SHALL declare a page whose `id` is the action id, whose `label` is the action label,
with the resident group, `menu: false` and exactly one block, `{type: action, action: <id>}`. The
overview SHALL carry, right after its `tasks` block, one `{type: cta, page: <id>, label: <action
label>}` per such page. The rule SHALL read the declaration only; no code names a procedure.

#### Scenario: A resident without a dossier finds the Woo request
@e2e exclude dossiq declares the contribution only; the page renders in portaliq, whose tests/e2e/woo-journey.spec.ts walks it. Asserted in tests/Unit/Portal/ResidentStartPointsTest.php.
- **GIVEN** the citizen or client contribution
- **WHEN** portaliq gathers its start tiles (the first page with an `action` block on the action)
- **THEN** `startWooVerzoekAlgemeen` SHALL have the page `startWooVerzoekAlgemeen`, holding only that action
- **AND** the overview SHALL show a tile "Informatie opvragen (Woo-verzoek)" to that page after "Wat u nog moet doen"

#### Scenario: What is about one case stays on the case page
@e2e exclude A declaration rule over the manifest, asserted in tests/Unit/Portal/ResidentStartPointsTest.php.
- **GIVEN** `createBezwaar`, `createKlacht` (each with `recordField`), `startWooVerzoek` (`attachTo`) and `changePermitPlate` (`rowField`)
- **WHEN** the resident pages are declared
- **THEN** none of them SHALL get a page of its own

#### Scenario: An action a page already offers gets no second page
@e2e exclude A declaration rule over the manifest, asserted in tests/Unit/Portal/ResidentStartPointsTest.php.
- **GIVEN** a start point that already starts the `verzoeken` page
- **WHEN** the resident pages are declared
- **THEN** it SHALL get no page of its own and no tile on the overview
