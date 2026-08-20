# case-search-via-or-unified-search

Procest participates in the OpenRegister fleet-wide Nextcloud unified-search provider by flagging its user-facing schemas searchable and claiming deep links for them. Procest does NOT register its own `OCP\Search\IProvider` (ADR-022: apps consume OR abstractions).

## ADDED Requirements

### Requirement: Searchable schema opt-in

The procest register definition SHALL flag exactly these schemas `searchable: true`: `case`, `task`, `bezwaar`, `voorstel`, `beroep`. Schemas without a standalone detail route SHALL NOT be flagged.

#### Scenario: Case findable in unified search

- **GIVEN** a case titled "Kapvergunning Dorpsstraat 12" exists and the user may read it
- **WHEN** the user types "Kapvergunning" in the Nextcloud unified search bar
- **THEN** the case appears as a result under the OpenRegister objects provider
- **AND** activating it navigates to `/apps/procest/cases/{uuid}`

@e2e exclude Requires the OR ObjectsProvider pipeline and NC search UI; provider behaviour is covered by openregister's own unified-search-provider e2e suite — procest only supplies declarative flags, asserted by unit test on the register JSON.

#### Scenario: Non-flagged schema absent from search

- **GIVEN** a `decisionType` config object exists
- **WHEN** a user searches for its title
- **THEN** it does not appear in unified search results (schema not flagged searchable)

@e2e exclude Same rationale — declarative flag asserted by unit test on the register JSON.

#### Scenario: RBAC-restricted case hidden

- **GIVEN** a confidential case the user has no OR RBAC read access to
- **WHEN** the user searches for its title
- **THEN** the case does not appear (OR provider delegates to `searchObjectsPaginated(_rbac: true)`)

@e2e exclude Enforced and tested in openregister (provider security contract); procest adds no code path.

### Requirement: Deep links for searchable schemas

Every schema flagged `searchable: true` SHALL have a `deepLinks` entry in `src/manifest.json` mapping `(procest, <schemaSlug>)` to its detail route, so the OR provider can render result URLs and display names.

#### Scenario: Deep links cover all searchable schemas

- **GIVEN** the manifest and register definition at HEAD
- **WHEN** the searchable schema slugs are compared against `deepLinks[].schemaSlug`
- **THEN** `case`, `task`, `bezwaar`, `voorstel`, `beroep` each have an entry with url templates `/apps/procest/cases/{uuid}`, `/apps/procest/tasks/{uuid}`, `/apps/procest/bezwaren/{uuid}`, `/apps/procest/voorstellen/{uuid}`, `/apps/procest/beroepen/{uuid}`

@e2e exclude Declarative cross-file consistency asserted by unit test (vitest) on the two JSON files.

### Requirement: Version-gated re-import

The change SHALL bump both the register `info.version` and the app version in `appinfo/info.xml`, because the OR register repair step only re-imports schema definitions when the version advances.

#### Scenario: Upgrade re-imports searchable flags

- **GIVEN** an instance running the previous procest version with the register already imported
- **WHEN** the app upgrades and the repair step runs
- **THEN** the register import re-runs (version gate passes) and the five schemas carry `searchable: true` in OR

@e2e exclude Repair-step import mechanics are owned and tested by openregister; the version bump is asserted by unit test comparing info.xml and register JSON versions advanced together.
