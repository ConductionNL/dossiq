# case-search-via-or-unified-search Specification

## Purpose
TBD - created by archiving change case-search-via-or-unified-search. Update Purpose after archive.

## Requirements

### Requirement: Searchable schema opt-in

A schema in the dossiq register is searchable unless it opts out.
OpenRegister's `Schema` entity declares `protected bool $searchable = true`,
so a definition that carries no `searchable` key imports as searchable and
`ObjectsProvider` includes it. Measured on the running instance 2026-09-08:
`brpPerson` and `kvkCompany` both report `searchable: true` with no flag
anywhere in `lib/Settings/register.d/25-brp-kvk.json`, and a search for a
seeded person returned that person.

The dossiq register definition therefore SHALL NOT be read as the allow-list
for unified search. It flags `case`, `objectionProceeding` and `beroep`
explicitly, which is a statement of intent and changes nothing at runtime; a
schema SHALL only be flagged `searchable: false` when it should be kept OUT of
search, and a schema SHALL NOT be flagged `true` merely to document that it is
searchable, because the flag only lands on an instance behind a schema version
bump and a re-import.

🔴 A task is not on that list any more, and that is a gap rather than a
tidy-up. `remove-casetask` deleted the `caseTask` schema, so there is no
object left to index and no `deepLinks` entry left to resolve. Engine tasks
have no unified-search provider at all today: the engine keeps its tasks in
its own table, outside the object index this opt-in feeds, and no `deepLinks`
shape addresses them. dossiq cannot close this, because the shape is
OpenRegister's to define. The route `/tasks/:id` is unchanged, so the task
page is ready the day a provider exists, and
`tests/vitest/searchableSchemas.spec.js` pins the list at three. The gap is
recorded in `openspec/changes/remove-casetask/tasks.md`.

What decides whether a result is USEFUL is the deep link, not the flag. See
the next requirement.

#### Scenario: Case findable in unified search

- **GIVEN** a case titled "Kapvergunning Dorpsstraat 12" exists and the user may read it
- **WHEN** the user types "Kapvergunning" in the Nextcloud unified search bar
- **THEN** the case appears as a result under the OpenRegister objects provider
- **AND** activating it navigates to `/apps/dossiq/cases/{uuid}`

@e2e exclude Requires the OR ObjectsProvider pipeline and NC search UI; provider behaviour is covered by openregister's own unified-search-provider e2e suite.

#### Scenario: Non-flagged schema absent from search

- **GIVEN** a `decisionType` config object exists
- **WHEN** a user searches for its title
- **THEN** it does not appear in unified search results (schema not flagged searchable)

@e2e exclude Same rationale — declarative flag asserted by unit test on the register JSON.

#### Scenario: A schema flagged out stays out

- **GIVEN** a schema flagged `searchable: false` in the register definition
- **WHEN** a user searches for the title of one of its objects
- **THEN** it does not appear in unified search results

@e2e exclude Declarative opt-out asserted by unit test on the register JSON; the provider's allow-list behaviour is openregister's.

#### Scenario: RBAC-restricted case hidden

- **GIVEN** a confidential case the user has no OR RBAC read access to
- **WHEN** the user searches for its title
- **THEN** the case does not appear (OR provider delegates to `searchObjectsPaginated(_rbac: true)`)

@e2e exclude Enforced and tested in openregister (provider security contract); dossiq adds no code path.

### Requirement: Version-gated re-import

The change SHALL bump both the register `info.version` and the app version in `appinfo/info.xml`, because the OR register repair step only re-imports schema definitions when the version advances.

#### Scenario: Upgrade re-imports searchable flags

- **GIVEN** an instance running the previous dossiq version with the register already imported
- **WHEN** the app upgrades and the repair step runs
- **THEN** the register import re-runs (version gate passes) and the five schemas carry `searchable: true` in OR

@e2e exclude Repair-step import mechanics are owned and tested by openregister; the version bump is asserted by unit test comparing info.xml and register JSON versions advanced together.

### Requirement: Deep links resolve to real pages

Every dossiq schema that has a standalone detail page SHALL have a `deepLinks`
entry in `src/manifest.json` mapping `(dossiq, <schemaSlug>)` to that route,
and the entry's `urlTemplate` SHALL name a route the manifest carries.

This is the requirement that bites. `ObjectSearchResultFormatter` asks
`DeepLinkRegistryService::resolveUrl()` and, when no app has claimed the
`(register, schema)` pair, falls back to `openregister.objects.show` — the
JSON API endpoint. A search result with no deep link therefore looks correct
and opens a JSON document; a search result whose template names a route the
manifest does not carry looks correct and opens a 404. Neither failure is
visible in the search itself.

`brpPerson` maps to `/apps/dossiq/contacts/{uuid}` and `kvkCompany` to
`/apps/dossiq/organisations/{uuid}`, the pages `contacts-domain` added.

#### Scenario: A search hit on a person opens the contact page
@e2e tests/e2e/contacts-domain.spec.ts

- **GIVEN** a seeded `brpPerson` row
- **WHEN** the OpenRegister objects provider is searched for its display name
- **THEN** a result SHALL carry the resource url `/apps/dossiq/contacts/<id>` for that row

#### Scenario: A search hit on an organisation opens the organisation page
@e2e tests/e2e/contacts-domain.spec.ts

- **GIVEN** a seeded `kvkCompany` row
- **WHEN** the OpenRegister objects provider is searched for its trade name
- **THEN** a result SHALL carry the resource url `/apps/dossiq/organisations/<id>` for that row

#### Scenario: Every deep link names a real route

- **GIVEN** the manifest at HEAD
- **WHEN** each `deepLinks[].urlTemplate` is compared against `pages[].route`
- **THEN** each SHALL correspond to a page route, with `{uuid}` standing where the route has `:id`

@e2e exclude Declarative cross-file consistency asserted by unit test (vitest) on the manifest.
